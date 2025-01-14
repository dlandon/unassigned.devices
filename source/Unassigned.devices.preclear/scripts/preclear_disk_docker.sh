#!/bin/bash
#
# Copyright 2022-2025, Dan Landon
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2,
# as published by the Free Software Foundation.
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
#
# Wrapper for the binhex preclear docker container
#

fast_postread="n"
noprompt="n"

progname="preclear_disk_docker.sh"
options=$*
docker="docker exec -i -t binhex-preclear bash preclear_binhex.sh "

#
# Latest docker version
#
ver="1.22"

while getopts ":tnc:WM:m:hvDNw:r:b:AalC:VRDd:zsSfJo:" opt; do
	case $opt in
		v ) echo $progname version: $ver
			exit 0
		;;
	esac
done

shift $(($OPTIND - 1))

if [ "$1" != "" ]; then
	#
	# This is the device to be precleared
	#
	devname=$(basename $1)

	#
	# Set status file as we are starting a preclear operation
	#
	echo -n "{$devname}|NN|Starting..." > /tmp/preclear/preclear_stat_${devname}

	#
	# Copy status file to docker so it is current
	#
	docker cp /tmp/preclear/preclear_stat_${devname} binhex-preclear:/tmp/preclear_stat_${devname}
fi

$docker $options 2>/dev/null

echo -n "{$devname}|NN|Finished check Progess for details!" > /tmp/preclear/preclear_stat_${devname}
