#!/bin/bash

# credits to:
# https://www.cyberciti.biz/faq/ping-test-a-specific-port-of-machine-ip-address-using-linux-unix/
# https://stackoverflow.com/questions/43876891/given-ip-address-and-netmask-how-can-i-calculate-the-subnet-range-using-bash

# IP address to scan subnet
ip=$1

# Network mask
mask=$2

# Port to scan
port=$3

# Port scan time out
timeout=1

# Regex to check for valid IP address
ip_regex="^([0-9]{1,3}\.){3}[0-9]{1,3}$"

# Regex to heck for zero IP address
zero_regex="^0\.0\.0\.[0-9]+$"

# Check if the IP address, mask, and port are specified and IP address and mask are valid IP addresses
if [[ $ip =~ $ip_regex && ! $ip =~ $zero_regex && $mask =~ $ip_regex && ! $mask =~ $zero_regex && $port =~ ^[0-9]+$ ]]; then
	# Break down IP address into octets
	IFS=. read -r i1 i2 i3 i4 <<< "$ip"

	# Break down the mask into octets
	IFS=. read -r m1 m2 m3 m4 <<< "$mask"

	# Uncomment the following lines to display network information
	# echo "network:	$((i1 & m1)).$((i2 & m2)).$((i3 & m3)).$((i4 & m4))"
	# echo "broadcast:	$((i1 & m1 | 255-m1)).$((i2 & m2 | 255-m2)).$((i3 & m3 | 255-m3)).$((i4 & m4 | 255-m4))"
	# echo "first IP:	$((i1 & m1)).$((i2 & m2)).$((i3 & m3)).$(((i4 & m4)+1))"
	# echo "last IP:	$((i1 & m1 | 255-m1)).$((i2 & m2 | 255-m2)).$((i3 & m3 | 255-m3)).$(((i4 & m4 | 255-m4)-1))"

	# Port scan the subnet(s)
	for net1 in $(seq $((i1 & m1)) $((i1 & m1 | 255-m1))); do
		for net2 in $(seq $((i2 & m2)) $((i2 & m2 | 255-m2)) ); do
			for net3 in $(seq $((i3 & m3)) $((i3 & m3 | 255-m3)) ); do
				for host in $(seq $(((i4 & m4)+1)) $(((i4 & m4 | 255-m4)-1)) ); do
					ip="${net1}.${net2}.${net3}.${host}"
					# Scan the IP address and port and timeout if port is not open - run in the background
					nice timeout $timeout bash -c "(echo >/dev/tcp/${ip}/${port}) &>/dev/null && echo $ip" 2>/dev/null &
				done
			done
		done
	done

	# Wait for all background port scans to be completed
	wait
else
	logger -t "unassigned.devices" "Cannot scan IP '${ip}' with mask '${mask}' for port '${port}' access"
fi
