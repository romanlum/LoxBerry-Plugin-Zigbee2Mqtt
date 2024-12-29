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
# Version dietpi 9.9
# Version loxberry 3.0.1.2
set -e

DIETPI_IMG="DietPi_NativePC-BIOS-x86_64-Bookworm"
BOX_NAME="dietpi-bullseye"

# create build dir
mkdir -p target
cd target

# download dietpi img
wget -nc -nv -c https://dietpi.com/downloads/images/${DIETPI_IMG}.img.xz
# extract files
unxz ${DIETPI_IMG}.img.xz

qemu-img convert -f raw ${DIETPI_IMG}.img -O qcow2 box.img


# add files
mkdir mountdisk
sudo guestmount --add box.img --mount /dev/sda1 mountdisk/
sudo cp -f ../dietpi.txt mountdisk/boot/dietpi.txt
sudo cp -f ../Automation_Custom_PreScript.sh mountdisk/boot/Automation_Custom_PreScript.sh
sudo guestunmount mountdisk
sleep 5
rmdir mountdisk

# pack to box file
tar czf ../${BOX_NAME}.box ../metadata.json ../Vagrantfile ./box.img

cd ..