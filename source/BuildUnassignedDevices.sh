#
# Make unassigned.devices distribution package
#
# Set permissions.
chown -R root:root unassigned.devices/
chmod -R 755 unassigned.devices/

# Build the tgz.
tar -cvzf unassigned.devices-2022.06.10.tgz unassigned.devices/

mkdir /boot/config/plugins/unassigned.devices/ 2>/dev/null
cp unassigned.devices-2022.06.10.tgz /boot/config/plugins/unassigned.devices/

md5sum unassigned.devices-2022.06.10.tgz > MD5

chmod -R 777 unassigned.devices/
