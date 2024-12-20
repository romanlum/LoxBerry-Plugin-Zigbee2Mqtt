#!/bin/bash

####
# GNU AGPL-3.0 License
#
# This file is part of github.com/aramcap/vagrant_dietpi
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU Affero General Public License as published by the Free
# Software Foundation, either version 3 of the License, or (at your option) any
# later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
# FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
# details.
#
# You have a copy of the GNU Affero General Public License here: https://www.gnu.org/licenses/agpl-3.0.en.html.
####

set -e

DIETPI_IMG="DietPi_Proxmox-x86_64-Bookworm"
BOX_NAME="dietpi-bullseye"

# create build dir
mkdir -p target
cd target

# download dietpi img
wget -nc -c https://dietpi.com/downloads/images/${DIETPI_IMG}.qcow2.xz
# extract files
unxz ${DIETPI_IMG}.qcow2.xz

# convert from img to qcow2 and resize to 10G
qemu-img convert -f raw ${DIETPI_IMG}.qcow2 -O qcow2 box.img
qemu-img resize -f qcow2 box.img 10G

# add files
mkdir mountdisk
guestmount --add box.img --mount /dev/sda1 mountdisk/
yes | cp -f ../dietpi.txt mountdisk/boot/dietpi.txt
yes | cp -f ../Automation_Custom_PreScript.sh mountdisk/boot/Automation_Custom_PreScript.sh
guestunmount mountdisk
sleep 5
rmdir mountdisk

# pack to box file
tar czf ${BOX_NAME}.box ../metadata.json ../Vagrantfile ./box.img

cd -