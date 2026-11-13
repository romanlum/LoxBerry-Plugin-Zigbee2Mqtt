#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall but *BEFORE* postupdate). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0  # Zero argument is shell command
PTEMPDIR=$1 # First argument is temp folder during install
PSHNAME=$2  # Second argument is Plugin-Name for scipts etc.
PDIR=$3     # Third argument is Plugin installation folder
PVERSION=$4 # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Loxberry Home is: $LBHOMEDIR"
echo "<INFO> Plugin installation folder is: $PDIR"

#source version file
. ${PTEMPPATH}/version.sh


ISUPGRADE=0
if [ -d "/tmp/${PTEMPDIR}_upgrade" ]; then
    echo "<INFO> Upgrade detected"
    ISUPGRADE=1

    #Replace service config in backup because it is copied back in the next step
    if [ -d "$LBHOMEDIR/config/plugins/$PDIR" ]; then
        cp -f -r $LBHOMEDIR/config/plugins/$PDIR/*.service /tmp/${PTEMPDIR}_upgrade/config/$PDIR/
    fi

    echo "<INFO> Copy back existing config files"
    if [ -d "/tmp/${PTEMPDIR}_upgrade/config/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/
    fi
    if [ -d "/tmp/${PTEMPDIR}_upgrade/data/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/data/$PDIR/* $LBHOMEDIR/data/plugins/$PDIR/
    fi
fi

# This script runs as root, so files restored above are root-owned. Hand
# them back to loxberry: zigbee2mqtt (User=loxberry) writes state.json,
# configuration.yaml etc. at runtime, and install-zigbee2mqtt.sh below runs
# update-config.php as loxberry too. Also repairs installs already left
# root-owned by an earlier upgrade.
chown -R loxberry:loxberry $PCONFIG $PDATA

# Persist version.sh into the plugin's bin folder so install-zigbee2mqtt.sh
# can still find a fallback Node.js version long after this install/upgrade
# has finished (e.g. when the user triggers a UI upgrade later on).
mkdir -p $PBIN
cp -f ${PTEMPPATH}/version.sh $PBIN/version.sh

# Effective target version: a version picked earlier in the web UI
# (installed-version.json, persisted across plugin upgrades) always wins
# over the version.sh baseline shipped with this plugin release.
TARGET_VERSION=$ZIGBEE2MQTT_VERSION
if [ -f "$PCONFIG/installed-version.json" ]; then
    PINNED_VERSION=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); if(isset($d["zigbee2mqttVersion"])) echo $d["zigbee2mqttVersion"];' "$PCONFIG/installed-version.json")
    if [ -n "$PINNED_VERSION" ]; then
        echo "<INFO> Using previously selected zigbee2mqtt version $PINNED_VERSION instead of plugin baseline $ZIGBEE2MQTT_VERSION"
        TARGET_VERSION=$PINNED_VERSION
    fi
fi

echo "<INFO> Installing zigbee2mqtt $TARGET_VERSION"
# This script runs as root, but install-zigbee2mqtt.sh is meant to run as
# loxberry (git clone/Node/pnpm unprivileged, escalating only for the
# specific /opt and systemctl operations it needs via sudo - see
# sudoers/sudoers) - so drop to loxberry here rather than calling it
# directly, which would run the whole thing as root instead.
sudo -u loxberry $PBIN/install-zigbee2mqtt.sh "$TARGET_VERSION" --from-plugin-install "$NODE_VERSION"
retval="$?"
if [ $retval -ne 0 ]; then
    echo "<ERROR> Installation of zigbee2mqtt $TARGET_VERSION failed"
    # install-zigbee2mqtt.sh builds the new version separately and only swaps
    # it in on success, so the previous installation is still intact here.
    # preroot.sh already stopped it - restart it so a failed upgrade doesn't
    # leave zigbee2mqtt down on top of failing.
    if [ -e /etc/systemd/system/zigbee2mqtt.service ]; then
        echo "<INFO> Restarting previous zigbee2mqtt installation"
        systemctl start zigbee2mqtt
    fi
    exit $retval
fi

echo "<INFO> Remove temporary folders"
rm -f -r /tmp/${PTEMPDIR}_upgrade

echo "<INFO> Configuring authenticated Zigbee2MQTT UI proxy"
APACHE_SYSTEM_DIR="$LBHOMEDIR/system/apache2"
APACHE_CONF_ENABLED="$APACHE_SYSTEM_DIR/conf-enabled"
APACHE_MODS_AVAILABLE="$APACHE_SYSTEM_DIR/mods-available"
APACHE_MODS_ENABLED="$APACHE_SYSTEM_DIR/mods-enabled"
APACHE_TEMPLATE="$PTEMPPATH/config/zigbee2mqtt-proxy.conf.template"
APACHE_PROXY_CONF="$PCONFIG/zigbee2mqtt-proxy.conf"
APACHE_PROXY_LINK="$APACHE_CONF_ENABLED/zigbee2mqtt-${PDIR}.conf"

case "$PDIR" in
    *[!A-Za-z0-9_-]*|"")
        echo "<ERROR> Invalid plugin folder for Apache proxy configuration: $PDIR"
        exit 2
        ;;
esac

enable_apache_module() {
    APACHE_MODULE="$1"
    APACHE_MODULE_SOURCE="$APACHE_MODS_AVAILABLE/${APACHE_MODULE}.load"
    APACHE_MODULE_LINK="$APACHE_MODS_ENABLED/${APACHE_MODULE}.load"

    if [ ! -e "$APACHE_MODULE_SOURCE" ]; then
        echo "<ERROR> Required Apache module is unavailable: $APACHE_MODULE"
        return 1
    fi

    if [ ! -e "$APACHE_MODULE_LINK" ] && [ ! -L "$APACHE_MODULE_LINK" ]; then
        ln -s "../mods-available/${APACHE_MODULE}.load" "$APACHE_MODULE_LINK"
    fi
}

if [ ! -d "$APACHE_CONF_ENABLED" ] || [ ! -f "$APACHE_TEMPLATE" ]; then
    echo "<ERROR> LoxBerry Apache proxy configuration paths are unavailable"
    exit 2
fi

enable_apache_module proxy && enable_apache_module proxy_http && enable_apache_module proxy_wstunnel || exit 2

sed "s|__PLUGIN_FOLDER__|$PDIR|g" "$APACHE_TEMPLATE" > "$APACHE_PROXY_CONF.new"
if [ ! -s "$APACHE_PROXY_CONF.new" ]; then
    echo "<ERROR> Could not generate Apache proxy configuration"
    exit 2
fi

APACHE_PROXY_CONF_EXISTED=0
if [ -f "$APACHE_PROXY_CONF" ]; then
    cp -p "$APACHE_PROXY_CONF" "$APACHE_PROXY_CONF.previous"
    APACHE_PROXY_CONF_EXISTED=1
fi

if [ -e "$APACHE_PROXY_LINK" ] || [ -L "$APACHE_PROXY_LINK" ]; then
    if [ "$(readlink -f "$APACHE_PROXY_LINK")" != "$APACHE_PROXY_CONF" ]; then
        echo "<ERROR> Refusing to replace Apache configuration not owned by this plugin: $APACHE_PROXY_LINK"
        exit 2
    fi
else
    ln -s "$APACHE_PROXY_CONF" "$APACHE_PROXY_LINK"
fi

mv "$APACHE_PROXY_CONF.new" "$APACHE_PROXY_CONF"
if ! apache2ctl -t -f "$APACHE_SYSTEM_DIR/apache2.conf"; then
    echo "<ERROR> Generated Apache proxy configuration is invalid; restoring previous configuration"
    if [ "$APACHE_PROXY_CONF_EXISTED" -eq 1 ]; then
        mv "$APACHE_PROXY_CONF.previous" "$APACHE_PROXY_CONF"
    else
        rm -f "$APACHE_PROXY_CONF"
        unlink "$APACHE_PROXY_LINK"
    fi
    exit 2
fi
rm -f "$APACHE_PROXY_CONF.previous"
if ! systemctl reload apache2; then
    echo "<ERROR> Could not reload Apache after enabling the Zigbee2MQTT UI proxy"
    exit 2
fi

# if we have a new installation we setup the encryption
# https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt/issues/13
if [ "$ISUPGRADE" -eq "0" ]; then
    echo "<INFO> Fresh installation detected - Set encryption key"
    php $PBIN/setup-encryption.php
fi

echo "<INFO> Updating service config"
if [ "$PIVERS" = 'type_0' ] || [ "$PIVERS" = 'type_1' ]; then
    ln -f -s $PCONFIG/zigbee2mqttNode10.service /etc/systemd/system/zigbee2mqtt.service
else
    ln -f -s $PCONFIG/zigbee2mqtt.service /etc/systemd/system/zigbee2mqtt.service
fi

# Enable auto-start of zigbee2mqtt service
systemctl daemon-reload
systemctl enable zigbee2mqtt
systemctl start zigbee2mqtt

# Exit with Status 0
exit 0
