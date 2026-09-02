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

if [ -e /opt/zigbee2mqtt ]; then
    echo "<INFO> Removing old zigbee2mqtt installation"
    rm -f -r /opt/zigbee2mqtt
fi

git clone --branch $ZIGBEE2MQTT_VERSION --depth 1 https://github.com/Koenkk/zigbee2mqtt.git /opt/zigbee2mqtt

cd /opt/zigbee2mqtt

# Get system architecture
ARCH=$(uname -m)

# Map architecture to Node.js download URL
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
    echo "Unsupported architecture: $ARCH"
    exit 1
    ;;
esac

# NODE_VERSION is set in version.sh

wget https://nodejs.org/dist/$NODE_VERSION/node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
tar -xvf node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
mkdir -p /opt/zigbee2mqtt/node
mv node-$NODE_VERSION-linux-$NODE_ARCH/* /opt/zigbee2mqtt/node/
rm -rf node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz
export PATH=/opt/zigbee2mqtt/node/bin:$PATH


npm install -g "$(node -p "require('./package.json').packageManager")"
node --version  
pnpm --version  
pnpm i --frozen-lockfile

# Build Zigbee2MQTT
pnpm run build
retval="$?"
if [ $retval -ne 0 ]; then
    echo "npm install failed"
    exit $retval
fi

echo "<INFO> Remove default data folder"
rm -f -r /opt/zigbee2mqtt/data

chown -R loxberry:loxberry /opt/zigbee2mqtt

echo "<INFO> Remove temporary folders"
rm -f -r /tmp/${PTEMPDIR}_upgrade

echo "<INFO> Linking log to log folder"
ln -f -s $PLOG /opt/zigbee2mqtt/log

echo "<INFO> Updating data folder"
ln -f -s $PDATA /opt/zigbee2mqtt/data

echo "<INFO> Refresh config"
php $PBIN/update-config.php

chown loxberry:loxberry $PDATA/* -R

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
