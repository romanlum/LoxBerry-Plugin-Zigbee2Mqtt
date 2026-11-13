#!/bin/sh

# Bash script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very first step (*BEFORE*
# preinstall.sh) and can be used e.g. to save existing configfiles to /tmp
# during installation. Use with caution and remember, that all systems may be
# different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
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

#source version file
. ${PTEMPPATH}/version.sh

# A version picked earlier in the web UI (persisted in installed-version.json)
# takes precedence over the version.sh baseline shipped with this plugin
# release - see postroot.sh and webfrontend/htmlauth/version.php.
TARGET_VERSION=$ZIGBEE2MQTT_VERSION
if [ -f "$PCONFIG/installed-version.json" ]; then
    PINNED_VERSION=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); if(isset($d["zigbee2mqttVersion"])) echo $d["zigbee2mqttVersion"];' "$PCONFIG/installed-version.json")
    if [ -n "$PINNED_VERSION" ]; then
        TARGET_VERSION=$PINNED_VERSION
    fi
fi

echo "<INFO> Checking if zigbee2mqtt repository is reachable before upgrade"
git ls-remote --exit-code https://github.com/Koenkk/zigbee2mqtt.git refs/tags/$TARGET_VERSION
retVal=$?
if [ $retVal -ne 0 ]; then
    echo "<ERROR> Could not reach zigbee2mqtt repository, or the selected version $TARGET_VERSION does not exist. Please check if your loxberry has an internet connection."
    exit 2
fi

echo "<INFO> Creating temporary folders for upgrading"
mkdir /tmp/${PTEMPDIR}_upgrade
mkdir /tmp/${PTEMPDIR}_upgrade/config
mkdir /tmp/${PTEMPDIR}_upgrade/data

echo "<INFO> Backing up existing files"
cp -v -r $PCONFIG/ /tmp/${PTEMPDIR}_upgrade/config
cp -v -r $PDATA/ /tmp/${PTEMPDIR}_upgrade/data

# install.lock and upgrade-state.json are transient runtime state written by
# install-zigbee2mqtt.sh, not config to preserve across an upgrade. If they
# were backed up here, postroot.sh (running as root) would restore them with
# a plain cp, leaving install.lock root-owned and permanently unwritable by
# loxberry - wedging every future install/upgrade with a misleading
# "Permission denied" / "Another install already running" error.
rm -f /tmp/${PTEMPDIR}_upgrade/config/$PDIR/install.lock
rm -f /tmp/${PTEMPDIR}_upgrade/config/$PDIR/upgrade-state.json

# Exit with Status 0
exit 0
