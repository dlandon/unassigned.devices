#!/bin/bash
#
# Copy config files to ram tmpfs.
#
/usr/bin/rm /tmp/unassigned.devices/config/*.cfg
/usr/bin/cp /boot/config/plugins/unassigned.devices/*.cfg /tmp/unassigned.devices/config/ 2>/dev/null

#
# Clean up the state files.
#

# Clear run status.
rm /var/state/unassigned.devices/run_status.json 2>/dev/null

# Clear ping status.
rm /var/state/unassigned.devices/ping_status.json 2>/dev/null

# Clear size, used, and free status.
rm /var/state/unassigned.devices/df_status.json 2>/dev/null

# Clear udev status.
rm /var/state/unassigned.devices/unassigned.devices.ini 2>/dev/null
