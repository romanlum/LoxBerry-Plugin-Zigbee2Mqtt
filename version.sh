# shellcheck shell=bash
# Sourced (not executed) by preupgrade.sh, postroot.sh and
# install-zigbee2mqtt.sh, hence no shebang.
#
# Baseline versions used for a FRESH install only. Once a version has been
# selected from the plugin's web UI ("Version" tab), that choice is persisted
# in $LBPCONFIGDIR/installed-version.json and takes precedence on upgrades -
# see webfrontend/htmlauth/version.php and bin/install-zigbee2mqtt.sh.
ZIGBEE2MQTT_VERSION=2.14.3
NODE_VERSION="v22.17.0"
