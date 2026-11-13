#!/bin/bash
#
# Tests the Node.js version selection in bin/install-zigbee2mqtt.sh: the
# npm-style range matching on package.json engines.node and the choice of
# release from nodejs.org. Runs offline - nodejs.org's index.json is replaced
# by a fixed release list below.
#
# Usage: .github/tests/node-version-test.sh   (needs bash and php)

set -u

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SCRIPT="$ROOT/bin/install-zigbee2mqtt.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Pull NODE_SEMVER_PHP, node_satisfies and resolve_node_version out of the
# install script, up to the line that calls them.
eval "$(sed -n '/^NODE_SEMVER_PHP=/,/^NODE_VERSION_TO_INSTALL=/p' "$SCRIPT" | sed '$d')"
for fn in node_satisfies resolve_node_version; do
    if ! declare -F "$fn" >/dev/null; then
        echo "Could not extract $fn from $SCRIPT - did its structure change?"
        exit 1
    fi
done

# Stubs for what resolve_node_version calls out to
fallback_node_version() { echo "FALLBACK"; }
curl() { cat "$WORK/index.json"; }
CUR="$WORK/cur"

# Shaped like https://nodejs.org/dist/index.json (newest first)
cat > "$WORK/index.json" <<'EOF'
[
  {"version":"v27.0.0-alpha.1","files":["linux-x64","linux-arm64"],"lts":false},
  {"version":"v26.13.1","files":["linux-x64","linux-arm64"],"lts":"Lithium"},
  {"version":"v26.2.0","files":["linux-x64","linux-arm64"],"lts":false},
  {"version":"v25.9.0","files":["linux-x64","linux-arm64"],"lts":false},
  {"version":"v24.22.3","files":["linux-x64","linux-arm64"],"lts":"Krypton"},
  {"version":"v22.23.5","files":["linux-x64","linux-arm64","linux-armv7l"],"lts":"Jod"},
  {"version":"v22.1.0","files":["linux-x64","linux-arm64","linux-armv7l"],"lts":false},
  {"version":"v20.19.0","files":["linux-x64","linux-arm64","linux-armv7l"],"lts":"Iron"}
]
EOF

PASS=0
FAIL=0

# check_satisfies <version> <range> <expected: yes|no>
check_satisfies() {
    local got=no
    node_satisfies "$1" "$2" && got=yes
    if [ "$got" = "$3" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "FAIL: $1 satisfies '$2': got $got, expected $3"
    fi
}

# check_resolve <description> <package.json> <arch> <current node or ""> <expected>
check_resolve() {
    local desc="$1" pkg="$2" arch="$3" cur="$4" expected="$5" got
    rm -rf "$CUR"
    if [ -n "$cur" ]; then
        mkdir -p "$CUR/node/bin"
        printf '#!/bin/sh\necho %s\n' "$cur" > "$CUR/node/bin/node"
        chmod +x "$CUR/node/bin/node"
    fi
    printf '%s' "$pkg" > "$WORK/package.json"
    got=$(cd "$WORK" && NODE_ARCH=$arch resolve_node_version 2>/dev/null)
    if [ "$got" = "$expected" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "FAIL: $desc: got '$got', expected '$expected'"
    fi
}

# Range matching
R='^22.2.0 || ^24 || <=26.2'
check_satisfies v22.2.0 "$R" yes
check_satisfies 22.23.5 "$R" yes
check_satisfies 24.22.3 "$R" yes
check_satisfies 26.2.9 "$R" yes
check_satisfies 26.3.0 "$R" no
check_satisfies 26.13.1 "$R" no
check_satisfies 22.1.9 '^22.2.0 || ^24' no
check_satisfies 23.0.0 '^22.2.0 || ^24' no
check_satisfies 20.0.0 '^20 || ^22' yes
check_satisfies 21.5.0 '^20 || ^22' no
check_satisfies 18.0.0 '>=18' yes
check_satisfies 17.9.0 '>=18' no
check_satisfies 18.5.0 '>18' no
check_satisfies 19.0.0 '>18' yes
check_satisfies 18.17.1 '>=18.17.0 <19' yes
check_satisfies 19.0.0 '>=18.17.0 <19' no
check_satisfies 20.3.1 '~20.3' yes
check_satisfies 20.4.0 '~20.3' no
check_satisfies 20.9.0 '20.x' yes
check_satisfies 21.0.0 '20.x' no
check_satisfies 18.0.0 '18 - 20' yes
check_satisfies 20.9.0 '18 - 20' yes
check_satisfies 21.0.0 '18 - 20' no
check_satisfies 22.0.0 '>= 20' yes
check_satisfies 22.0.0 '*' yes
check_satisfies 22.0.0 'not a range!' no

# Version selection
check_resolve "newest LTS within upper bound" \
    '{"engines":{"node":"^22.2.0 || ^24 || <=26.2"}}' x64 "" v24.22.3
check_resolve "newest LTS when the range allows it" \
    '{"engines":{"node":"^22.2.0 || ^24 || ^26"}}' x64 "" v26.13.1
check_resolve "only releases with a build for the architecture" \
    '{"engines":{"node":"^22.2.0 || ^24 || ^26"}}' armv7l "" v22.23.5
check_resolve "newest stable release when no LTS matches, prereleases skipped" \
    '{"engines":{"node":"^25 || ^27"}}' x64 "" v25.9.0
check_resolve "reuse installed node that satisfies the range" \
    '{"engines":{"node":"^22.2.0 || ^24"}}' x64 v22.17.0 v22.17.0
check_resolve "replace installed node outside the range" \
    '{"engines":{"node":"^22.2.0 || ^24 || <=26.2"}}' x64 v26.13.1 v24.22.3
check_resolve "fallback when nothing matches" \
    '{"engines":{"node":">=30"}}' x64 "" FALLBACK
check_resolve "fallback without engines.node" \
    '{"name":"zigbee2mqtt"}' x64 "" FALLBACK
check_resolve "fallback for an unparseable range" \
    '{"engines":{"node":"not a range!"}}' x64 "" FALLBACK

echo "node version tests: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
