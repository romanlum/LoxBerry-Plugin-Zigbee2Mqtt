#!/bin/bash
#
# Installs or upgrades zigbee2mqtt to a specific version.
#
# The new version is cloned/built into /opt/zigbee2mqtt.new alongside the
# running installation at /opt/zigbee2mqtt and only swapped in once the build
# succeeds, so a failed upgrade leaves the previously running version intact.
#
# Called from two places:
#   - postroot.sh, during plugin install/upgrade (pass --from-plugin-install;
#     at that point the systemd unit is not linked yet, so this script will
#     not try to stop/start it - postroot.sh does that once afterwards)
#   - webfrontend/htmlauth/ajax.php, dispatched via sudo when the user picks
#     a version on the "Version" tab
#
# Usage: install-zigbee2mqtt.sh <zigbee2mqtt-version> [--from-plugin-install]
#
# Must run as root.

set -u

# /etc/environment is not reliably inherited through sudo, so read it
# directly instead of depending on env propagation from the caller.
if [ -f /etc/environment ]; then
    set -a
    . /etc/environment
    set +a
fi

VERSION="${1:-}"
FROM_PLUGIN_INSTALL=0
if [ "${2:-}" = "--from-plugin-install" ]; then
    FROM_PLUGIN_INSTALL=1
fi

if [ -z "$VERSION" ]; then
    echo "<ERROR> No zigbee2mqtt version given. Usage: $0 <version> [--from-plugin-install]"
    exit 1
fi

if [ "$FROM_PLUGIN_INSTALL" -eq 1 ]; then
    echo "<INFO> Running as part of the plugin's own install/upgrade"
fi

# Fixed per plugin.cfg (NAME/FOLDER=zigbee2mqtt, never changed post-release)
PDIR=zigbee2mqtt
PCONFIG=$LBPCONFIG/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR
PBIN=$LBPBIN/$PDIR

CUR=/opt/zigbee2mqtt
NEW=/opt/zigbee2mqtt.new
OLD=/opt/zigbee2mqtt.old

STATEFILE=$PCONFIG/upgrade-state.json
VERSIONFILE=$PCONFIG/installed-version.json

LOCKFILE=/var/lock/zigbee2mqtt-install.lock
exec 9>"$LOCKFILE"
if ! flock -n 9; then
    echo "<ERROR> Another zigbee2mqtt install/upgrade is already running."
    exit 1
fi

now() { date -u +"%Y-%m-%dT%H:%M:%SZ"; }
STARTED=$(now)

# write_state <status> [message]
write_state() {
    local status="$1"
    local message="${2:-}"
    local finished="null"
    if [ "$status" != "running" ]; then
        finished="\"$(now)\""
    fi
    mkdir -p "$PCONFIG"
    cat > "$STATEFILE" <<EOF
{
    "status": "$status",
    "version": "$VERSION",
    "pid": $$,
    "started": "$STARTED",
    "finished": $finished,
    "message": $(php -r 'echo json_encode($argv[1]);' "$message" 2>/dev/null || echo "\"$message\"")
}
EOF
    chown loxberry:loxberry "$STATEFILE" 2>/dev/null || true
}

fail() {
    echo "<ERROR> $1"
    write_state "failed" "$1"
    rm -rf "$NEW"
    exit 1
}

write_state "running"

echo "<INFO> Checking if zigbee2mqtt tag $VERSION exists upstream"
if ! git ls-remote --exit-code https://github.com/Koenkk/zigbee2mqtt.git "refs/tags/$VERSION" >/dev/null; then
    fail "Could not find zigbee2mqtt tag $VERSION on GitHub. Please check the version and your internet connection."
fi

echo "<INFO> Checking free disk space"
AVAILABLE_KB=$(df -Pk /opt | tail -1 | awk '{print $4}')
if [ -z "$AVAILABLE_KB" ] || [ "$AVAILABLE_KB" -lt 1048576 ]; then
    fail "Not enough free disk space in /opt (need at least 1 GB free)."
fi

rm -rf "$NEW" "$OLD"

