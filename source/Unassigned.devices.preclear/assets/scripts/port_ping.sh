#!/bin/bash

# credits to https://www.cyberciti.biz/faq/ping-test-a-specific-port-of-machine-ip-address-using-linux-unix/
# and to https://stackoverflow.com/questions/43876891/given-ip-address-and-netmask-how-can-i-calculate-the-subnet-range-using-bash

ip=$1; mask=$2; port=$3; timeout=5

IFS=. read -r i1 i2 i3 i4 <<< "$ip"
IFS=. read -r m1 m2 m3 m4 <<< "$mask"

#echo "network:   $((i1 & m1)).$((i2 & m2)).$((i3 & m3)).$((i4 & m4))"
#echo "broadcast: $((i1 & m1 | 255-m1)).$((i2 & m2 | 255-m2)).$((i3 & m3 | 255-m3)).$((i4 & m4 | 255-m4))"
#echo "first IP:  $((i1 & m1)).$((i2 & m2)).$((i3 & m3)).$(((i4 & m4)+1))"
#echo "last IP:   $((i1 & m1 | 255-m1)).$((i2 & m2 | 255-m2)).$((i3 & m3 | 255-m3)).$(((i4 & m4 | 255-m4)-1))"

for net1 in $(seq $((i1 & m1)) $((i1 & m1 | 255-m1))); do
  for net2 in $(seq $((i2 & m2)) $((i2 & m2 | 255-m2)) ); do
    for net3 in $(seq $((i3 & m3)) $((i3 & m3 | 255-m3)) ); do
      for host in $(seq $(((i4 & m4)+1)) $(((i4 & m4 | 255-m4)-1)) ); do
        ip="${net1}.${net2}.${net3}.${host}"
        timeout -s 5 $timeout bash -c "(echo >/dev/tcp/${ip}/${port}) &>/dev/null && echo $ip" 2>/dev/null &
      done
    done
  done
done

wait
