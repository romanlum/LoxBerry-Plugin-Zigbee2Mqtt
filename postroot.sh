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
FALLBACK_NODE_VERSION=$NODE_VERSION $PBIN/install-zigbee2mqtt.sh "$TARGET_VERSION" --from-plugin-install
retval="$?"
if [ $retval -ne 0 ]; then
    echo "<ERROR> Installation of zigbee2mqtt $TARGET_VERSION failed"
    exit $retval
fi

echo "<INFO> Remove temporary folders"
rm -f -r /tmp/${PTEMPDIR}_upgrade

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