echo "<INFO> Cloning zigbee2mqtt $VERSION"
if ! git clone --branch "$VERSION" --depth 1 https://github.com/Koenkk/zigbee2mqtt.git "$NEW"; then
    fail "git clone of zigbee2mqtt $VERSION failed."
fi

cd "$NEW" || fail "Could not enter $NEW"

# Map system architecture to a Node.js download architecture
ARCH=$(uname -m)
case $ARCH in
    x86_64)
        NODE_ARCH="x64"
        ;;
    aarch64)
        NODE_ARCH="arm64"
        ;;
    armv7l)
        NODE_ARCH="armv7l"
        ;;
    *)
        fail "Unsupported architecture: $ARCH"
        ;;
esac

# Resolves the Node.js version to install: reuses the currently vendored
# Node if it already satisfies zigbee2mqtt's package.json engines.node,
# otherwise downloads the newest (preferably LTS) nodejs.org release that
# does. Falls back to the plugin-pinned baseline if engines.node cannot be
# read.
resolve_node_version() {
    local required required_major

    required=$(grep -A5 '"engines"' package.json 2>/dev/null | grep '"node"' | head -1 | sed -E 's/.*"node"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')
    if [ -n "$required" ]; then
        required_major=$(echo "$required" | grep -oE '[0-9]+' | head -1)
    fi

    if [ -z "${required_major:-}" ]; then
        echo "<WARNING> Could not read required Node.js version from package.json, falling back to plugin default" >&2
        fallback_node_version
        return
    fi

    if [ -x "$CUR/node/bin/node" ]; then
        local cur_major
        cur_major=$("$CUR/node/bin/node" -e 'console.log(process.versions.node.split(".")[0])' 2>/dev/null)
        if [ -n "$cur_major" ] && [ "$cur_major" -ge "$required_major" ] 2>/dev/null; then
            "$CUR/node/bin/node" -e 'console.log("v"+process.versions.node)'
            return
        fi
    fi

    echo "<INFO> Resolving newest Node.js release satisfying engines.node ($required) from nodejs.org" >&2
    local resolved
    resolved=$(curl -s https://nodejs.org/dist/index.json | php -r '
        $data = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($data)) { exit; }
        $reqMajor = (int)$argv[1];
        $bestLts = null; $bestAny = null;
        foreach ($data as $rel) {
            $v = ltrim($rel["version"], "v");
            $major = (int)explode(".", $v)[0];
            if ($major < $reqMajor) continue;
            if ($bestAny === null || version_compare($v, $bestAny, ">")) $bestAny = $v;
            if (!empty($rel["lts"])) {
                if ($bestLts === null || version_compare($v, $bestLts, ">")) $bestLts = $v;
            }
        }
        $best = $bestLts !== null ? $bestLts : $bestAny;
        if ($best !== null) echo "v$best\n";
    ' "$required_major" 2>/dev/null)

    if [ -z "$resolved" ]; then
        echo "<WARNING> Could not resolve a Node.js version from nodejs.org, falling back to plugin default" >&2
        fallback_node_version
        return
    fi
    echo "$resolved"
}

# Plugin-pinned baseline: prefer the copy persisted at install time
# ($PBIN/version.sh, see postroot.sh), else the value passed down by
# postroot.sh via FALLBACK_NODE_VERSION, else a last-resort constant.
fallback_node_version() {
    if [ -n "${FALLBACK_NODE_VERSION:-}" ]; then
        echo "$FALLBACK_NODE_VERSION"
        return
    fi
    if [ -f "$PBIN/version.sh" ]; then
        (. "$PBIN/version.sh"; echo "$NODE_VERSION")
        return
    fi
    echo "v22.17.0"
}

NODE_VERSION_TO_INSTALL=$(resolve_node_version)
if [ -z "$NODE_VERSION_TO_INSTALL" ]; then
    fail "Could not determine a Node.js version to install."
fi
echo "<INFO> Using Node.js $NODE_VERSION_TO_INSTALL"

