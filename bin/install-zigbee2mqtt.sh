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
#     not try to stop/start it - postroot.sh does that once afterwards).
#     postroot.sh runs as root, but drops to the loxberry user (sudo -u
#     loxberry) before calling this script, for the same reason described
#     below.
#   - webfrontend/htmlauth/ajax.php, run directly (no sudo) as the loxberry
#     user, when the user picks a version on the "Version" tab.
#
# Usage: install-zigbee2mqtt.sh <zigbee2mqtt-version> [--from-plugin-install] [<fallback-node-version>]
#
# Runs as the loxberry user. git clone, the Node.js download and the
# npm/pnpm install+build - the actual attack surface, since they run
# network-fetched, third-party build tooling - are all unprivileged. Root is
# only needed for a handful of mechanical filesystem operations that must
# touch /opt itself (root:root, not writable by loxberry: creating/removing
# the top-level /opt/zigbee2mqtt.new and /opt/zigbee2mqtt.old entries, and
# the final swap) and for systemctl. Those go through sudo with fixed
# arguments (see sudoers/sudoers) rather than running the whole script as
# root, so a compromised loxberry account can't turn this script into
# arbitrary root code execution - only into the exact mv/rm/install one-liners
# granted there. systemctl itself needs no plugin-specific rule: LoxBerry's
# own default sudoers already grants loxberry unrestricted "sudo /bin/systemctl".

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
FALLBACK_NODE_VERSION="${3:-}"
if [ "${2:-}" = "--from-plugin-install" ]; then
    FROM_PLUGIN_INSTALL=1
fi

if [ -z "$VERSION" ]; then
    echo "<ERROR> No zigbee2mqtt version given. Usage: $0 <version> [--from-plugin-install] [<fallback-node-version>]"
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

# /var/lock isn't reliably writable by loxberry, so the lock file lives in
# the plugin's own (loxberry-owned) config folder instead.
LOCKFILE=$PCONFIG/install.lock
mkdir -p "$PCONFIG"
if ! exec 9>"$LOCKFILE" 2>/dev/null; then
    # A stale install.lock not writable by loxberry (e.g. restored root-owned
    # by postroot.sh across an upgrade on an older plugin version, see
    # preupgrade.sh) would otherwise wedge every future install/upgrade with
    # a misleading "Permission denied" error. Removing it only needs write
    # permission on $PCONFIG, not ownership of the file itself.
    rm -f "$LOCKFILE"
    if ! exec 9>"$LOCKFILE"; then
        echo "<ERROR> Could not create lock file $LOCKFILE (permission problem)."
        exit 1
    fi
fi
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
    # Written to a temp file and renamed into place: "cat > $STATEFILE"
    # truncates the file before the heredoc (incl. the php call) is
    # expanded, and ajax.php polling in that window would read an empty
    # file and report "idle", stopping the UI's progress display.
    local tmp="$STATEFILE.$$"
    cat > "$tmp" <<EOF
{
    "status": "$status",
    "version": "$VERSION",
    "pid": $$,
    "started": "$STARTED",
    "finished": $finished,
    "message": $(php -r 'echo json_encode($argv[1]);' "$message" 2>/dev/null || echo "\"$message\"")
}
EOF
    mv -f "$tmp" "$STATEFILE"
}

