#!/bin/bash
#
# Write an unRAID compatible mbr.
#
write_signature() {
	local disk=${device}
	local disk_blocks=${blocks_512} 
	local max_mbr_blocks partition_size size1=0 size2=0 sig start_sector=${start_sector} var
	let partition_size=($disk_blocks - $start_sector)
	max_mbr_blocks=$(/bin/printf "%d" 0xFFFFFFFF)
  
	if [ $disk_blocks -ge $max_mbr_blocks ]; then
		size1=$(/bin/printf "%d" "0x00020000")
		size2=$(/bin/printf "%d" "0xFFFFFF00")
		start_sector=1
		partition_size=$(/bin/printf "%d" 0xFFFFFFFF)
	fi

	/bin/dd if=/dev/zero bs=512 seek=1 of=$disk  count=4096 2>/dev/null
	/bin/dd if=/dev/zero bs=1 seek=462 count=48 of=$disk >/dev/null 2>&1
	/bin/dd if=/dev/zero bs=446 count=1 of=$disk  >/dev/null 2>&1
	echo -ne "\0252" | /bin/dd bs=1 count=1 seek=511 of=$disk >/dev/null 2>&1
	echo -ne "\0125" | /bin/dd bs=1 count=1 seek=510 of=$disk >/dev/null 2>&1

	for var in $size1 $size2 $start_sector $partition_size ; do
		for hex in $(tac <(fold -w2 <(/bin/printf "%08x\n" $var) )); do
			sig="${sig}\\x${hex}"
		done
	done
	/bin/printf $sig| /bin/dd seek=446 bs=1 count=16 of=$disk >/dev/null 2>&1

	echo -ne "\0203" | /bin/dd bs=1 count=1 seek=450 of=$disk >/dev/null 2>&1
}

# Disk properties
device=$1
start_sector=$2
blocks_512=$(/sbin/blockdev --getsz ${device} 2>/dev/null)

echo "write mbr signature"
write_signature
echo "done"