if [ -x "$CUR/node/bin/node" ] && [ "$("$CUR/node/bin/node" -e 'console.log("v"+process.versions.node)' 2>/dev/null)" = "$NODE_VERSION_TO_INSTALL" ]; then
    echo "<INFO> Reusing already installed Node.js $NODE_VERSION_TO_INSTALL"
    cp -a "$CUR/node" "$NEW/node"
else
    echo "<INFO> Downloading Node.js $NODE_VERSION_TO_INSTALL"
    if ! wget -q "https://nodejs.org/dist/$NODE_VERSION_TO_INSTALL/node-$NODE_VERSION_TO_INSTALL-linux-$NODE_ARCH.tar.xz"; then
        fail "Download of Node.js $NODE_VERSION_TO_INSTALL failed."
    fi
    tar -xf "node-$NODE_VERSION_TO_INSTALL-linux-$NODE_ARCH.tar.xz"
    mkdir -p "$NEW/node"
    mv "node-$NODE_VERSION_TO_INSTALL-linux-$NODE_ARCH"/* "$NEW/node/"
    rm -rf "node-$NODE_VERSION_TO_INSTALL-linux-$NODE_ARCH" "node-$NODE_VERSION_TO_INSTALL-linux-$NODE_ARCH.tar.xz"
fi

export PATH=$NEW/node/bin:$PATH

echo "<INFO> Installing pnpm"
if ! npm install -g pnpm; then
    fail "npm install -g pnpm failed."
fi
node --version
pnpm --version

echo "<INFO> Installing dependencies"
if ! pnpm i --frozen-lockfile; then
    fail "pnpm install (dependencies) failed."
fi

echo "<INFO> Building zigbee2mqtt"
if ! pnpm run build; then
    fail "pnpm build failed."
fi

echo "<INFO> Remove default data folder"
rm -rf "$NEW/data"

echo "<INFO> Linking log and data folders"
ln -f -s "$PLOG" "$NEW/log"
ln -f -s "$PDATA" "$NEW/data"

chown -R loxberry:loxberry "$NEW"

# The systemd unit is only linked once the plugin's own install has
# completed (postroot.sh does that after this script returns), so during a
# fresh plugin install there is nothing to stop/start here yet.
UNIT_EXISTS=0
if [ -e /etc/systemd/system/zigbee2mqtt.service ]; then
    UNIT_EXISTS=1
fi

if [ "$UNIT_EXISTS" -eq 1 ]; then
    echo "<INFO> Stopping zigbee2mqtt service"
    systemctl stop zigbee2mqtt
fi

echo "<INFO> Swapping in new version"
if [ -e "$CUR" ]; then
    mv "$CUR" "$OLD"
fi
mv "$NEW" "$CUR"

echo "<INFO> Refreshing configuration"
php "$PBIN/update-config.php"
chown loxberry:loxberry "$PDATA"/* -R 2>/dev/null

if [ "$UNIT_EXISTS" -eq 1 ]; then
    echo "<INFO> Starting zigbee2mqtt service"
    systemctl daemon-reload
    systemctl start zigbee2mqtt
    sleep 3
    if ! systemctl is-active --quiet zigbee2mqtt; then
        echo "<ERROR> zigbee2mqtt $VERSION failed to start, rolling back"
        rm -rf "$CUR"
        if [ -e "$OLD" ]; then
            mv "$OLD" "$CUR"
            systemctl start zigbee2mqtt
        fi
        write_state "failed" "zigbee2mqtt $VERSION failed to start. Rolled back to the previous installation."
        exit 1
    fi
fi

cat > "$VERSIONFILE" <<EOF
{
    "zigbee2mqttVersion": "$VERSION",
    "nodeVersion": "$NODE_VERSION_TO_INSTALL",
    "installedAt": "$(now)"
}
EOF
chown loxberry:loxberry "$VERSIONFILE" 2>/dev/null || true

rm -rf "$OLD"

write_state "success" "zigbee2mqtt $VERSION installed successfully."
echo "<OK> zigbee2mqtt $VERSION installed successfully."

exit 0
