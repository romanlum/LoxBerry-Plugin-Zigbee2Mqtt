#!/bin/sh

# Bash script which is executed by bash *BEFORE* installation is started
# (*BEFORE* preinstall but *AFTER* preupdate). Use with caution and remember,
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

#!/bin/bash

# Note: the data/log symlinks are no longer touched here. install-zigbee2mqtt.sh
# builds the new version in /opt/zigbee2mqtt.new (linking data/log there) and only
# swaps it over /opt/zigbee2mqtt once the build fully succeeds, so the currently
# running installation must stay intact until then. Unlinking it here unconditionally
# used to leave a failed upgrade (e.g. not enough disk space) with a broken data link,
# since nothing would recreate it if install-zigbee2mqtt.sh never reached the swap.

echo "<INFO> Stopping service if already running"
if systemctl is-active --quiet zigbee2mqtt; then
	systemctl stop zigbee2mqtt
fi
