#
# Make unassigned.devices.preclear distribution package
#
# Set permissions.
chown -R root:root unassigned.devices.preclear/
chmod -R 755 unassigned.devices.preclear/

# Build the tgz.
tar -cvzf unassigned.devices.preclear-2022.06.10.tgz unassigned.devices.preclear/

mkdir /boot/config/plugins/unassigned.devices.preclear/ 2>/dev/null
cp unassigned.devices.preclear-2022.06.10.tgz /boot/config/plugins/unassigned.devices.preclear/

md5sum unassigned.devices.preclear-2022.06.10.tgz > MD5

chmod -R 777 unassigned.devices.preclear/
