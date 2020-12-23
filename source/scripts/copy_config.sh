#!/bin/bash
#
# Copy config files to ram tmpfs.
#
/usr/bin/rm /tmp/unassigned.devices/config/*.cfg
/usr/bin/cp /boot/config/plugins/unassigned.devices/*.cfg /tmp/unassigned.devices/config/ 2>/dev/null