fail() {
    echo "<ERROR> $1"
    write_state "failed" "$1"
    sudo /bin/rm -rf "$NEW"
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

sudo /bin/rm -rf "$NEW"
sudo /bin/rm -rf "$OLD"

# $NEW is created root-owned, then handed to loxberry, so everything
# cloned/built into it below can run unprivileged.
sudo /bin/mkdir -p "$NEW"
sudo /bin/chown loxberry:loxberry "$NEW"

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

# npm-style semver range matching for package.json engines.node (e.g.
# "^22.2.0 || ^24 || <=26.2"), shared by the php -r snippets below. Supports
# ||, space-separated comparators (<, <=, >, >=, =, ^, ~), partial versions,
# x/* wildcards and hyphen ranges. ^0.x semantics are not special-cased -
# irrelevant for Node.js.
NODE_SEMVER_PHP='
function semver_comparators($part) {
    $part = preg_replace("/(<=|>=|<|>|=|\\^|~)\\s+/", "\\1", trim($part));
    if (preg_match("/^(\\S+)\\s+-\\s+(\\S+)$/", $part, $h)) $part = ">=" . $h[1] . " <=" . $h[2];
    return preg_split("/\\s+/", $part, -1, PREG_SPLIT_NO_EMPTY);
}
// Expands one comparator into [op, "x.y.z"] bounds for version_compare, or false if unparseable.
function semver_bounds($c) {
    if ($c === "*" || $c === "x" || $c === "X") return [];
    if (!preg_match("/^(<=|>=|<|>|=|\\^|~)?v?(\\d+)(?:\\.(\\d+|[xX*]))?(?:\\.(\\d+|[xX*]))?(?:-[0-9A-Za-z.-]+)?$/", $c, $m)) return false;
    $op = $m[1];
    $maj = (int)$m[2];
    $min = (isset($m[3]) && ctype_digit($m[3])) ? (int)$m[3] : null;
    $pat = ($min !== null && isset($m[4]) && ctype_digit($m[4])) ? (int)$m[4] : null;
    $lo = $maj . "." . ($min === null ? 0 : $min) . "." . ($pat === null ? 0 : $pat);
    $nextMajor = ($maj + 1) . ".0.0";
    // First version past a partial version ("26.2" -> "26.3.0"), null if fully specified
    $next = $min === null ? $nextMajor : ($pat === null ? $maj . "." . ($min + 1) . ".0" : null);
    switch ($op) {
        case "^":  return [[">=", $lo], ["<", $nextMajor]];
        case "~":  return [[">=", $lo], ["<", $min === null ? $nextMajor : $maj . "." . ($min + 1) . ".0"]];
        case ">=": return [[">=", $lo]];
        case ">":  return $next === null ? [[">", $lo]] : [[">=", $next]];
        case "<":  return [["<", $lo]];
        case "<=": return $next === null ? [["<=", $lo]] : [["<", $next]];
        default:   return $next === null ? [["=", $lo]] : [[">=", $lo], ["<", $next]];
    }
}
function semver_range_valid($range) {
    foreach (explode("||", $range) as $part) {
        foreach (semver_comparators($part) as $c) if (semver_bounds($c) === false) return false;
    }
    return true;
}
function semver_satisfies($v, $range) {
    $v = ltrim($v, "v");
    foreach (explode("||", $range) as $part) {
        $ok = true;
        foreach (semver_comparators($part) as $c) {
            $bounds = semver_bounds($c);
            if ($bounds === false) { $ok = false; break; }
            foreach ($bounds as $b) if (!version_compare($v, $b[1], $b[0])) { $ok = false; break 2; }
        }
        if ($ok) return true;
    }
    return false;
}
'

# node_satisfies <version> <range>
node_satisfies() {
    php -r "$NODE_SEMVER_PHP"' exit(semver_satisfies($argv[1], $argv[2]) ? 0 : 1);' "$1" "$2" 2>/dev/null
}

# Resolves the Node.js version to install: reuses the currently vendored
# Node if it already satisfies zigbee2mqtt's package.json engines.node,
# otherwise downloads the newest (preferably LTS) stable nodejs.org release
# that satisfies it and ships a build for this architecture. Falls back to
# the plugin-pinned baseline if engines.node cannot be read or resolved.
resolve_node_version() {
    local required

    required=$(php -r '$p = json_decode((string)@file_get_contents("package.json"), true); if (is_array($p) && isset($p["engines"]["node"]) && is_string($p["engines"]["node"])) echo trim($p["engines"]["node"]);' 2>/dev/null)

    if [ -z "$required" ]; then
        echo "<WARNING> Could not read required Node.js version from package.json, falling back to plugin default" >&2
        fallback_node_version
        return
    fi

    if ! php -r "$NODE_SEMVER_PHP"' exit(semver_range_valid($argv[1]) ? 0 : 1);' "$required" 2>/dev/null; then
        echo "<WARNING> Could not parse engines.node range \"$required\" from package.json, falling back to plugin default" >&2
        fallback_node_version
        return
    fi

    if [ -x "$CUR/node/bin/node" ]; then
        local cur_version
        cur_version=$("$CUR/node/bin/node" -e 'console.log("v"+process.versions.node)' 2>/dev/null)
        if [ -n "$cur_version" ] && node_satisfies "$cur_version" "$required"; then
            echo "$cur_version"
            return
        fi
    fi

    echo "<INFO> Resolving newest Node.js release satisfying engines.node ($required) from nodejs.org" >&2
    local resolved
    resolved=$(curl -s https://nodejs.org/dist/index.json | php -r "$NODE_SEMVER_PHP"'
        $data = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($data)) { exit; }
        $bestLts = null; $bestAny = null;
        foreach ($data as $rel) {
            $v = ltrim(isset($rel["version"]) ? $rel["version"] : "", "v");
            // Stable releases only - index.json also lists prereleases
            if (!preg_match("/^\\d+\\.\\d+\\.\\d+$/", $v)) continue;
            if (!isset($rel["files"]) || !in_array($argv[2], $rel["files"], true)) continue;
            if (!semver_satisfies($v, $argv[1])) continue;
            if ($bestAny === null || version_compare($v, $bestAny, ">")) $bestAny = $v;
            if (!empty($rel["lts"])) {
                if ($bestLts === null || version_compare($v, $bestLts, ">")) $bestLts = $v;
            }
        }
        $best = $bestLts !== null ? $bestLts : $bestAny;
        if ($best !== null) echo "v$best\n";
    ' "$required" "linux-$NODE_ARCH" 2>/dev/null)

    if [ -z "$resolved" ]; then
        echo "<WARNING> Could not resolve a Node.js version from nodejs.org, falling back to plugin default" >&2
        fallback_node_version
        return
    fi
    echo "$resolved"
}

# Plugin-pinned baseline: prefer the copy persisted at install time
# ($PBIN/version.sh, see postroot.sh), else the value passed down by
# postroot.sh as the 3rd argument, else a last-resort constant.
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
if ! npm install -g "$(node -p "require('./package.json').packageManager")"; then
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

# The systemd unit is only linked once the plugin's own install has
# completed (postroot.sh does that after this script returns), so during a
# fresh plugin install there is nothing to stop/start here yet.
START_STABLE_SECONDS=60

# zigbee2mqtt only exits on startup errors (e.g. adapter not reachable)
# after its own connect timeouts/retries - ~20s for an unresponsive zstack
# adapter, plus z2m's own load time on slow hardware - and systemd then
# restarts it (Restart=always). A single
# is-active check shortly after starting can therefore land on a process
# that is about to fail. Instead require the service to stay active with
# the same main process for START_STABLE_SECONDS: a crash shows up either
# as the unit leaving "active" (auto-restart delay) or as a new MainPID.
wait_for_stable_start() {
    local pid now i
    pid=$(systemctl show -p MainPID --value zigbee2mqtt 2>/dev/null)
    for i in $(seq 1 "$START_STABLE_SECONDS"); do
        sleep 1
        systemctl is-active --quiet zigbee2mqtt || return 1
        now=$(systemctl show -p MainPID --value zigbee2mqtt 2>/dev/null)
        if [ -z "$now" ] || [ "$now" = "0" ] || [ "$now" != "$pid" ]; then
            return 1
        fi
    done
    return 0
}

UNIT_EXISTS=0
if [ -e /etc/systemd/system/zigbee2mqtt.service ]; then
    UNIT_EXISTS=1
fi

if [ "$UNIT_EXISTS" -eq 1 ]; then
    echo "<INFO> Stopping zigbee2mqtt service"
    sudo /bin/systemctl stop zigbee2mqtt
fi

echo "<INFO> Swapping in new version"
if [ -e "$CUR" ]; then
    sudo /bin/mv "$CUR" "$OLD"
fi
sudo /bin/mv "$NEW" "$CUR"

echo "<INFO> Refreshing configuration"
php "$PBIN/update-config.php"

if [ "$UNIT_EXISTS" -eq 1 ]; then
    echo "<INFO> Starting zigbee2mqtt service"
    sudo /bin/systemctl daemon-reload
    sudo /bin/systemctl start zigbee2mqtt
    echo "<INFO> Waiting ${START_STABLE_SECONDS}s for zigbee2mqtt to start up"
    if ! wait_for_stable_start; then
        echo "<ERROR> zigbee2mqtt $VERSION failed to start, rolling back"
        # A failed start may already have been restarted by systemd
        # (Restart=always) and still be running from $CUR - stop it first.
        sudo /bin/systemctl stop zigbee2mqtt
        sudo /bin/rm -rf "$CUR"
        if [ -e "$OLD" ]; then
            sudo /bin/mv "$OLD" "$CUR"
            sudo /bin/systemctl start zigbee2mqtt
        fi
        write_state "failed" "zigbee2mqtt $VERSION failed to start. Rolled back to the previous installation."
        exit 1
    fi
fi

# $VERSIONFILE records the version the *user* picked on the Version tab, and
# preupgrade.sh/postroot.sh let it override the plugin's version.sh baseline
# on every later plugin upgrade. Writing it here during the plugin's own
# install would pin that baseline as if the user had chosen it, so no future
# plugin release could ever ship a newer zigbee2mqtt.
if [ "$FROM_PLUGIN_INSTALL" -eq 0 ]; then
    cat > "$VERSIONFILE" <<EOF
{
    "zigbee2mqttVersion": "$VERSION",
    "nodeVersion": "$NODE_VERSION_TO_INSTALL",
    "installedAt": "$(now)"
}
EOF
fi

sudo /bin/rm -rf "$OLD"

write_state "success" "zigbee2mqtt $VERSION installed successfully."
echo "<OK> zigbee2mqtt $VERSION installed successfully."

exit 0
