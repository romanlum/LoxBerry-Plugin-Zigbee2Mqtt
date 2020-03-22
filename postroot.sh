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

if [ -e /opt/zigbee2mqtt ] ; then
	echo "<INFO> Removing old zigbee2mqtt installation"
	rm -f -r /opt/zigbee2mqtt
fi

git  clone --branch 1.10.0 --depth 1 https://github.com/Koenkk/zigbee2mqtt.git /opt/zigbee2mqtt

chown -R loxberry:loxberry /opt/zigbee2mqtt 

cd /opt/zigbee2mqtt
npm install

mkdir -p /opt/zigbee2mqtt/data/log

chown -R loxberry:loxberry /opt/zigbee2mqtt 

echo "<INFO> Copy back existing config files"
cp -f -r /tmp/$PTEMPDIR\_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/ 

echo "<INFO> Remove temporary folders"
rm -f -r /tmp/$PTEMPDIR\_upgrade

echo "<INFO> Linking log to log folder"
ln -f -s /opt/zigbee2mqtt/data/log $PLOG

echo "<INFO> Updating configuration"
ln -f -s $PCONFIG/configuration.yaml /opt/zigbee2mqtt/data/configuration.yaml

echo "<INFO> Updating service config"
ln -f -s $PCONFIG/zigbee2mqtt.service /etc/systemd/system/zigbee2mqtt.service

# Enable auto-start of Mosquitto service
systemctl enable zigbee2mqtt
systemctl start zigbee2mqtt


# Exit with Status 0
exit 0
