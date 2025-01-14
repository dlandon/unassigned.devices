#!/bin/bash
#
# Copyright 2015-2020, Guilherme Jardim
# Copyright 2022-2025, Dan Landon
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2,
# as published by the Free Software Foundation.
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#

LC_CTYPE=C
export LC_CTYPE

ionice -c3 -p$BASHPID

# Version
version="1.0.31"
#
# Change Log
# 1.0.23
# Change file references on flash from preclear.disk to unassigned.devices.preclear.
# Change log file to /var/log/preclear/preclear.log.
# Cleaned up code with tabs instead of spaces for readability.
# Fixed head stress holding up pre-read status.
# Lots of tmux adjustments.
# Stop logging everything to the syslog.  Way too chatty!
#
# 1.0.24
# Fix preclear status to right justify the preclear results.
# Fix integer error on notify check.
#
# 1.0.25
# Only write resume file to flash when absolutely necessary.
# Fix diff calculation when POS is H/M/S notation.
#
# 1.0.26
# Remove SMART assessment in SMART report.
#
#
# 1.0.27
# Prevent divide by zero when checking root free space.
#
# 1.0.28
# Double dd_hang timeout when reading and writing to disk.
#
#
# 1.0.29
# Don't read disk data into memory and check to be zero when
# doing post-read.  Disk data is read, but not confirmed
# to be zero.
#
# 1.0.30
# Increase dd_hang timeout even more when reading and writing to disk.
#
# 1.0.31
# Add date and time to preclear report.
#
######################################################
##													##
##				PROGRAM FUNCTIONS					##
##													##
######################################################

DOCROOT=`grep -Po '^chdir = \K.*' /etc/php-fpm.d/www.conf 2>/dev/null`
if [ -z ${DOCROOT} ];then
	DOCROOT="/usr/local/emhttp"
fi

# Send debug messages to log
debug() {
	local msg="$*"
	if [ -z "$msg" ]; then
		while read msg; do 
			cat <<< "$(date +"%b %d %T" ) ${log_prefix} $msg" >> /var/log/preclear/preclear.log
		done
	else
		cat <<< "$(date +"%b %d %T" ) ${log_prefix} $msg" >> /var/log/preclear/preclear.log
	fi
}

trim() {
	local var="$*"
	if [ -z "$var" ]; then
		read var;
	fi
	var="${var#"${var%%[![:space:]]*}"}"	# remove leading whitespace characters
	var="${var%"${var##*[![:space:]]}"}"	# remove trailing whitespace characters
	echo -n "$var"
}

list_unraid_disks(){
	local _result=$1
	local i=0
	#
	# Get flash disk device
	#
	unraid_disks[$i]=$(readlink -f /dev/disk/by-label/UNRAID|grep -Po "[^\d]*")

	#
	# Grab cache disks using disks.cfg file
	#
	if [ -f "/boot/config/disk.cfg" ]
	then
		while read line ; do
			if [ -n "$line" ]; then
				let "i+=1" 
				unraid_disks[$i]=$(find /dev/disk/by-id/ -type l -iname "*-$line*" ! -iname "*-part*"| xargs readlink -f)
			fi
		done < <(cat /boot/config/disk.cfg | grep 'cacheId' | grep -Po '=\"\K[^\"]*')
	fi

	#
	# Get array disks using super.dat id's
	#
	if [ -f "/var/local/emhttp/disks.ini" ]; then
		while read line; do
			disk="/dev/${line}"
			if [ -n "$disk" ]; then
				let "i+=1"
				unraid_disks[$i]=$(readlink -f $disk)
			fi
		done < <(cat /var/local/emhttp/disks.ini | grep -Po 'device="\K[^"]*')
	fi
	eval "$_result=(${unraid_disks[@]})"
}

list_all_disks(){
	local _result=$1
	for disk in $(find /dev/disk/by-id/ -type l ! \( -iname "wwn-*" -o -iname "*-part*" \))
	do
		all_disks+=($(readlink -f $disk))
	done
	eval "$_result=(${all_disks[@]})"
}

is_preclear_candidate () {
	list_unraid_disks unraid_disks
	part=($(comm -12 <(for X in "${unraid_disks[@]}"; do echo "${X}"; done|sort)	<(echo $1)))
	if [ ${#part[@]} -eq 0 ] && [ $(cat /proc/mounts|grep -Poc "^${1}") -eq 0 ]
	then
		return 0
	else
		return 1
	fi
}

#
# list the disks that are not assigned to the array. They are the possible drives to pre-clear
#
list_device_names() {
	echo "========================================"
	echo " Disks not assigned to the Unraid array "
	echo " (potential candidates for clearing)"
	echo "========================================"
	list_unraid_disks unraid_disks
	list_all_disks all_disks
	unassigned=($(comm -23 <(for X in "${all_disks[@]}"; do echo "${X}"; done|sort)	<(for X in "${unraid_disks[@]}"; do echo "${X}"; done|sort)))

	if [ ${#unassigned[@]} -gt 0 ]
	then
		for disk in "${unassigned[@]}"
		do
			if [ $(cat /proc/mounts|grep -Poc "^${disk}") -eq 0 ]
			then
				serial=$(udevadm info --query=property --path $(udevadm info -q path -n $disk 2>/dev/null) 2>/dev/null|grep -Po "ID_SERIAL=\K.*")
				echo "  ${disk} = ${serial}"
			fi
		done
	else
		echo "No un-assigned disks detected."
	fi
}

list_unassigned_disks() {
	list_unraid_disks unraid_disks
	list_all_disks all_disks
	unassigned=($(comm -23 <(for X in "${all_disks[@]}"; do echo "${X}"; done|sort)	<(for X in "${unraid_disks[@]}"; do echo "${X}"; done|sort)))

	if [ ${#unassigned[@]} -gt 0 ]
	then
		for disk in "${unassigned[@]}"
		do
			if [ $(cat /proc/mounts|grep -Poc "^${disk}") -eq 0 ]
			then
				serial=$(udevadm info --query=property --path $(udevadm info -q path -n $disk 2>/dev/null) 2>/dev/null|grep -Po "ID_SERIAL=\K.*")
				name=$(basename $disk)
				echo "$name = $serial"
			fi
		done
	fi
}

#
# Send notifications.
#
send_notify() {
	subject=$(echo ${1})
	description=$(echo ${2})
	message=$(echo ${3})
	recipient=${4}
	if [ -n "${5}" ]; then
		importance="${5}"
	else
		importance="normal"
	fi

	notify_script="${DOCROOT}/webGui/scripts/notify"
	$notify_script -e "Preclear on ${disk_properties[serial]}" -s """${subject}""" -d """${description}""" -m """${message}""" -i "${importance}"
}

append() {
	local _array=$1 _array_keys="${1}_keys" _k _key;
	eval "local x=\${$_array+x}"
	if [ -z $x ]; then
		eval "declare -g -A $_array"
		eval "declare -g -a $_array_keys"
	fi
	if [ "$#" -eq "3" ]; then
		_key=$2
		el=$(printf "[$_key]='%s'" "${@:3}")
	else
		for (( i = 0; i < 1000; i++ )); do
			eval "_k=\${$_array[$i]+x}"
			if [ -z "$_k" ] ; then
				break
			fi
		done
		_key=$i
		el="[$_key]=\"${@:2}\""
	fi
	eval "$_array+=($el);$_array_keys+=($_key)";
}

array_enumerate() {
	local i _column z
	for z in $@; do
		echo -e "array '$z'\n ("
		eval "_column="";for i in \"\${!$z[@]}\"; do	_column+=\"| | [\$i]| -> |\${$z[\$i]}\n\"; done"
		echo -e $_column|column -t -s "|"
		echo -e " )\n"
	done
}

array_enumerate2() {
	local i _column z
	for z in $@; do
		debug "array '$z'"
		eval "_column="";for i in \"\${!$z[@]}\"; do debug \"\$i -> \${$z[\$i]}\"; done"
	done
}

array_content() { local _arr=$(eval "declare -p $1") && echo "${_arr#*=}"; }

read_mbr() {
	#
	# called read_mbr [variable] "/dev/sdX" 
	#
	local disk=$1 i
	local mbr

	#
	# verify MBR boot area is clear
	#
	append mbr `dd bs=446 count=1 if=$disk 2>/dev/null | sum | awk '{print $1}'`

	#
	# verify partitions 2,3, & 4 are cleared
	#
	append mbr `dd bs=1 skip=462 count=48 if=$disk 2>/dev/null | sum | awk '{print $1}'`

	#
	# verify partition type byte is clear
	#
	append mbr `dd bs=1 skip=450 count=1 if=$disk 2>/dev/null | sum | awk '{print $1}'`

	#
	# verify MBR signature bytes are set as expected
	#
	append mbr `dd bs=1 count=1 skip=511 if=$disk 2>/dev/null | sum | awk '{print $1}'`
	append mbr `dd bs=1 count=1 skip=510 if=$disk 2>/dev/null | sum | awk '{print $1}'`

	for i in $(seq 446 461); do
		append mbr `dd bs=1 count=1 skip=$i if=$disk 2>/dev/null | sum | awk '{print $1}'`
	done
	echo $(declare -p mbr)
}

verify_mbr() {
	#
	# called verify_mbr "/dev/disX"
	#
	local cleared
	local disk=$1
	local disk_blocks=${disk_properties[blocks_512]}
	local i
	local max_mbr_blocks
	local mbr_blocks
	local over_mbr_size
	local partition_size
	local patterns
	local -a sectors
	local start_sector 
	local patterns=("00000" "00000" "00000" "00170" "00085")
	local max_mbr_blocks=$(printf "%d" 0xFFFFFFFF)

	if [ $disk_blocks -ge $max_mbr_blocks ]; then
		over_mbr_size="y"
		patterns+=("00000" "00000" "00002" "00000" "00000" "00255" "00255" "00255")
		partition_size=$(printf "%d" 0xFFFFFFFF)
	else
		patterns+=("00000" "00000" "00000" "00000" "00000" "00000" "00000" "00000")
		partition_size=$disk_blocks
	fi

	#
	# verify MBR boot area is clear
	#
	sectors+=(`dd bs=446 count=1 if=$disk 2>/dev/null | sum | awk '{print $1}'`)

	#
	# verify partitions 2,3, & 4 are cleared
	#
	sectors+=(`dd bs=1 skip=462 count=48 if=$disk 2>/dev/null | sum | awk '{print $1}'`)

	#
	# verify partition type byte is clear
	#
	sectors+=(`dd bs=1 skip=450 count=1 if=$disk 2>/dev/null | sum | awk '{print $1}'`)

	#
	# verify MBR signature bytes are set as expected
	#
	sectors+=(`dd bs=1 count=1 skip=511 if=$disk 2>/dev/null | sum | awk '{print $1}'`)
	sectors+=(`dd bs=1 count=1 skip=510 if=$disk 2>/dev/null | sum | awk '{print $1}'`)

	for i in $(seq 446 461); do
		sectors+=(`dd bs=1 count=1 skip=$i if=$disk 2>/dev/null | sum | awk '{print $1}'`)
	done

	for i in $(seq 0 $((${#patterns[@]}-1)) ); do
		if [ "${sectors[$i]}" != "${patterns[$i]}" ]; then
			echo "Failed test 1: MBR signature is not valid, byte $i [${sectors[$i]}] != [${patterns[$i]}]"
			return 1
		fi
	done

	for i in $(seq ${#patterns[@]} $((${#sectors[@]}-1)) ); do
		if [ $i -le 16 ]; then
			start_sector="$(echo ${sectors[$i]} | awk '{printf("%02x", $1)}')${start_sector}"
		else
			mbr_blocks="$(echo ${sectors[$i]} | awk '{printf("%02x", $1)}')${mbr_blocks}"
		fi
	done

	start_sector=$(printf "%d" "0x${start_sector}")
	mbr_blocks=$(printf "%d" "0x${mbr_blocks}")

	case "$start_sector" in
		63|64)
			if [ $disk_blocks -ge $max_mbr_blocks ]; then
				partition_size=$(printf "%d" 0xFFFFFFFF)
			else
				let partition_size=($disk_blocks - $start_sector)
			fi
			;;
		1)
			if [ "$over_mbr_size" != "y" ]; then
				echo "Failed test 2: GPT start sector [$start_sector] is wrong, should be [1]."
				return 1
			fi
			;;
		*)
			echo "Failed test 3: start sector is different from those accepted by Unraid."
			;;
	esac
	if [ $partition_size -ne $mbr_blocks ]; then
		echo "Failed test 4: physical size didn't match MBR declared size. [$partition_size] != [$mbr_blocks]"
		return 1
	fi
	return 0
}

write_signature() {
	local disk=${disk_properties[device]}
	local disk_blocks=${disk_properties[blocks_512]} 
	local max_mbr_blocks partition_size size1=0 size2=0 sig start_sector=$1 var
	let partition_size=($disk_blocks - $start_sector)
	max_mbr_blocks=$(printf "%d" 0xFFFFFFFF)
	
	debug "Signature: writing signature..."

	if [ $disk_blocks -ge $max_mbr_blocks ]; then
		size1=$(printf "%d" "0x00020000")
		size2=$(printf "%d" "0xFFFFFF00")
		start_sector=1
		partition_size=$(printf "%d" 0xFFFFFFFF)
	fi

	dd if=/dev/zero bs=1 seek=462 count=48 of=$disk >/dev/null 2>&1
	dd if=/dev/zero bs=446 count=1 of=$disk	>/dev/null 2>&1
	echo -ne "\0252" | dd bs=1 count=1 seek=511 of=$disk >/dev/null 2>&1
	echo -ne "\0125" | dd bs=1 count=1 seek=510 of=$disk >/dev/null 2>&1

	awk 'BEGIN{
	printf ("%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c",
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),1,2)))
	}' $size1 $size2 $start_sector $partition_size | dd seek=446 bs=1 count=16 of=$disk >/dev/null 2>&1

	local sig=$(awk 'BEGIN{
	printf ("%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c",
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[3]),1,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),7,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),5,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),3,2)),
	strtonum("0x" substr(sprintf( "%08x\n", ARGV[4]),1,2)))
	}' $size1 $size2 $start_sector $partition_size | od -An -vtu1 )
}

maxExecTime() {
	# maxExecTime prog_name disk_name max_exec_time
	local exec_time=0
	local prog_name=$1
	local disk_name=$(basename $2)
	local max_exec_time=$3

	while read line; do
		local pid=$( echo $line | awk '{print $1}')
		local pid_date=$(find /proc/${pid} -maxdepth 0 -type d -printf "%a\n" 2>/dev/null)
		local pid_child=$(ps -h --ppid ${pid} 2>/dev/null | wc -l)
		# pid_child=0
		if [ -n "$pid_date" -a "$pid_child" -eq 0 ]; then
			eval "local pid_elapsed=$(( $(date +%s) - $(date +%s -d "$pid_date") ))"
			if [ "$pid_elapsed" -gt "$exec_time" ]; then
				exec_time=$pid_elapsed
			fi
			if [ "$pid_elapsed" -gt $max_exec_time ]; then
				debug "killing ${prog_name} with pid ${pid} - probably stalled ..." 
				kill -9 $pid &>/dev/null
			fi
		fi
	done < <(ps ax -o pid,cmd | awk '/'$prog_name'.*\/dev\/'${disk_name}'/{print $1}' )
	echo $exec_time
}

format_number() {
	# echo " $1 " | sed -r ':L;s=\b([0-9]+)([0-9]{3})\b=\1,\2=g;t L' | trim
	numfmt --to=si --suffix= --format='%1.1f' --round=nearest "$1" | trim
}

# Keep track of the elapsed time of the preread/clear/postread process
timer() {
	if [[ $# -eq 0 ]]; then
		echo $(date '+%s')
	else
		local	stime=$1
		etime=$(date '+%s')

		if [[ -z "$stime" ]]; 
			then stime=$etime; 
		fi

		dt=$((etime - stime))
		ds=$((dt % 60))
		dm=$(((dt / 60) % 60))
		dh=$((dt / 3600))
		printf '%d:%02d:%02d' $dh $dm $ds
	fi
}

format_time() {
	local time=$1
	ds=$((time % 60))
	dm=$(((time / 60) % 60))
	dh=$((time / 3600))
	printf '%d:%02d:%02d' $dh $dm $ds
}

time_elapsed(){
	local _elapsed="_time_elapsed_$1" _last="_time_elapsed_last_$1" _current=$(date '+%s') _delta

	eval "local x=\${$_elapsed+x}"
	if [ -z "$x" ]; then
		eval "declare -g $_elapsed=0 $_last=$_current"
	fi

	#
	# return without computing time if paused
	#
	if [ "$#" -eq "2" ] && [ "$2" == "paused" ]; then
		eval "$_last=$_current" && return 0
	fi

	#
	# set a new elapsed time if requested
	#
	if [ "$#" -eq "3" ] && [ "$2" == "set" ]; then
		eval "$_last=$_current;$_elapsed=$3" && return 0
	fi

	#
	# display formatted or export not formatted elapsed time
	#
	if [ "$#" -eq "2" ]; then
		if [ "$2" == "display" ]; then
			eval "local _time=\$$_elapsed"
			printf '%d:%02d:%02d' $(($_time / 3600)) $((($_time / 60) % 60)) $(($_time % 60))
			return 0
		elif [ "$2" == "export" ]; then
			eval "echo \$$_elapsed" && return 0
		fi
	fi

	#
	# compute the elapsed time
	#
	eval "_delta=\$(( $_current - \$$_last ));"
	eval "$_last=$_current && $_elapsed=\$(( \$$_elapsed + \$_delta ))"
}

dd_parse_status() {
	local output_file=$1
	local regex="([0-9]*) bytes.*copied, ([^\s]*) [^0-9]*(.*)"
	echo > "${output_file}"
	echo > "${output_file}_complete"
	while read line; do
		complete_lines=$(wc -l < "${output_file}_complete" 2>/dev/null)
		complete_lines=${complete_lines:-0}
		if [ $complete_lines -gt 30 ]; then
			echo "$(tail -n 29 "${output_file}_complete")" > "${output_file}_complete"
		fi
		echo "$line" >> "${output_file}_complete"
		if [[ $line =~ $regex ]]; then
			echo "${BASH_REMATCH[1]}|${BASH_REMATCH[2]}|${BASH_REMATCH[3]}" > "$output_file"
		fi
	done
}

get_dd_output() {
	init=$(stat -c %Z "$2")
	kill -USR1 $1 2>/dev/null
	for x in $(seq 10); do
		now=$(stat -c %Z "$2")
		if [ $init -lt $now ]; then
			cat "$2"
			return 0
		fi
		sleep 1
	done
}

is_numeric() {
	local _var=$2 _num=$3
	if [ ! -z "${_num##*[!0-9]*}" ]; then
		eval "$1=$_num"
	else
		echo "$_var value [$_num] is not a number. Please verify your command arguments.";
		exit 2
	fi
}

root_free_space() {
	local size=$(df --output=size / | tail -n +2 )
	local avail=$(df --output=avail / | tail -n +2 )
	if [ "$size" -gt "0" ]; then
		local pfree=$(( $avail * 100 / $size ))
	else
		local pfree="0"
	fi
	if [ "$pfree" -gt "15" ]; then
		return 0
	else
		debug "size: $size, available: $avail, free: ${pfree}%"
		df | debug
		return 1
	fi
}

save_current_status() {
	touch "${all_files[wait]}"
	local current_op=${diskop[current_op]}
	local current_pos=${diskop[current_pos]}
	local current_timer=${diskop[current_timer]}
	local tmp_resume="${all_files[resume_temp]}.tmp"

	echo -e '# parsed arguments'	> "$tmp_resume"
	for arg in "${!arguments[@]}"; do
		echo "$arg='${arguments[$arg]}'" >> "$tmp_resume"
	done
	echo -e '' >> "$tmp_resume"
	echo -e '# current operation' >> "$tmp_resume"
	echo -e "current_op='$current_op'" >> "$tmp_resume"
	echo -e "current_pos='$current_pos'" >> "$tmp_resume"
	echo -e "current_timer='$current_timer'" >> "$tmp_resume"
	echo -e "current_cycle='$cycle'\n" >> "$tmp_resume"

	echo -e '# previous operations' >> "$tmp_resume"
	echo -e "preread_average='$preread_average'" >> "$tmp_resume"
	echo -e "preread_speed='$preread_speed'" >> "$tmp_resume"
	echo -e "write_average='$write_average'" >> "$tmp_resume"
	echo -e "write_speed='$write_speed'" >> "$tmp_resume"
	echo -e "postread_average='$postread_average'" >> "$tmp_resume"
	echo -e "postread_speed='$postread_speed'\n" >> "$tmp_resume"

	echo -e '# current elapsed time' >> "$tmp_resume"
	echo -e "main_elapsed_time='$( time_elapsed main export )'" >> "$tmp_resume"
	echo -e "cycle_elapsed_time='$( time_elapsed cycle export )'" >> "$tmp_resume"
	mv -f "$tmp_resume" "${all_files[resume_temp]}"

	if [ "$1" = "1" ]; then
		mkdir -p /boot/preclear_reports/
		cp "${all_files[resume_temp]}" "${all_files[resume_file]}"
	fi

	sleep 0.1 && rm -f "${all_files[wait]}"
}

write_the_disk(){
	#
	# called write_the_disk
	#
	local blkpid=${all_files[blkpid]}
	local bytes_written=0
	local bytes_dd
	local bytes_dd_current=0
	local cycle=$cycle
	local cycles=$cycles
	local current_speed
	local current_elapsed=0
	local dd_exit=${all_files[dd_exit]}
	local dd_flags="conv=notrunc iflag=count_bytes,nocache,fullblock oflag=seek_bytes"
	local dd_hang=0
	local dd_last_bytes=0
	local dd_pid
	local dd_output=${all_files[dd_out]}
	local disk=${disk_properties[device]}
	local disk_name=${disk_properties[name]}
	local disk_blocks=${disk_properties[blocks]}
	local disk_bytes=${disk_properties[size]}
	local disk_serial=${disk_properties[serial]}
	local last_progress=-1
	local next_notify=-1

	declare -A is_paused_by
	declare -A paused_by
	local pause=${all_files[pause]}
	local is_paused=n
	local do_pause=0

	local percent_written
	local queued=n
	local queued_file=${all_files[queued]}
	local short_test=$short_test
	local skip_initial=0
	local stat_file=${all_files[stat]}
	local tb_formatted
	local total_bytes
	local update_period=0
	local display_pid=0
	local write_bs=2097152
	local write_type_v
	local write_type=$1
	local initial_bytes=$2
	local initial_timer=$3
	local output=$4
	local output_speed=$5

	#
	# start time
	#
	resume_timer=${!initial_timer}
	resume_timer=${resume_timer:-0}
	time_elapsed $write_type set $resume_timer

	touch $dd_output
	touch ${dd_output}_complete

	if [ "$short_test" == "y" ]; then
		total_bytes=$(( ($write_bs * 2048 * 2) + 1 ))
	else
		total_bytes=${disk_properties[size]}
	fi

	#
	# Seek if restored
	#
	resume_seek=${!initial_bytes:-0}
	if test "$resume_seek" -eq 0; then resume_seek=$write_bs; fi

	if [ "$resume_seek" -gt "$write_bs" ]; then
		resume_seek=$(($resume_seek - $write_bs))
		debug "Continuing disk write on byte $resume_seek"
		skip_initial=1
	fi
	dd_seek="seek=$resume_seek count=$(( $total_bytes - $resume_seek ))"

	#
	# Print-formatted bytes
	#
	tb_formatted=$(format_number $total_bytes)

	#
	# Type of write: zero or erase (random data)
	#
	if [ "$write_type" == "zero" ]; then
		write_type_s="Zeroing"
		write_type_v="zeroed"
		device="/dev/zero"
		dd_cmd="dd if=$device of=$disk bs=$write_bs $dd_seek $dd_flags"
	else
		write_type_s="Erasing"
		write_type_v="erased"
		device="/dev/urandom"
		pass=$(dd if=/dev/urandom bs=128 count=1 2>/dev/null | base64 -w 0)
		openssl_cmd="openssl enc -aes-256-ctr -pass pass:'${pass}' -nosalt"
		$openssl_cmd < /dev/zero > ${all_files[fifo]} 2>/dev/null &
		dd_cmd="dd if=${all_files[fifo]} of=${disk} bs=${write_bs} $dd_seek $dd_flags iflag=fullblock"
	fi

	if [ "$skip_initial" -eq 0 ]; then
		#
		# Empty the MBR partition table
		#
		debug "${write_type_s}: emptying the MBR."
		dd if=$device bs=$write_bs count=1 of=$disk >/dev/null 2>&1
		blockdev --rereadpt $disk
	fi

	#
	# running dd
	#

	$dd_cmd &> >(dd_parse_status $dd_output) & dd_pid=$!

	#
	# update elapsed time
	#
	time_elapsed $write_type && time_elapsed cycle && time_elapsed main

	#
	# if we are interrupted, kill the background zeroing of the disk.
	#
	all_files[dd_pid]=$dd_pid

	#
	# Send initial notification
	#
	if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -ge 3 ] ; then
		report_out="${write_type_s} started on $disk_serial ($disk_name).\\nDisk temperature: $(get_disk_temp $disk "$smart_type")\\n"
		send_notify "${write_type_s} started on $disk_serial ($disk_name)" "${write_type_s} started on $disk_serial ($disk_name). Cycle $cycle of ${cycles}. " "$report_out"
	fi

	local timelapse=$(( $(timer) - 20 ))
	local current_speed_time=$(timer)
	local current_speed_bytes=0
	local current_dd_time=0

	sleep 3

	while kill -0 $dd_pid &>/dev/null; do
		#
		# kill if low memory available
		#
		if ! root_free_space; then
			debug "Low memory detected, aborting ..."
			return 1
		fi

		#
		# update elapsed time
		#
		if [ "$is_paused" == "y" ]; then
			time_elapsed $write_type paused && time_elapsed cycle paused && time_elapsed main paused
		else
			time_elapsed $write_type && time_elapsed cycle && time_elapsed main
		fi

		if [ $(( $(timer) - $timelapse )) -gt $update_period ]; then
			if [ "$update_period" -lt 10 ]; then
				update_period=$(($update_period + 1))
			fi

			current_elapsed=$(time_elapsed $write_type export)

			dd_status=$(get_dd_output $dd_pid $dd_output)

			#
			# Calculate the current status
			#
			bytes_dd=$(echo -n $dd_status | cut -d '|' -f 1 | trim)
			current_dd_time=$(echo -n $dd_status | cut -d '|' -f 2 | trim)

			#
			# Ensure bytes_written is a number
			#
			if [ ! -z "${bytes_dd##*[!0-9]*}" ]; then
				bytes_written=$(($bytes_dd + $resume_seek))
				bytes_dd_current=$bytes_dd
			fi

			let percent_written=($bytes_written*100/$total_bytes)
			if [ ! -z "${bytes_written##*[!0-9]*}" ]; then
				let percent_written=($bytes_written*100/$total_bytes)
			fi

			if [ "$current_elapsed" -gt 0 ]; then
				average_speed=$(( $bytes_written	/ $current_elapsed / 1000000 ))
			fi

			if [ -z "$current_speed" ]; then
				current_speed=$average_speed
			fi

			if [ ! -z "${current_dd_time##*[!0-9.]*}" ]; then
				local bytes_diff=$(awk "BEGIN {printf \"%.2f\",${bytes_dd_current} - ${current_speed_bytes}}")
				local time_diff=$(awk "BEGIN {printf \"%.2f\",${current_dd_time} - ${current_speed_time}}")
				if [ "${time_diff//.}" -ne 0 ]; then		
					current_speed=$(awk "BEGIN {printf \"%d\",(${bytes_diff}/${time_diff}/1000000)}")
					current_speed_bytes=$bytes_dd_current
					current_speed_time=$current_dd_time
					if [ "$current_speed" -le 0 ]; then
						current_speed=$average_speed
					fi
				fi
			fi

			#
			# Save current status
			#
			diskop+=([current_op]="$write_type" [current_pos]="$bytes_written" [current_timer]=$(time_elapsed $write_type export))
			save_current_status

			local maxTimeout=15
			for prog in hdparm smartctl; do
				local prog_elapsed=$(maxExecTime "$prog" "$disk_name" "30")
				if [ "$prog_elapsed" -gt "$maxTimeout" ]; then
					paused_by["$prog"]="Pause ("$prog" run time: ${prog_elapsed}s)"
				else
					paused_by["$prog"]=n
				fi
			done

			isSync=$(ps -e -o pid,command | grep -Po "\d+ [s]ync$|\d+ [s]6-sync$" | wc -l)
			if [ "$isSync" -gt 0 ]; then
				paused_by["sync"]="Pause (sync command issued)"
			else
				paused_by["sync"]=n
			fi

			local decile=$(( $percent_written / 25 ))
			#
			# Catch up the last progress if we resumed.
			#
			if [ "$last_progress" -eq -1 ]; then
				let last_progress=decile
			fi
			if [ $decile -gt $last_progress ]; then
				debug "${write_type_s}: progress - ${percent_written}% $write_type_v @ $current_speed MB/s"
				last_progress=$decile
			fi

			timelapse=$(timer)
		else
			sleep 1
		fi

		status="Time elapsed: $(time_elapsed $write_type display) | Write speed: $current_speed MB/s | Average speed: $average_speed MB/s"
		if [ "$cycles" -gt 1 ]; then
			cycle_disp=" ($cycle of $cycles)"
		fi

		if [ -f "$pause" ]; then
			paused_by["file"]="Pause requested"
		else
			paused_by["file"]=n
		fi

		if [ -f "$queued_file" ]; then
			paused_by["queue"]="Pause requested by queue manager"
		else
			paused_by["queue"]=n
		fi

		do_pause=0
		for issuer in "${!paused_by[@]}"; do
			local pauseIssued=${paused_by[$issuer]}
			local pausedBy=${is_paused_by[$issuer]}
			local pauseReason=""
			if [ "$pauseIssued" != "n" ]; then
				pauseReason=$pauseIssued
				pauseIssued=y
			fi

			if [ "$pauseIssued" == "y" ]; then
				do_pause=$(( $do_pause + 1 ))
				if [ "$pausedBy" != "y" ]; then
					is_paused_by[$issuer]=y
					if [ "$pauseReason" != "y" ]; then
						debug "$pauseReason" 
					fi
				fi
			elif [ "$pauseIssued" == "n" ]; then
				if [ "$pausedBy" == "y" ]; then
					do_pause=$(( $do_pause - 1 ))
					is_paused_by[$issuer]=n
				fi
			fi
		done

		if [ "$do_pause" -gt 0 ] && [ "$is_paused" == "n" ]; then
			kill -TSTP $dd_pid
			is_paused=y
			save_current_status 1
			time_elapsed $write_type && time_elapsed cycle && time_elapsed main
			debug "Paused"
		fi

		if [ "$do_pause" -lt 0 ] && [ "$is_paused" == "y" ]; then
			kill -CONT $dd_pid
			is_paused=n
			time_elapsed $write_type paused && time_elapsed cycle paused && time_elapsed main paused
			debug "Resumed"
		fi

		local stat_content
		local display_content
		local display_status
		if [ "$is_paused" == "y" ]; then
			if [ -f "$queue_file" ]; then
				stat_content="${write_type_s}${cycle_disp}: QUEUED"
				display_content="${write_type_s} in progress: (${percent_written}% Done) ${bold}QUEUEDS${norm}"
				display_status="** QUEUED"
			else
				stat_content="${write_type_s}${cycle_disp}: PAUSED"
				display_content="${write_type_s} in progress: (${percent_written}% Done) ${bold}PAUSED${norm}"
				display_status="** PAUSED"
			fi
		else
			stat_content="${write_type_s}${cycle_disp}: ${percent_written}% @ $current_speed MB/s ($(time_elapsed $write_type display))"
			display_content="${write_type_s} in progress: (${percent_written}% Done)"
			display_status="** $status"
		fi

		echo -n "$disk_name|NN|${stat_content}|$$" > $stat_file

		#
		# Display refresh
		#
		if [ ! -e "/proc/${display_pid}/exe" ]; then
			display_status "$display_content" "$display_status" &
			display_pid=$!
		fi

		#
		# Detect hung dd write
		#
		if [ "$bytes_dd_current" -eq "$dd_last_bytes" -a "$is_paused" != "y" ]; then
			let dd_hang=($dd_hang + 1)
		else
			dd_last_bytes=$bytes_dd_current
			dd_hang=0
		fi

		#
		# Kill dd if hung
		#
		if [ "$dd_hang" -gt 480 ]; then
			eval "$initial_bytes='$bytes_written';"
			eval "$initial_timer='$current_elapsed';"
			while read l; do debug "${write_type_s}: dd output: ${l}"; done < <(cat "${dd_output}_complete")
			kill -9 $dd_pid
			return 2
		fi

		#
		# Send mid notification
		#
		#
		# Catch up the last progress if we resumed.
		#
		if [ "$notify_channel" -gt 0 ] && [ "$next_notify" -eq -1 ]; then
			let next_notify=decile
		fi
		local decile=$(( $percent_written / 25 ))
		if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -eq 4 ] && [ "$decile" -gt "$next_notify" ] && [ "$percent_written" -ne 100 ]; then
			disktemp="$(get_disk_temp $disk "$smart_type")"
			report_out="${write_type_s} in progress on $disk_serial ($disk_name): ${percent_written}% complete.\\n"
			report_out+="Wrote $(format_number ${bytes_written}) of ${tb_formatted} @ ${current_speed} MB/s\\n"
			report_out+="Disk temperature: ${disktemp}\\n"
			report_out+="${write_type_s} Elapsed Time: $(time_elapsed $write_type display)\\n"
			report_out+="Cycle's Elapsed Time: $(time_elapsed cycle display)\\n"
			report_out+="Total Elapsed time: $(time_elapsed main display)"
			send_notify "${write_type_s} in progress on $disk_serial ($disk_name)" "${write_type_s} in progress on $disk_serial ($disk_name): ${percent_written}% @ ${current_speed} MB/s. Temp: ${disktemp}. Cycle ${cycle} of ${cycles}." "${report_out}"
			((next_notify+=1))
		fi
	done

	wait $dd_pid
	dd_exit_code=$?
	sleep 2

	bytes_dd=$(cat $dd_output | cut -d '|' -f 1 | trim)
	if [ ! -z "${bytes_dd##*[!0-9]*}" ]; then
		bytes_written=$(( $bytes_dd + $resume_seek ))
	fi

	#
	# Wait last display refresh
	#
	for i in $(seq 30); do
		if [ ! -e "/proc/${display_pid}/exe" ]; then
			break
		fi
		sleep 1
	done

	local exit_code=0

	#
	# Check dd status
	#
	if test "$dd_exit_code" -ne 0; then
		debug "${write_type_s}: dd command failed, exit code [$dd_exit_code]."
		while read l; do debug "${write_type_s}: dd output: ${l}"; done < <(cat "${dd_output}_complete")

		diskop+=([current_op]="$write_type" [current_pos]="$bytes_written" [current_timer]=$current_elapsed )
		save_current_status 1

		exit_code=1
	fi

	#
	# Send final notification
	#
	if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -ge 3 ] ; then
		report_out="${write_type_s} finished on $disk_serial ($disk_name).\\n"
		report_out+="Wrote $(format_number ${bytes_written}) of ${tb_formatted} @ ${average_speed} MB/s\\n"
		report_out+="Disk temperature: $(get_disk_temp $disk "$smart_type").\\n"
		report_out+="${write_type_s} Elapsed Time: $(time_elapsed $write_type display).\\n"
		report_out+="Cycle's Elapsed Time: $(time_elapsed cycle display).\\n"
		report_out+="Total Elapsed time: $(time_elapsed main display)."
		send_notify "${write_type_s} finished on $disk_serial ($disk_name)" "${write_type_s} finished on $disk_serial ($disk_name). Cycle ${cycle} of ${cycles}." "$report_out"
	fi

	#
	# update elapsed time
	#
	time_elapsed $write_type && time_elapsed cycle && time_elapsed main
	current_elapsed=$(time_elapsed $write_type display)
	eval "$output='$current_elapsed @ $average_speed MB/s';$output_speed='$average_speed MB/s'"

	return $exit_code
}

read_the_disk() { 
	local average_speed bytes_dd current_speed current_elapsed count disktemp dd_cmd resume_skip report_out status tb_formatted
	local skip_b1 skip_b2 skip_b3 skip_p1 skip_p2 skip_p3 skip_p4 skip_p5 time_current read_type_s read_type_t read_type_v total_bytes
	local blkpid=${all_files[blkpid]}
	local current_speed=0
	local average_speed=0
	local bytes_read=0
	local bytes_dd_current=0
	local cycle=$cycle
	local cycles=$cycles
	local display_pid=0
	local dd_exit=${all_files[dd_exit]}
	local dd_flags_verify="iflag=nocache,count_bytes,skip_bytes"
	local dd_flags_read="conv=noerror iflag=nocache,count_bytes,skip_bytes"
	local dd_hang=0
	local dd_last_bytes=0
	local dd_output=${all_files[dd_out]}
	local dd_seek=""
	local disk=${disk_properties[device]}
	local disk_name=${disk_properties[name]}
	local disk_blocks=${disk_properties[blocks_512]}
	local disk_serial=${disk_properties[serial]}
	local last_progress=-1
	local next_notify=-1

	declare -A is_paused_by
	declare -A paused_by
	local pause=${all_files[pause]}
	local is_paused=n
	local do_pause=0

	local percent_read=0
	local update_period=0
	local queued_file=${all_files[queued]}
	local read_stress=$read_stress
	local short_test=$short_test
	local skip_initial=0
	local stat_file=${all_files[stat]}
	local verify_errors=${all_files[verify_errors]}
	local read_bs=2097152

	local verify=$1
	local read_type=$2
	local initial_bytes=$3
	local initial_timer=$4
	local output=$5
	local output_speed=$6

	#
	# start time
	#
	resume_timer=${!initial_timer}
	resume_timer=${resume_timer:-0}
	time_elapsed $read_type set $resume_timer

	#
	# Bytes to read
	#
	if [ "$short_test" == "y" ]; then
		total_bytes=$(( ($read_bs * 2048 * 2) + 1 ))
	else
		total_bytes=${disk_properties[size]}
	fi

	#
	# Skip input (bytes) if restored
	#
	resume_skip=${!initial_bytes}
	resume_skip=${resume_skip:-0}
	if test "$resume_skip" -eq 0; then resume_skip=$read_bs; fi

	if [ "$resume_skip" -gt "$read_bs" ]; then
		resume_skip=$(($resume_skip - $read_bs))
		debug "Continuing disk read from byte $resume_skip"
		skip_initial=1
	fi

	dd_skip="skip=$resume_skip count=$(( $total_bytes - $resume_skip ))"

	#
	# Type of read: Pre-Read or Post-Read
	#
	if [ "$read_type" == "preread" ]; then
		read_type_t="Pre-read in progress:"
		read_type_s="Pre-Read"
		read_type_v="read"
	elif [ "$read_type" == "postread" ]; then
		read_type_t="Post-Read in progress:"
		read_type_s="Post-Read"
		read_type_v="verified"
	else
		read_type_t="Verifying if disk is zeroed:"
		read_type_s="Verify Zeroing"
		read_type_v="verified"
		read_stress=n
	fi

	echo -n "${disk_properties[name]}|NN|Starting ${read_type_s}...|${script_pid}" > ${all_files[stat]}

	#
	# Print-formatted bytes
	#
	tb_formatted=$(format_number $total_bytes)

	#
	# Send initial notification
	#
	if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -ge 3 ] ; then
		report_out="$read_type_s started on $disk_serial ($disk_name).\\nDisk temperature: $(get_disk_temp $disk "$smart_type")\\n"
		send_notify "$read_type_s started on $disk_serial ($disk_name)" "$read_type_s started on $disk_serial ($disk_name). Cycle $cycle of ${cycles}. " "$report_out" &
	fi

	#
	# Start the disk read verify
	#
	if [ "$verify" == "verify" ]; then

		if [ "$skip_initial" -eq 0 ]; then
			#
			# Verify the beginning of the disk skipping the MBR
			#
			debug "${read_type_s}: verifying the beginning of the disk."

			dd_cmd="dd if=$disk of=/dev/null count=$(( $read_bs - 512 )) skip=512 $dd_flags_verify"

			#
			# exec dd command
			#
			$dd_cmd 2> >(dd_parse_status $dd_output) & dd_pid=$!

			wait $dd_pid
			dd_exit=$?

			#
			# Fail if error
			#
			if test $dd_exit -ne 0; then
				debug "${read_type_s}: dd command failed -> $(cat "${dd_output}_complete")"
				return 1
			fi
		fi

		#
		# update elapsed time
		#
		time_elapsed $read_type && time_elapsed cycle && time_elapsed main

		#
		# Verify the rest of the disk
		#
		debug "${read_type_s}: verifying the rest of the disk."
		dd_cmd="dd if=$disk of=/dev/null bs=$read_bs $dd_skip $dd_flags_verify"

		#
		# exec dd command
		#
		$dd_cmd 2> >(dd_parse_status $dd_output) & dd_pid=$!
	else
		if [ "$skip_initial" -eq 0 ]; then
			dd_skip="skip=0 count=$total_bytes"
			resume_skip=0
		fi

		dd_cmd="dd if=$disk of=/dev/null bs=$read_bs $dd_skip $dd_flags_read"

		#
		# exec dd command
		#
		$dd_cmd 2> >(dd_parse_status $dd_output) & dd_pid=$!
	fi

	if [ -z "$dd_pid" ]; then
		debug "${read_type_s}: dd command failed -> $(cat "${dd_output}_complete")"
		return 1
	fi

	#
	# return 1 if dd failed
	#
	if ! ps -p $dd_pid &>/dev/null; then
		debug "${read_type_s}: dd command failed -> $(cat "${dd_output}_complete")"
		return 1
	fi

	#
	# if we are interrupted, kill the background reading of the disk.
	#
	all_files[dd_pid]=$dd_pid

	local timelapse=$(( $(timer) - 20 ))
	local current_speed_time=$(timer)
	local current_speed_bytes=0
	local current_dd_time=0

	sleep 3

	#
	# Stress the disk header
	#
	if [ "$read_stress" == "y" ]; then
		#
		# read a random block
		#
		skip_b1=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($disk_blocks) ))
		dd if=$disk of=/dev/null count=1 bs=512 skip=$skip_b1 iflag=direct >/dev/null 2>&1 &
		skip_p1=$!

		#
		# read the first block
		#
		dd if=$disk of=/dev/null count=1 bs=512 iflag=direct >/dev/null 2>&1 &
		skip_p2=$!

		#
		# read a random block
		#
		skip_b2=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($disk_blocks) ))
		dd if=$disk of=/dev/null count=1 bs=512 skip=$skip_b2 iflag=direct >/dev/null 2>&1 &
		skip_p3=$!

		#
		# read the last block
		#
		dd if=$disk of=/dev/null count=1 bs=512 skip=$(($disk_blocks -1)) iflag=direct >/dev/null 2>&1 &
		skip_p4=$!

		#
		# read a random block
		#
		skip_b3=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($disk_blocks) ))
		dd if=$disk of=/dev/null count=1 bs=512 skip=$skip_b3 iflag=direct >/dev/null 2>&1 &
		skip_p5=$!
	fi

	while kill -0 $dd_pid >/dev/null 2>&1; do
		#
		# kill if low memory available
		#
		if ! root_free_space; then
			debug "Low memory detected, aborting ..."
			return 1
		fi

		#
		# update elapsed time
		#
		if [ "$is_paused" == "y" ]; then
			time_elapsed $read_type paused && time_elapsed cycle paused && time_elapsed main paused
		else
			time_elapsed $read_type && time_elapsed cycle && time_elapsed main
		fi

		if [ $(( $(timer) - $timelapse )) -gt $update_period ]; then

			if [ "$update_period" -lt 10 ]; then
				update_period=$(($update_period + 1))
			fi

			current_elapsed=$(time_elapsed $read_type export)

			dd_status=$(get_dd_output $dd_pid $dd_output)

			#
			# Calculate the current status
			#
			bytes_dd=$(echo -n "$dd_status" | cut -d '|' -f 1 | trim)
			current_dd_time=$(echo -n "$dd_status" | cut -d '|' -f 2 | trim)

			#
			# Ensure bytes_read is a number
			#
			if [ ! -z "${bytes_dd##*[!0-9]*}" ]; then
				bytes_read=$(($bytes_dd + $resume_skip))
				bytes_dd_current=$bytes_dd
				let percent_read=($bytes_read*100/$total_bytes)
			fi

			if [ $(( $current_elapsed )) -gt 0 ]; then
				average_speed=$(( $bytes_read / $current_elapsed / 1000000 ))
			fi

			if [ -z "$current_speed" ]; then
				current_speed=$average_speed
			fi

			if [ ! -z "${current_dd_time##*[!0-9.]*}" ]; then
				local bytes_diff=$(awk "BEGIN {printf \"%.2f\",${bytes_dd_current} - ${current_speed_bytes}}")
				local time_diff=$(awk "BEGIN {printf \"%.2f\",${current_dd_time} - ${current_speed_time}}")
				if [ "${time_diff//.}" -ne 0 ]; then		
					current_speed=$(awk "BEGIN {printf \"%d\",(${bytes_diff}/${time_diff}/1000000)}")
					current_speed_bytes=$bytes_dd_current
					current_speed_time=$current_dd_time
					if [ "$current_speed" -le 0 ]; then
						current_speed=$average_speed
					fi
				fi
			fi

			#
			# Save current status
			#
			diskop+=([current_op]="$read_type" [current_pos]="$bytes_read" [current_timer]=$current_elapsed )
			save_current_status

			local maxTimeout=15
			for prog in hdparm smartctl; do
				local prog_elapsed=$(maxExecTime "$prog" "$disk_name" "30")
				if [ "$prog_elapsed" -gt "$maxTimeout" ]; then
					paused_by["$prog"]="Pause ("$prog" run time: ${prog_elapsed}s)"
				else
					paused_by["$prog"]=n
				fi
			done

			isSync=$(ps -e -o pid,command | grep -Po "\d+ [s]ync$|\d+ [s]6-sync$" | wc -l)
			if [ "$isSync" -gt 0 ]; then
				paused_by["sync"]="Pause (sync command issued)"
			else
				paused_by["sync"]=n
			fi

			local decile=$(( $percent_read / 25 ))
			#
			# Catch up the last progress if we resumed.
			#
			if [ "$last_progress" -eq -1 ]; then
				let last_progress=decile
			fi
			if [ $decile -gt $last_progress ]; then
				debug "${read_type_s}: progress - ${percent_read}% $read_type_v @ $current_speed MB/s"
				last_progress=$decile
			fi

			timelapse=$(timer)
		else
			sleep 1
		fi

		status="Time elapsed: $(time_elapsed $read_type display) | Current speed: $current_speed MB/s | Average speed: $average_speed MB/s"
		if [ "$cycles" -gt 1 ]; then
			cycle_disp=" ($cycle of $cycles)"
		fi

		if [ -f "$pause" ]; then
			paused_by["file"]="Pause requested"
		else
			paused_by["file"]=n
		fi

		if [ -f "$queued_file" ]; then
			paused_by["queue"]="Pause requested by queue manager"
		else
			paused_by["queue"]=n
		fi

		do_pause=0
		for issuer in "${!paused_by[@]}"; do
			local pauseIssued=${paused_by[$issuer]}
			local pausedBy=${is_paused_by[$issuer]}
			local pauseReason=""
			if [ "$pauseIssued" != "n" ]; then
				pauseReason=$pauseIssued
				pauseIssued=y
			fi

			if [ "$pauseIssued" == "y" ]; then
				do_pause=$(( $do_pause + 1 ))
				if [ "$pausedBy" != "y" ]; then
					is_paused_by[$issuer]=y
					if [ "$pauseReason" != "y" ]; then
						debug "$pauseReason" 
					fi
				fi
			elif [ "$pauseIssued" == "n" ]; then
				if [ "$pausedBy" == "y" ]; then
					do_pause=$(( $do_pause - 1 ))
					is_paused_by[$issuer]=n
				fi
			fi
		done

		if [ "$do_pause" -gt 0 ] && [ "$is_paused" == "n" ]; then
			kill -TSTP $dd_pid
			is_paused=y
			save_current_status 1
			time_elapsed $read_type && time_elapsed cycle && time_elapsed main
			debug "Paused"
		fi

		if [ "$do_pause" -lt 0 ] && [ "$is_paused" == "y" ]; then
			kill -CONT $dd_pid
			is_paused=n
			time_elapsed $read_type paused && time_elapsed cycle paused && time_elapsed main paused
			debug "Resumed"
		fi

		local stat_content
		local display_content
		local display_status
		if [ "$is_paused" == "y" ]; then
			if [ -f "$queue_file" ]; then
				stat_content="${read_type_s}${cycle_disp}: QUEUED"
				display_content="${read_type_t} (${percent_read}% Done) ${bold}QUEUED${norm}"
				display_status="** QUEUED"
			else
				stat_content="${read_type_s}${cycle_disp}: PAUSED"
				display_content="${read_type_t} (${percent_read}% Done) ${bold}PAUSED${norm}"
				display_status="** PAUSED"
			fi
		else
			stat_content="${read_type_s}${cycle_disp}: ${percent_read}% @ ${current_speed} MB/s ($(time_elapsed $read_type display))"
			display_content="${read_type_t} (${percent_read}% Done)"
			display_status="** ${status}"
		fi
		echo -n "$disk_name|NN|${stat_content}|$$" > $stat_file

		#
		# Display refresh
		#
		if [ ! -e "/proc/${display_pid}/exe" ]; then
			display_status "${display_content}" "${display_status}" &
			display_pid=$!
		fi

		#
		# Detect hung dd read
		#
		if [ "$bytes_dd_current" == "$dd_last_bytes" -a "$is_paused" != "y" ]; then
			dd_hang=$(($dd_hang + 1))
		else
			dd_hang=0
			dd_last_bytes=$bytes_dd_current
		fi

		#
		# Kill dd if hung
		#
		if [ "$dd_hang" -gt 480 ]; then
			eval "$initial_bytes='"$bytes_read"';"
			eval "$initial_timer='$(time_elapsed $read_type export)';"
			while read l; do debug "${read_type_s}: dd output: ${l}"; done < <(cat "${dd_output}_complete")

			kill -9 $dd_pid
			return 2
		fi

		#
		# Send mid notification
		#
		local decile=$(( $percent_read / 25 ))
		#
		# Catch up the last progress if we resumed.
		#
		if [ "$notify_channel" -gt 0 ] && [ "$next_notify" -eq -1 ]; then
			let next_notify=decile
		fi
		if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -eq 4 ] && [ "$decile" -gt "$next_notify" ] && [ "$percent_read" -ne 100 ]; then
			disktemp="$(get_disk_temp $disk "$smart_type")"
			report_out="${read_type_s} in progress on $disk_serial ($disk_name): ${percent_read}% complete.\\n"
			report_out+="Read $(format_number ${bytes_read}) of ${tb_formatted} @ ${current_speed} MB/s\\n"
			report_out+="Disk temperature: ${disktemp}\\n"
			report_out+="${read_type_s} Elapsed Time: $(time_elapsed $read_type display).\\n"
			report_out+="Cycle's Elapsed Time: $(time_elapsed cycle display).\\n"
			report_out+="Total Elapsed time: $(time_elapsed main display)."
			send_notify "$read_type_s in progress on $disk_serial ($disk_name)" "${read_type_s} in progress on $disk_serial ($disk_name): ${percent_read}% @ ${current_speed} MB/s. Temp: ${disktemp}. Cycle ${cycle} of ${cycles}." "${report_out}" &
			((next_notify+=1))
		fi
	done

	wait $dd_pid
	dd_exit_code=$?
	sleep 2

	if [ "$read_stress" == "y" ]; then
		#
		# Wait for random blocks to be completed
		#
		kill -0 $skip_p1 2>/dev/null && wait $skip_p1
		kill -0 $skip_p2 2>/dev/null && wait $skip_p2
		kill -0 $skip_p3 2>/dev/null && wait $skip_p3
		kill -0 $skip_p4 2>/dev/null && wait $skip_p4
		kill -0 $skip_p5 2>/dev/null && wait $skip_p5
	fi

	#
	# Wait last display refresh
	#
	for i in $(seq 30); do
		if ! kill -0 $display_pid &>/dev/null; then
			break
		fi
		sleep 1
	done

	bytes_dd=$(cat $dd_output | cut -d '|' -f 1 | trim)
	if [ ! -z "${bytes_dd##*[!0-9]*}" ]; then
		bytes_read=$(( $bytes_dd + $resume_skip ))
	fi

	debug "${read_type_s}: elapsed time - $(time_elapsed $read_type display)"

	if test "$dd_exit_code" -ne 0; then
		debug "${read_type_s}: dd command failed, exit code [$dd_exit_code]."
		while read l; do debug "${read_type_s}: dd output: ${l}"; done < <(cat "${dd_output}_complete")

		diskop+=([current_op]="$read_type" [current_pos]="$bytes_read" [current_timer]=$(time_elapsed $read_type display) )
		save_current_status 1

		return 1
	fi

	#
	# Send final notification
	#
	if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -ge 3 ] ; then
		report_out="$read_type_s finished on $disk_serial ($disk_name).\\n"
		report_out+="Read $(format_number $bytes_read) of $tb_formatted @ $average_speed MB/s\\n"
		report_out+="Disk temperature: $(get_disk_temp $disk "$smart_type").\\n"
		report_out+="$read_type_s Elapsed Time: $(time_elapsed $read_type display).\\n"
		report_out+="Cycle's Elapsed Time: $(time_elapsed cycle display).\\n"
		report_out+="Total Elapsed time: $(time_elapsed main display)."
		send_notify "$read_type_s finished on $disk_serial ($disk_name)" "$read_type_s finished on $disk_serial ($disk_name). Cycle ${cycle} of ${cycles}." "$report_out" &
	fi

	#
	# update elapsed time
	#
	time_elapsed $read_type && time_elapsed cycle && time_elapsed main
	current_elapsed=$(time_elapsed $read_type display)
	eval "$output='$current_elapsed @ $average_speed MB/s';$output_speed='$average_speed MB/s'"

	return 0
}

draw_canvas(){
	local start=$1 height=$2 width=$3 brick="${canvas[brick]}" c
	let iniline=($height + $start)
	for line in $(seq $start $iniline ); do
		c+=$(tput cup $line 0 && echo -n $brick)
		c+=$(tput cup $line $width && echo -n $brick)
	done
	for col in $(seq $width); do
		c+=$(tput cup $start $col && echo -n $brick)
		c+=$(tput cup $iniline $col && echo -n $brick)
		c+=$(tput cup $(( $iniline - 2 )) $col && echo -n $brick)
	done
	echo -n $c
}

display_status(){
	local max=$max_steps
	local cycle=$cycle
	local cycles=$cycles
	local current=$1
	local status=$2
	local stat=""
	local width="${canvas[width]}"
	local height="${canvas[height]}"
	local smart_output="${all_files[smart_out]}"
	local wpos=4
	local hpos=1
	local skip_formatting=$3
	local step=1
	local out="${all_files[dir]}/display_out"

	eval "local -A prev=$(array_content display_step)"
	eval "local -a prev_keys=$(array_content display_step_keys)"
	eval "local -A title=$(array_content display_title)"
	eval "local -a title_keys=$(array_content display_title_keys)"

	if [ "$skip_formatting" != "y" ]; then
		tput reset > $out
	fi

	if [ -z "${canvas[info]}" ]; then
		append canvas "info" "$(draw_canvas $hpos $height $width)"
	fi
	echo -n "${canvas[info]}" >> $out

	for (( i = 0; i <= ${#title_keys[@]}; i++ )); do
		line=${title[$i]}
		line_num=$(echo -e -n "$line"|tr -d "${bold}"|tr -d "${norm}"|tr -d "${ul}"|tr -d "${noul}"|wc -m)
		tput cup $(( $i + 1 + $hpos )) $(( $width/2 - $line_num/2 )) >> $out
		echo -e -n "$line" >> $out
	done

	l=$((${#title[@]} + 2 + $hpos))

	for i in "${!prev_keys[@]}"; do
		if [ -n "${prev[$i]}" ]; then
			line=${prev[$i]}
			stat=""
			if [ "$(echo -e -n "$line" | grep -c '|')" -gt "0" -a "$skip_formatting" != "y" ]; then
				stat=$(trim $(echo -e -n "$line" | cut -d'|' -f2))
				line=$(trim $(echo -e -n "$line" | cut -d'|' -f1))
			fi
			if [ -n "$max" ]; then
				line="Step $step of $max - $line"
			fi
			tput cup $l $wpos >> $out
			echo -e -n "$line" >> $out
			if [ -n "$stat" ]; then
				clean_stat=$(echo -e -n "$stat"|sed -e "s|\*\{3\}\([^\*]*\)\*\{3\}|\1|g"|sed -e "s|\#\{3\}\([^\#]*\)\#\{3\}|\1|g")
				stat_num=${#clean_stat}
				if [ "$skip_formatting" != "y" ]; then
					stat=$(echo -e -n "$stat"|sed -e "s|\*\{3\}\([^\*]*\)\*\{3\}|${bold}\1${norm}|g"|sed -e "s|\#\{3\}\([^\#]*\)\#\{3\}|${ul}\1${noul}|g")
				fi
				tput cup $l $(($width - $stat_num - $wpos )) >> $out
				echo -e -n "$stat" >> $out
			fi
			let "l+=1"
			let "step+=1"
		fi
	done

	if [ -n "$current" ]; then
		line=$current;
		stat=""
		if [ "$(echo -e -n "$line" | grep -c '|')" -gt "0" -a "$skip_formatting" != "y" ]; then
			stat=$(echo -e -n "$line" | cut -d'|' -f2)
			line=$(echo -e -n "$line" | cut -d'|' -f1)
		fi
		if [ -n "$max" ]; then
			line="Step $step of $max - $line"
		fi
		tput cup $l $wpos >> $out
		echo -e -n "$line" >> $out
		if [ -n "$stat" ]; then
			clean_stat=$(echo -e -n "$stat" | sed -e "s|\*\{3\}\([^\*]*\)\*\{3\}|\1|g"|sed -e "s|\#\{3\}\([^\#]*\)\#\{3\}|\1|g")
			stat_num=${#clean_stat}
			if [ "$skip_formatting" != "y" ]; then
				stat=$(echo -e -n "$stat" | sed -e "s|\*\{3\}\([^\*]*\)\*\{3\}|${bold}\1${norm}|g"|sed -e "s|\#\{3\}\([^\#]*\)\#\{3\}|${ul}\1${noul}|g")
			fi
			tput cup $l $(($width - $stat_num - $wpos )) >> $out
			echo -e -n "$stat" >> $out
		fi
		let "l+=1"
	fi
	if [ -n "$status" ]; then
		tput cup $(($height+$hpos-4)) $wpos >> $out
		echo -e -n "$status" >> $out
	fi
	local elapsed_main=$(time_elapsed main display)
	footer="Total elapsed time: $elapsed_main"
	local elapsed_cycle=$(time_elapsed cycle display)
	if [[ -n "$elapsed_cycle" ]]; then
		footer="Cycle elapsed time: $elapsed_cycle | $footer"
	fi
	tput cup $(( $height + $hpos - 1)) $(( $wpos*2 )) >> $out
	echo -e -n "$footer" >> $out
	if [ -f "$smart_output" ]; then
		init=$(( $hpos + $height + 2 ))
		if [ -z "${canvas[smart]}" ]; then
			append canvas "smart" "$(draw_canvas $init $(($height + 1)) $width)"
		fi

		echo -n "${canvas[smart]}" >> $out

		line="S.M.A.R.T. Status (device type: $type)"
		let l=($init + 1)
		tput cup $(($l)) $(( $wpos )) >> $out
		echo -e -n "${ul}$line${noul}" >> $out
		let "l+=2"
		while read line; do
			tput cup $l $wpos >> $out
			echo -e -n "$line" >> $out
			let "l+=1"
		done < <(head -n -1 "$smart_output")
		tput cup $(( $init + $height)) $wpos >> $out
		tail -n 1 "$smart_output" >> $out
		tput cup $(( $init + $height )) $(( $wpos*2 )) >> $out
		current_datetime=$(date "+%B %d, %Y at %H:%M:%S")
		echo -e -n "Report genereated on: $current_datetime" >> $out
		tput cup $(( $init + $height )) $width >> $out
		tput cup $(( $init + $height + 2 )) 0 >> $out
	else
		tput cup $(( $height + $hpos )) $width >> $out
		tput cup $(( $height + $hpos + 2 )) 0 >> $out
	fi
	cat $out
}

ask_preclear(){
	local line
	local wpos=4
	local hpos=0
	local max=""
	local width="${canvas[width]}"
	local height="${canvas[height]}"
	eval "local -A title=$(array_content display_title)"
	eval "local -A disk_info=$(array_content disk_properties)"

	tput reset

	draw_canvas $hpos $height $width

	for (( i = 0; i <= ${#title[@]}; i++ )); do
		line=${title[$i]}
		line_num=$(echo "$line"|tr -d "${bold}"|tr -d "${norm}"|tr -d "${ul}"|tr -d "${noul}"|wc -m)
		tput cup $(($i + 1 + $hpos)) $(( $width/2 - $line_num/2	))
		echo -n "$line"
	done

	l=$((${#title[@]} + 2 + $hpos))

	if [ -n "${disk_info[family]}" ]; then
		tput cup $l $wpos && echo -n "Model Family: 	${disk_info[family]}"
		let "l+=1"
	fi
	if [ -n "${disk_info[model]}" ]; then
		tput cup $l $wpos && echo -n "Device Model:	${disk_info[model]}"
		let "l+=1"
	fi
	if [ -n "${disk_info[serial]}" ]; then
		tput cup $l $wpos && echo -n "Serial Number:	${disk_info[serial]}"
		let "l+=1"
	fi
	if [ -n "${disk_info[size_human]}" ]; then
		tput cup $l $wpos && echo -n "User Capacity:	${disk_info[size_human]}"
		let "l+=1"
	fi
	if [ -n "${disk_info[firmware]}" ]; then
		tput cup $l $wpos && echo -n "Firmware:		${disk_info[firmware]}"
		let "l+=1"
	fi
	if [ -n "${disk_info[device]}" ]; then
		tput cup $l $wpos && echo -n "Disk Device:	${disk_info[device]}"
	fi

	tput cup $(($height - 4)) $wpos && echo -n "Answer ${bold}Yes${norm} to continue:"
	tput cup $(($height - 4)) $(($wpos+24)) && read answer

	tput cup $(( $height - 1)) $wpos; 

	if [[ "$answer" == "Yes" ]]; then
		tput cup $(( $height + 2 )) 0
		return 0
	else
		echo -n "The disk will ${bold}NOT${norm} be precleared."
		tput cup $(( $height + 24 )) 0
		exit 2
	fi
}

save_smart_info() {
	local device=$1
	local type=$2
	local name=$3
	local valid_attributes=" 5 9 183 184 187 190 194 196 197 198 199 "
	local valid_temp=" 190 194 "
	local smart_file="${all_files[smart_prefix]}${name}"
	local found_temp=n
	cat /dev/null > $smart_file

	while read line; do
		attr=$(echo "$line" | cut -d'|' -f1)
		if [[ $valid_attributes =~ [[:space:]]$attr[[:space:]] ]]; then
			if [[ $valid_temp =~ [[:space:]]$attr[[:space:]] ]]; then
				if [[ $found_temp != "y" ]]; then
					echo "$line" >> $smart_file
					found_temp=y
				fi
			else
				echo "$line" >> $smart_file
			fi
		fi
	done < <(timeout -s 9 30 smartctl --all $type $device 2>/dev/null | sed -n "/ATTRIBUTE_NAME/,/^$/p" | \
					grep -v "ATTRIBUTE_NAME" | grep -v "^$" | awk '{ print $1 "|" $2 "|" $10}')
}

compare_smart() {
	local initial="${all_files[smart_prefix]}$1"
	local current="${all_files[smart_prefix]}$2"
	local final="${all_files[smart_final]}$4"
	local title=$3
	if [ -e "$final" -a -n "$title" ]; then
		sed -i " 1 s/$/|$title/" $final
	elif [ ! -f "$current" ]; then
		echo "ATTRIBUTE|INITIAL" > $final
		current=$initial
	else
		echo "ATTRIBUTE|INITIAL|$title" > $final
	fi

	while read line; do
		attr=$(echo "$line" | cut -d'|' -f1)
		name=$(echo "$line" | cut -d'|' -f2)
		name="${name}"
		nvalue=$(echo "$line" | cut -d'|' -f3)
		ivalue=$(cat $initial| grep "^${attr}"|cut -d'|' -f3)
		if [ "$(cat $final 2>/dev/null|grep -c "$name")" -gt "0" ]; then
			sed -i "/^$name/ s/$/|${nvalue}/" $final
		else
			echo "${name}|${ivalue}" >> $final
		fi
	done < <(cat $current)
}

output_smart() {
	local device=$1
	local type=$2
	local final="${all_files[smart_final]}$3"
	local output="${all_files[smart_out]}$4"
	local msg
	local nfinal="${final}_$(( $RANDOM * 19318203981230 + 40 ))"
	cp -f "$final" "$nfinal"
	sed -i " 1 s/$/|STATUS/" $nfinal
	local status=$(timeout -s 9 30 smartctl --attributes $type $device 2>/dev/null | sed -n "/ATTRIBUTE_NAME/,/^$/p" | \
					grep -v "ATTRIBUTE_NAME" | grep -v "^$" | awk '{print $1 "-" $2 "|" $9 }')
	while read line; do
		local attr=$(echo "$line" | cut -d'|' -f1)
		local inival=$(echo "$line" | cut -d'|' -f2)
		local lasval=$(echo "$line" | grep -o '[^|]*$')
		let diff=($((`cut -d h -f1 <<< ${lasval}`)) - $((`cut -d h -f1 <<< ${inival}`)))
		if [ "$diff" -gt "0" ]; then
			msg="Up $diff"
		elif [ "$diff" -lt "0" ]; then
			diff=$(echo "$diff" | sed 's/-//g')
			msg="Down $diff"
		else
			msg="-"
		fi
		local stat=$(echo "$status" | grep -Po "${attr}[^\s]*")
		if [[ $stat =~ FAILING_NOW ]]; then
			msg="$msg|->FAILING NOW!<-"
		elif [[ $stat =~ In_the_past ]]; then
			msg="$msg|->Failed in Past<-"
		fi
		sed -i "/^$attr/ s/$/|${msg}/" $nfinal
	done < <(cat $nfinal | tail -n +2)
	cat $nfinal | column -t -s '|' -o '	'> $output
	echo "" >> $output
#	timeout -s 9 30 smartctl --health $type $device | sed -n '/SMART DATA SECTION/,/^$/p'| tail -n +2 | head -n 1 >> $output
	rm $nfinal
}

get_disk_temp() {
	local device=$1
	local type=$2
	local valid_temp=" 190 194 "
	local temp=0
	if [ "$disable_smart" == "y" ]; then
		echo -n "n/a"
		return 0
	fi

	while read line; do
		attr=$(echo -n "$line" | cut -d'|' -f1)
		if [[ $valid_temp =~ [[:space:]]$attr[[:space:]] ]]; then
			echo -n "$(echo -n "$line" | cut -d'|' -f3) C"
			return 0
		fi
	done < <(timeout -s 9 30 smartctl --attributes $type $device 2>/dev/null | sed -n "/ATTRIBUTE_NAME/,/^$/p" | \
					grep -v "ATTRIBUTE_NAME" | grep -v "^$" | awk '{ print $1 "|" $2 "|" $10}')
	echo -n "n/a"
}

debug_smart()
{
	local disk=$1
	local smart=$2
	local id=$RANDOM
	[ "$disable_smart" != "y" ] && compare_smart	"cycle_initial_start" "" "" "_${id}"
	[ "$disable_smart" != "y" ] && save_smart_info	$disk "$smart" "${id}"
	[ "$disable_smart" != "y" ] && compare_smart	"cycle_initial_start" "${id}" "NOW" "_${id}"
	[ "$disable_smart" != "y" ] && output_smart		$disk "$smart" "_${id}" "_${id}"
	if [ -f "${all_files[smart_out]}_${id}" ]; then
		debug "S.M.A.R.T.: $3"
		debug "S.M.A.R.T.:"
		while read l; do 
			debug "S.M.A.R.T.: ${l}"; 
		done < <(cat "${all_files[smart_out]}_${id}")
	fi
}

do_exit()
{
	trap '' ERR EXIT 1 2 3 9 15;
	while [ -f "${all_files[wait]}" ]; do 
		sleep 0.1; 
	done
	
	dd_pid=${all_files[dd_pid]}

	case "$1" in
		0)
			debug "SIG${2} received, exiting ..."
			rm -f "${all_files[pid]}" "${all_files[pause]}" "${all_files[queued]}"
			save_current_status 1;
			kill -9 $dd_pid 2>/dev/null
			exit 0
			;;
		1)
			debug 'error encountered, exiting ...'
			kill -9 $dd_pid 2>/dev/null
			echo "${disk_properties[name]}|NY|Error encountered, please check the report|$$" > ${all_files[stat]}
			rm -f "${all_files[resume_file]}"
			rm -f "${all_files[resume_temp]}"
			sleep 2
			rm -rf ${all_files[dir]};
			exit 1
			;;
		*)
			rm -f "${all_files[resume_file]}"
			rm -f "${all_files[resume_temp]}"
			rm -rf ${all_files[dir]};
			exit 0
			;;
	esac
}

trap_with_arg() {
		func="$1" ; shift
		for sig ; do
				trap "$func $sig" "$sig"
		done
}

is_current_op() {
	if [ -n "$current_op" ] && [ "$current_op" == "$1" ]; then
		current_op=""
		return 0
	elif [ -z "$current_op" ]; then
		return 0
	else
		return 1
	fi
}

keep_pid_updated(){
	while [ -e "${all_files[dir]}" ]; do
		echo "$script_pid" > "${all_files[pid]}"
		sleep 2
	done
}

######################################################
##													##
##					INITIAL SETUP					##
##													##
######################################################
#
# PID
#
script_pid=$BASHPID

#
# Serial
#
cmd_disk=""
for arg in "$@"; do
	if [ -b "$arg" ]; then
		cmd_disk=$arg
		attrs=$(udevadm info --query=property --name="${arg}")
		serial_number=$(echo -e "$attrs" | awk -F'=' '/ID_SCSI_SERIAL/{print $2}')
		if [ -z "$serial_number" ]; then
			serial_number=$(echo -e "$attrs" | awk -F'=' '/ID_SERIAL_SHORT/{print $2}')
		fi
		break
	else
		serial_number=""
	fi
done
#
# Log prefix
#
if [ -n "$serial_number" ]; then
	syslog_prefix="preclear_disk_${serial_number}"
	log_prefix="preclear_disk_${serial_number}_${script_pid}:"
elif [[ -n "$cmd_disk" ]]; then
	syslog_prefix="preclear_disk_${cmd_disk}"
	log_prefix="preclear_disk_${script_pid}:"
else
	syslog_prefix="preclear_disk"
	log_prefix="preclear_disk_${script_pid}:"
fi

#
# Redirect errors to log
#
exec 2> >(while read err; do debug "${err}"; echo "${err}"; done; >&2)

#
# Let's make sure some features are supported by BASH
#
BV=$(echo $BASH_VERSION|tr '.' "\n"|grep -Po "^\d+"|xargs printf "%.2d\n"|tr -d '\040\011\012\015')
if [ "$BV" -lt "040253" ]; then
	echo -e "Sorry, your BASH version isn't supported.\nThe minimum required version is 4.2.53.\nPlease update."
	debug "Sorry, your BASH version isn't supported.\nThe minimum required version is 4.2.53.\nPlease update."
	exit 2
fi

#
# Let's verify all dependencies
#
for dep in cat awk basename blockdev comm date dd find fold getopt grep kill openssl printf readlink seq sort sum tac tmux todos tput udevadm xargs; do
	if ! type $dep >/dev/null 2>&1 ; then
		echo -e "The following dependency isn't met: [$dep]. Please install it and try again."
		debug "The following dependency isn't met: [$dep]. Please install it and try again."
		exit 1
	fi
done

######################################################
##													##
##					PARSE OPTIONS					##
##													##
######################################################
#
#Defaut values
#
all_timer_diff=0
cycle_timer_diff=0
main_elapsed_time=0
cycle_elapsed_time=0
command=$(echo "$0 $@")
read_stress=y
cycles=1
append display_step ""
erase_disk=n
erase_preclear=n
initial_bytes=0
verify_mbr_only=n
refresh_period=30
append canvas 'width'	'100'
append canvas 'height' '14'
append canvas 'brick'	'#'
smart_type=auto
notify_channel=0
notify_freq=0
opts_long="frequency:,notify:,skip-preread,skip-postread,read-size:,write-size:,read-blocks:,test,no-stress,list,"
opts_long+="cycles:,signature,verify,no-prompt,version,preclear-only,format-html,erase,erase-clear,load-file:,unassigned"

OPTS=$(getopt -o f:n:sSr:w:b:tdlc:ujvomera:U \
			--long $opts_long -n "$(basename $0)" -- "$@")

if [ "$?" -ne "0" ]; then
	exit 1
fi

eval set -- "$OPTS"
# (set -o >/dev/null; set >/tmp/.init)
while true ; do
	case "$1" in
		-f|--frequency)			is_numeric notify_freq		"$1" "$2"; shift 2;;
		-n|--notify)			is_numeric notify_channel	"$1" "$2"; shift 2;;
		-s|--skip-preread)		skip_preread=y;				shift 1;;
		-S|--skip-postread)		skip_postread=y;			shift 1;;
		-r|--read-size)			is_numeric read_size		"$1" "$2"; shift 2;;
		-w|--write-size)		is_numeric write_size		"$1" "$2"; shift 2;;
		-b|--read-blocks)		is_numeric read_blocks		"$1" "$2"; shift 2;;
		-t|--test)				short_test=y;				shift 1;;
		-d|--no-stress)			read_stress=n;				shift 1;;
		-l|--list)				list_device_names;			exit 0;;
		-c|--cycles)			is_numeric cycles			"$1" "$2"; shift 2;;
		-u|--signature)			verify_disk_mbr=y;			shift 1;;
		-p|--verify)			verify_disk_mbr=y;			verify_zeroed=y; shift 1;;
		-j|--no-prompt)			no_prompt=y;				shift 1;;
		-v|--version)			echo "$0 version: $version"; exit 0;;
		-o|--preclear-only)		write_disk_mbr=y;			shift 1;;
		-m|--format-html)		format_html=y;				shift 1;;
		-e|--erase)				erase_disk=y;				shift 1;;
		-r|--erase-clear)		erase_preclear=y;			shift 1;;
		-a|--load-file)			load_file="$2";				shift 2;;
		-U|--unassigned)		list_unassigned_disks;		exit 0;;

		--) shift ; break ;;
		* ) echo "Internal error!" ; exit 1 ;;
	esac
done

if [ ! -b "$1" ]; then
	echo "Disk not set, please verify the command arguments."
	debug "Disk not set, please verify the command arguments."
	exit 1
fi

theDisk=$(echo $1 | trim)

debug "Preclear Disk Version: ${version}"

#
# Restoring session or exit if it's not possible
#
if [ -f "$load_file" ] && $(bash -n "$load_file"); then
	debug "Restoring previous instance of preclear"
	. "$load_file"
	if [ "$all_timer_diff" -gt 0 ]; then
		main_elapsed_time=$all_timer_diff
		cycle_elapsed_time=$cycle_timer_diff
	fi
	if [ "$main_elapsed_time" -eq 0 ]; then
		debug "Resume failed, please start a new instance of preclear"
		echo -n "$(basename $theDisk)|NN|Resume failed!|${script_pid}" > "/tmp/preclear/preclear_stat_$(basename $theDisk)"
		exit 1
	fi
fi

append arguments 'notify_freq'		"$notify_freq"
append arguments 'notify_channel'	"$notify_channel"
append arguments 'skip_preread'		"$skip_preread"
append arguments 'skip_postread'	"$skip_postread"
append arguments 'read_size'		"$read_size"
append arguments 'write_size'		"$write_size"
append arguments 'read_blocks'		"$read_blocks"
append arguments 'short_test'		"$short_test"
append arguments 'read_stress'		"$read_stress"
append arguments 'cycles'			"$cycles"
append arguments 'verify_disk_mbr'	"$verify_disk_mbr"
append arguments 'verify_zeroed'	"$verify_zeroed"
append arguments 'no_prompt'		"$no_prompt"
append arguments 'write_disk_mbr'	"$write_disk_mbr"
append arguments 'format_html'		"$format_html"
append arguments 'erase_disk'		"$erase_disk"
append arguments 'erase_preclear'	"$erase_preclear"

# diff /tmp/.init <(set -o >/dev/null; set)
# exit 0

######################################################
##													##
##			SET DEFAULT PROGRAM VARIABLES			##
##													##
######################################################
#
# Operation variables
#
append diskop 'current_op' ""
append diskop 'current_pos' ""
append diskop 'current_timer' ""

#
# Disk properties
#
append disk_properties 'device'			"$theDisk"
append disk_properties 'size'			$(blockdev --getsize64 ${disk_properties[device]} 2>/dev/null)
append disk_properties 'block_sz'		$(blockdev --getpbsz ${disk_properties[device]} 2>/dev/null)
append disk_properties 'blocks'			$(( ${disk_properties[size]} / ${disk_properties[block_sz]} ))
append disk_properties 'blocks_512'		$(blockdev --getsz ${disk_properties[device]} 2>/dev/null)
append disk_properties 'name'			$(basename ${disk_properties[device]} 2>/dev/null)
append disk_properties 'parts'			$(grep -c "${disk_properties[name]}[0-9]" /proc/partitions 2>/dev/null)
append disk_properties 'serial_long'	$(udevadm info --query=property --name ${disk_properties[device]} 2>/dev/null|grep -Po 'ID_SERIAL=\K.*')
append disk_properties 'serial'			$(udevadm info --query=property --name ${disk_properties[device]} 2>/dev/null|grep -Po 'ID_SERIAL_SHORT=\K.*')
append disk_properties 'smart_type'		"default"

disk_controller=$(udevadm info --query=property --name ${disk_properties[device]} | grep -Po 'DEVPATH.*0000:\K[^/]*')
append disk_properties 'controller'	"$(lspci | grep -Po "${disk_controller}[^:]*: \K.*")"

if [ "${disk_properties[parts]}" -gt 0 ]; then
	for part in $(seq 1 "${disk_properties[parts]}" ); do
		let "parts+=($(blockdev --getsize64 ${disk_properties[device]}${part} 2>/dev/null) / ${disk_properties[block_sz]})"
	done
	append disk_properties 'start_sector' $(( ${disk_properties[blocks]} - $parts ))
else
	append disk_properties 'start_sector' "0"
fi

#
# Disable read_stress if preclearing a SSD
#
eval $( lsblk -nbP --nodeps -o ROTA $theDisk )
if [ "$ROTA" -eq 0 ]; then 
	debug "Disk ${theDisk} is a SSD, disabling head stress test." 
	read_stress=n
fi

#
# Test suitable device type for SMART, and disable it if not found.
#
disable_smart=y
for type in "" scsi ata auto sat,auto sat,12 usbsunplus usbcypress usbjmicron usbjmicron,x test "sat -T permissive" usbjmicron,p sat,16; do
	if [ -n "$type" ]; then
		type="-d $type"
	fi
	smartInfo=$(timeout -s 9 30 smartctl --all $type "$theDisk" 2>/dev/null)
	if [[ $smartInfo == *"START OF INFORMATION SECTION"* ]]; then

		smart_type=$type

		if [ -z "$type" ]; then
			type='default'
		fi

		append disk_properties 'smart_type' "$type"

		if [[ $smartInfo == *"Reallocated_Sector_Ct"* ]]; then
			disable_smart=n
		fi
		
		while read line ; do
			if [[ $line =~ Model\ Family:\ (.*) ]]; then
				append disk_properties 'family' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			elif [[ $line =~ Device\ Model:\ (.*) ]]; then
				append disk_properties 'model' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			elif [[ $line =~ User\ Capacity:\ (.*) ]]; then
				append disk_properties 'size_human' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			elif [[ $line =~ Firmware\ Version:\ (.*) ]]; then
				append disk_properties 'firmware' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			elif [[ $line =~ Vendor:\ (.*) ]]; then
				append disk_properties 'vendor' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			elif [[ $line =~ Product:\ (.*) ]]; then
				append disk_properties 'product' "$(echo "${BASH_REMATCH[1]}"|xargs)"
			fi
		done < <(echo -n "$smartInfo")

		if [ -z "${disk_properties[model]}" ] && [ -n "${disk_properties[vendor]}" ] && [ -n "${disk_properties[product]}" ]; then
			append disk_properties 'model' "${disk_properties[vendor]} ${disk_properties[product]}"
		fi

		append disk_properties 'temp' "$(get_disk_temp $theDisk "$smart_type")"

		break
	fi
done

debug "Disk size: ${disk_properties[size]}"
debug "Disk blocks: ${disk_properties[blocks]}"
debug "Blocks (512 bytes): ${disk_properties[blocks_512]}"
debug "Block size: ${disk_properties[block_sz]}"
debug "Start sector: ${disk_properties[start_sector]}"

#
# Used files
#
append all_files 'dir'				"/tmp/.preclear/${disk_properties[name]}"
append all_files 'dd_out'			"${all_files[dir]}/dd_output"
append all_files 'dd_exit'			"${all_files[dir]}/dd_exit_code"
append all_files 'dd_pid'			"${all_files[dir]}/dd_pid"
append all_files 'blkpid'			"${all_files[dir]}/blkpid"
append all_files 'fifo'				"${all_files[dir]}/fifo"
append all_files 'pause'			"${all_files[dir]}/pause"
append all_files 'queued'			"${all_files[dir]}/queued"
append all_files 'verify_errors'	"${all_files[dir]}/verify_errors"
append all_files 'pid'				"${all_files[dir]}/pid"
append all_files 'stat'				"/tmp/preclear/preclear_stat_${disk_properties[name]}"
append all_files 'smart_prefix'		"${all_files[dir]}/smart_"
append all_files 'smart_final'		"${all_files[dir]}/smart_final"
append all_files 'smart_out'		"${all_files[dir]}/smart_out"
append all_files 'form_out'			"${all_files[dir]}/form_out"
append all_files 'resume_file'		"/boot/preclear_reports/${disk_properties[serial]}.resume"
append all_files 'resume_temp'		"/tmp/.preclear/${disk_properties[serial]}.resume"
append all_files 'wait'				"${all_files[dir]}/wait"

mkdir -p "${all_files[dir]}"

trap_with_arg "do_exit 0" INT TERM EXIT SIGKILL

if [ ! -p "${all_files[fifo]}" ]; then
	mkfifo "${all_files[fifo]}" || exit
fi

# Set terminal variables
if [ "$format_html" == "y" ]; then
	clearscreen=`tput clear`
	goto_top=`tput cup 0 1`
	screen_line_three=`tput cup 3 1`
	bold="&lt;b&gt;"
	norm="&lt;/b&gt;"
	ul="&lt;span style=\"text-decoration: underline;\"&gt;"
	noul="&lt;/span&gt;"
elif [ -x /usr/bin/tput ]; then
	clearscreen=`tput clear`
	goto_top=`tput cup 0 1`
	screen_line_three=`tput cup 3 1`
	bold=`tput smso`
	norm=`tput rmso`
	ul=`tput smul`
	noul=`tput rmul`
else
	clearscreen=`echo -n -e "\033[H\033[2J"`
	goto_top=`echo -n -e "\033[1;2H"`
	screen_line_three=`echo -n -e "\033[4;2H"`
	bold=`echo -n -e "\033[7m"`
	norm=`echo -n -e "\033[27m"`
	ul=`echo -n -e "\033[4m"`
	noul=`echo -n -e "\033[24m"`
fi

#
# disk name
#
if [ -n "${disk_properties[serial]}" ]; then
	diskName="${disk_properties[serial]}"
else
	diskName="${disk_properties[name]}"
fi

#
# set init timer or reset timer
#
time_elapsed main set $main_elapsed_time
time_elapsed cycle set $cycle_elapsed_time

######################################################
##													##
##				MAIN PROGRAM BLOCK					##
##													##
######################################################

#
# Verify if it's already running
#
if [ -f "${all_files[pid]}" ]; then
	pid=$(cat ${all_files[pid]})
	if [ -e "/proc/${pid}" ]; then
		echo "An instance of Preclear for disk '$theDisk' is already running."
		debug "An instance of Preclear for disk '$theDisk' is already running."
		trap '' EXIT
		exit 1
	else
		echo "$script_pid" > ${all_files[pid]}
	fi
else
	echo "$script_pid" > ${all_files[pid]}
fi

if ! is_preclear_candidate $theDisk; then
	tput reset
	echo -e "\n${bold}The disk '$theDisk' is part of Unraid's array, or is assigned as a Pool device.${norm}"
	echo -e "\nPlease choose another one from below:\n"
	list_device_names
	echo -e "\n"
	debug "Disk $theDisk is part of Unraid array. Aborted."
	do_exit 1
fi

echo -n "${disk_properties[name]}|NN|Starting...|${script_pid}" > ${all_files[stat]}

if [ -z "$current_op" ] || [ ! -f "${all_files[smart_prefix]}cycle_initial_start" ]; then
	# Export initial SMART status
	[ "$disable_smart" != "y" ] && save_smart_info $theDisk "$smart_type" "cycle_initial_start"
fi

#
# Add current SMART status to display_smart
#
[ "$disable_smart" != "y" ] && compare_smart "cycle_initial_start"
[ "$disable_smart" != "y" ] && output_smart $theDisk "$smart_type"

######################################################
##				VERIFY PRECLEAR STATUS				##
######################################################

if [ "$verify_disk_mbr" == "y" ]; then
	max_steps=1
	if [ "$verify_zeroed" == "y" ]; then
		max_steps=2
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 

	append display_title "${ul}Unraid Server: verifying Preclear State of disk ${noul} ${bold}${disk_properties['serial']}${norm}."
	append display_title "Verifying disk '${disk_properties['serial']}' for Unraid's Preclear State."

	display_status "Verifying Unraid's signature on the MBR: ..." ""
	debug "verifying Unraid's signature on the MBR ..."
	echo -n "${disk_properties[name]}|NN|Verifying Unraid's signature on the MBR...|$$" > ${all_files[stat]}
	sleep 5

	if verify_mbr $theDisk; then
		append display_step "Verifying Unraid's signature:|${bold}SUCCESS${norm}"
		debug "success - Unraid preclear signature valid!"
		echo -n "${disk_properties[name]}|NN|Verifying Unraid's signature on the MBR successful|$$" > ${all_files[stat]}
		display_status
	else
		append display_step "Verifying Unraid's signature:|${bold}FAIL${norm}"
		echo "${disk_properties[name]}|NY|Verifying Unraid's signature on the MBR failed|$$" > ${all_files[stat]}
		debug "fail - Unraid preclear signature invalid!"
		display_status
		echo -e "--> RESULT: FAIL! $theDisk DOESN'T have a valid Unraid MBR signature!!!\n\n"
		if [ "$notify_channel" -gt 0 ]; then
			send_notify "FAIL! $diskName (${disk_properties[name]}) DOESN'T have a valid Unraid MBR signature!!!" "$diskName (${disk_properties[name]}) DOESN'T have a valid Unraid MBR signature!!!" "$diskName (${disk_properties[name]}) DOESN'T have a valid Unraid MBR signature!!!" "" "alert"
		fi
		do_exit 1
	fi
	
	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 
	
	if [ "$max_steps" -eq "2" ]; then
		display_status "Verifying if disk is zeroed ..." ""

		#
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "zeroed"; then
			#
			# Loading restored position
			#
			if [ -n "$current_pos" ]; then
				start_bytes=$current_pos
				start_timer=$current_timer
				current_pos=0
			else
				start_bytes=0
				current_timer=0
			fi

			echo -n "${disk_properties[name]}|NN|Verifying if disk is zeroed...|${script_pid}" > ${all_files[stat]}

			if read_the_disk verify zeroed start_bytes start_timer preread_average preread_speed; then
				append display_step "Verifying if disk is zeroed:|${preread_average} ${bold}SUCCESS${norm}"
				debug "success - the disk is zeroed!"
				echo -n "${disk_properties[name]}|NN|Verifying if disk is zeroed: SUCCESS|$$" > ${all_files[stat]}
				display_status
				sleep 10
			else
				append display_step "Verifying if disk is zeroed:|${bold}FAIL${norm}"
				debug "fail - the disk is NOT zeroed!"
				echo "${disk_properties[name]}|NY|Verifying if disk is zeroed: FAIL|$$" > ${all_files[stat]}
				display_status
				echo -e "--> RESULT: FAIL! $diskName ($theDisk) IS NOT zeroed!!!\n\n"
				if [ "$notify_channel" -gt 0 ]; then
					send_notify "FAIL! $diskName (${disk_properties[name]}) IS NOT zeroed!!!" "FAIL! $diskName (${disk_properties[name]}) IS NOT zeroed!!!" "FAIL! $diskName (${disk_properties[name]}) IS NOT zeroed!!!" "" "alert"
				fi
				do_exit 1
			fi
		fi
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 
	
	if [ "$notify_channel" -gt 0 ]; then
		send_notify "Disk $diskName (${disk_properties[name]}) is precleared!" "Disk $diskName (${disk_properties[name]}) is precleared!" "Disk $diskName (${disk_properties[name]}) is precleared!"
	fi
	echo -n "${disk_properties[name]}|NN|The disk is Precleared!|$$" > ${all_files[stat]}
	echo -e "--> RESULT: SUCCESS! Disk ${disk_properties['serial']} is precleared!\n\n"
	do_exit
fi

######################################################
##				WRITE PRECLEAR STATUS				##
######################################################

append display_title "${ul}Unraid Server Pre-Clear of disk${noul} ${bold}$theDisk${norm}"

if [ "$no_prompt" != "y" ]; then
	ask_preclear
	tput clear
fi

if [ "$write_disk_mbr" == "y" ]; then
	write_signature 64
	# reread partition information
	sleep 1
	blockdev --rereadpt $theDisk
	exit 0
fi

######################################################
##				PRECLEAR THE DISK					##
######################################################

if [ "$erase_disk" == "y" ]; then
	op_title="Erase"
	title_write="Erasing"
	write_op="erase"
else
	op_title="Preclear"
	title_write="Zeroing"
	write_op="zero"
fi

# Numner of times to try the current operation

retries=5

for cycle in $(seq $cycles); do
	#
	# Continue to next cycle if restoring new-session
	#
	if [ -n "$current_op" ] && [ "$cycle" != "$current_cycle" ]; then
		debug "skipping cycle ${cycle}."
		continue
	fi

	if [ -z "$current_pos" ]; then
		time_elapsed cycle set 0
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle

	#
	# Reset canvas
	#
	unset display_title
	unset display_step && append display_step ""
	append display_title "${ul}Unraid Server ${op_title} of disk${noul} ${bold}${disk_properties['serial']}${norm}"

	if [ "$erase_disk" == "y" ]; then
		append display_title "Cycle ${bold}${cycle}$norm of ${cycles}."
	else
		append display_title "Cycle ${bold}${cycle}$norm of ${cycles}, partition start on sector 64."
	fi

	#
	# Adjust the number of steps
	#
	if [ "$erase_disk" == "y" ]; then
		max_steps=4

		# Disable pre-read and post-read if erasing
		skip_preread="y"
		skip_postread="y"
	else
		max_steps=6
	fi

	if [ "$skip_preread" == "y" ]; then
		let max_steps-=1
	fi
	if [ "$skip_postread" == "y" ]; then
		let max_steps-=1
	fi
	if [ "$erase_preclear" != "y" ]; then
		let max_steps-=1
	fi

	#
	# Export initial SMART status
	#
	[ "$disable_smart" != "y" ] && save_smart_info $theDisk "$smart_type" "cycle_${cycle}_start"

	#
	# Do a preread if not skipped
	#
	if [ "$skip_preread" != "y" ]; then
		#
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "preread"; then

			# Loading restored position
			if [ -n "$current_pos" ]; then
				start_bytes=$current_pos
				start_timer=$current_timer
				current_pos=0
			else
				start_bytes=0
				current_timer=0
			fi

			#
			# Updating display status 
			#
			display_status "Pre-Read in progress: ..." ''

			#
			# Saving progress	
			#
			diskop+=([current_op]="preread" [current_pos]="$start_bytes" [current_timer]="$start_timer" )
			save_current_status


			for x in $(seq 1 $retries); do
				#
				# update elapsed time
				#
				time_elapsed main && time_elapsed cycle
				debug "Pre-read: pre-read verification started $x of $retries retries..."
				
				read_the_disk no-verify preread start_bytes start_timer preread_average preread_speed
				ret_val=$?
				if [ "$ret_val" -eq 0 ]; then
					append display_step "Pre-read verification:|[${preread_average}] ${bold}SUCCESS${norm}"
					debug "Pre-read: pre-read verification completed!"
					display_status
					break
				elif [ "$ret_val" -eq 2 -a "$x" -lt $retries ]; then
					debug "dd process hung at ${start_bytes}, killing ..."
					continue
				else
					append display_step "Pre-read verification:|${bold}FAIL${norm}"
					debug "Pre-read: pre-read verification failed!"
					echo "${disk_properties[name]}|NY|Pre-read failed - Aborted|$$" > ${all_files[stat]}
					send_notify "FAIL! Pre-read $diskName (${disk_properties[name]}) failed" "FAIL! Pre-read $diskName (${disk_properties[name]}) failed." "Pre-read $diskName (${disk_properties[name]}) failed - Aborted" "" "alert"
					echo -e "--> FAIL: Result: Pre-Read failed.\n\n"
					debug_smart $theDisk "$smart_type"	"Error:"
					display_status
					wait $!

					do_exit 1
				fi
			done
		else
			append display_step "Pre-read verification:|[${preread_average}] ${bold}SUCCESS${norm}"
			display_status
		fi
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 

	#
	# Erase the disk in erase-clear op
	#
	if [ "$erase_preclear" == "y" ]; then
		#
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "erase"; then
			#
			# Loading restored position
			#
			if [ -n "$current_pos" ]; then
				start_bytes=$current_pos
				start_timer=$current_timer
				current_pos=""
			else
				start_bytes=0
				start_timer=0
			fi

			display_status "Erasing in progress ..." ''
			diskop+=([current_op]="erase" [current_pos]="$start_bytes" [current_timer]="$start_timer" )
			save_current_status

			#
			# Erase the disk
			#
			for x in $(seq 1 $retries); do
				#
				# update elapsed time
				#
				time_elapsed main && time_elapsed cycle 
				debug "Erasing: erasing the disk started $x of $retries retries..."

				write_the_disk erase start_bytes start_timer write_average write_speed
				ret_val=$?
				if [ "$ret_val" -eq 0 ]; then
					debug "Erasing: erasing the disk completed!"
					append display_step "Erasing the disk:|[${write_average}] ${bold}SUCCESS${norm}"
					display_status
					break
				elif [ "$ret_val" -eq 2 -a "$x" -lt $retries ]; then
					debug "dd process hung at ${start_bytes}, killing ..."
					continue
				else
					append display_step "Erasing the disk:|${bold}FAIL${norm}"
					debug "Erasing: erasing the disk failed!"
					echo "${disk_properties[name]}|NY|Erasing failed - Aborted|$$" > ${all_files[stat]}
					send_notify "FAIL! Erasing $diskName (${disk_properties[name]}) failed" "FAIL! Erasing $diskName (${disk_properties[name]}) failed." "Erasing $diskName (${disk_properties[name]}) failed - Aborted" "" "alert"
					echo -e "--> FAIL: Result: Erasing the disk failed.\n\n"
					debug_smart $theDisk "$smart_type"	"Error:"
					display_status
					wait $!

					do_exit 1
				fi
			done
		else
			append display_step "Erasing the disk:|[${write_average}] ${bold}SUCCESS${norm}"
			display_status
		fi
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 

	#
	# Erase/Zero the disk
	# Check current operation if restoring a previous preclear instance
	#
	if is_current_op "$write_op"; then
		#
		# Loading restored position
		#
		if [ -n "$current_pos" ]; then
			start_bytes=$current_pos
			start_timer=$current_timer
			current_pos=""
		else
			start_bytes=0
			start_timer=0
		fi

		display_status "${title_write} in progress ..." ''
		diskop+=([current_op]="$write_op" [current_pos]="$start_bytes" [current_timer]="$start_timer" )
		save_current_status

		for x in $(seq 1 $retries); do
			#
			# update elapsed time
			#
			time_elapsed main && time_elapsed cycle 
			debug "${title_write}: ${title_write,,} the disk started $x of $retries retries..."

			write_the_disk $write_op start_bytes start_timer write_average write_speed
			ret_val=$?
			if [ "$ret_val" -eq 0 ]; then
				append display_step "${title_write} the disk:|[${write_average}] ${bold}SUCCESS${norm}"
				debug "${title_write}: ${title_write,,} the disk completed!"
				break
			elif [ "$ret_val" -eq 2 -a "$x" -lt $retries ]; then
				debug "dd process hung at ${start_bytes}, killing ..."
				continue
			else
				append display_step "${title_write} the disk:|${bold}FAIL${norm}"
				debug "${title_write}: ${title_write,,} the disk failed!"
				echo "${disk_properties[name]}|NY|${title_write} the disk failed - Aborted|$$" > ${all_files[stat]}
				send_notify "FAIL! ${title_write} $diskName (${disk_properties[name]}) failed" "FAIL! ${title_write} $diskName (${disk_properties[name]}) failed." "${title_write} $diskName (${disk_properties[name]}) failed - Aborted" "" "alert"
				echo -e "--> FAIL: Result: ${title_write} $diskName (${disk_properties[name]}) failed.\n\n"
				debug_smart $theDisk "$smart_type" "Error:"
				display_status
				wait $!

				do_exit 1
			fi
		done
	else
		append display_step "${title_write} the disk:|[${write_average}] ${bold}SUCCESS${norm}"
		display_status
	fi

	#
	# Write the preclear signature
	#
	if [ "$erase_disk" != "y" ]; then
		#
		# Write Unraid's preclear signature to the disk
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "write_mbr"; then
			#
			# update elapsed time
			#
			time_elapsed main && time_elapsed cycle 

			display_status "Writing Unraid's Preclear signature to the disk ..." ''
			diskop+=([current_op]="write_mbr" [current_pos]="0" [current_timer]="0" )
			save_current_status
			echo -n "${disk_properties[name]}|NN|Writing Unraid's Preclear signature|$$" > ${all_files[stat]}
			write_signature 64
			sleep 1
			#
			# reread partition information
			#
			blockdev --rereadpt $theDisk
			# sleep 10
			append display_step "Writing Unraid's Preclear signature:|${bold}SUCCESS${norm}"
			echo -n "${disk_properties[name]}|NN|Writing Unraid's Preclear signature finished|$$" > ${all_files[stat]}
			# sleep 10
		else
			append display_step "Writing Unraid's Preclear signature:|${bold}SUCCESS${norm}"
			display_status
		fi

		#
		# Verify Unraid's preclear signature in disk
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "read_mbr"; then
			display_status "Verifying Unraid's signature on the MBR: ..." ""
			diskop+=([current_op]="read_mbr" [current_pos]="0" [current_timer]="0" )
			save_current_status
			echo -n "${disk_properties[name]}|NN|Verifying Unraid's signature on the MBR|$$" > ${all_files[stat]}
			debug "Signature: verifying Unraid's signature on the MBR ..."

			if verify_mbr $theDisk; then
				append display_step "Verifying Unraid's Preclear signature:|${bold}SUCCESS${norm}"
				display_status
				debug "Signature: Unraid preclear signature is valid!"
				echo -n "${disk_properties[name]}|NN|Unraid's signature on the MBR is valid|$$" > ${all_files[stat]}
			else
				append display_step "Verifying Unraid's Preclear signature:|${bold}FAIL${norm}"
				debug "Signature: Unraid preclear signature is invalid!"
				echo -e "--> FAIL: Unraid's Preclear signature is invalid. \n\n"
				echo "${disk_properties[name]}|NY|Unraid's signature on the MBR failed - Aborted|$$" > ${all_files[stat]}
				send_notify "FAIL! Invalid Unraid's MBR signature on $diskName (${disk_properties[name]})" "FAIL! Invalid Unraid's MBR signature on $diskName (${disk_properties[name]})." "Invalid Unraid's MBR signature on $diskName (${disk_properties[name]}) - Aborted" "" "alert"
				debug_smart $theDisk "$smart_type" "Error:"
				display_status
				wait $!

				do_exit 1
			fi
		else
			append display_step "Verifying Unraid's Preclear signature:|${bold}SUCCESS${norm}"
			display_status
		fi

		#
		# update elapsed time
		#
		time_elapsed main && time_elapsed cycle
	fi

	#
	# Do a post-read if not skipped
	#
	if [ "$skip_postread" != "y" ]; then
		#
		# Check current operation if restoring a previous preclear instance
		#
		if is_current_op "postread"; then
			#
			# Loading restored position
			#
			if [ -n "$current_pos" ]; then
				start_bytes=$current_pos
				start_timer=$current_timer
				current_pos=""
			else
				start_bytes=0
				start_timer=0
			fi

			display_status "Post-Read in progress ..." ""
			diskop+=([current_op]="postread" [current_pos]="$start_bytes" [current_timer]="$start_timer" )
			save_current_status
			for x in $(seq 1 $retries); do
				#
				# update elapsed time
				#
				time_elapsed main && time_elapsed cycle
				debug "Post-Read: post-read verification started $x of $retries retries..."

				read_the_disk verify postread start_bytes start_timer postread_average postread_speed
				ret_val=$?
				if [ "$ret_val" -eq 0 ]; then
					append display_step "Post-Read verification:|[${postread_average}] ${bold}SUCCESS${norm}"
					debug "Post-Read: post-read verification completed!"
					display_status
					echo "${disk_properties[name]}|NY|Post-Read verification successful|$$" > ${all_files[stat]}
					break
				elif [ "$ret_val" -eq 2 -a "$x" -lt $retries ]; then
					debug "dd process hung at ${start_bytes}, killing ..."
					continue
				else
					append display_step "Post-Read verification:|${bold}FAIL${norm}"
					debug "Post-Read: post-read verification failed!"
					echo -e "--> FAIL: Post-Read verification failed. Your drive is not zeroed.\n\n"
					echo "${disk_properties[name]}|NY|Post-Read failed - Aborted|$$" > ${all_files[stat]}
					send_notify "FAIL! Post-Read $diskName (${disk_properties[name]}) failed" "FAIL! Post-Read $diskName (${disk_properties[name]}) failed." "Post-Read $diskName (${disk_properties[name]}) failed - Aborted" "" "alert"
					debug_smart $theDisk "$smart_type" "Error:"
					display_status
					wait $!

					do_exit 1
				fi
			done
		fi
	fi

	#
	# update elapsed time
	#
	time_elapsed main && time_elapsed cycle 

	#
	# Export final SMART status for cycle
	#
	[ "$disable_smart" != "y" ] && save_smart_info $theDisk "$smart_type" "cycle_${cycle}_end"

	#
	# Compare start/end values
	#

	[ "$disable_smart" != "y" ] && compare_smart "cycle_${cycle}_start" "cycle_${cycle}_end" "CYCLE $cycle"

	#
	# Add current SMART status to display_smart
	#
	[ "$disable_smart" != "y" ] && output_smart $theDisk "$smart_type"

	display_status '' ''
	debug_smart $theDisk "$smart_type" "Cycle $cycle"

	debug "Cycle: elapsed time: $(time_elapsed cycle display)"

	#
	# Send end of the cycle notification
	#
	if [ "$notify_channel" -gt 0 ] && [ "$notify_freq" -ge 1 ]; then
		if [ $cycle -eq $cycles ]; then
			subject="Disk $diskName (${disk_properties[name]}) preclear finished!"
			description="${op_title}: Disk $diskName (${disk_properties[name]}) preclear finished!"
			report_out="Disk ${disk_properties[name]} has successfully finished it's preclear!\\n\\n"
		else
			subject="Disk $diskName (${disk_properties[name]}) PASSED cycle ${cycle}!"
			description="${op_title}: Disk $diskName (${disk_properties[name]}) PASSED cycle ${cycle}!"
			report_out="Disk ${disk_properties[name]} has successfully finished a preclear cycle!\\n\\n"
			report_out+="Finished Cycle $cycle of $cycles cycles.\\n"
		fi
		[ "$skip_preread" != "y" ] && report_out+="Last Cycle's Pre-Read Time: $(time_elapsed preread display).\\n"
		if [ "$erase_disk" == "y" ]; then
			report_out+="Last Cycle's Erasing Time:	$(time_elapsed erase display).\\n"
		else
			report_out+="Last Cycle's Zeroing Time:	$(time_elapsed zero display).\\n"
		fi
		[ "$skip_postread" != "y" ] && report_out+="Last Cycle's Post-Read Time: $(time_elapsed postread display).\\n"
		report_out+="Last Cycle's Elapsed Time: $(time_elapsed cycle display)\\n"
		report_out+="Disk Start Temperature: ${disk_properties[temp]}\n"
		report_out+="Disk Current Temperature: $(get_disk_temp $theDisk "$smart_type")\\n"
		[ "$cycle" -lt "$cycles" ] && report_out+="\\nStarting a new cycle.\\n"

		send_notify "$subject" "$description" "$report_out"
	fi
done

#
# update elapsed time
#
time_elapsed main && time_elapsed cycle
debug "Preclear: total elapsed time: $(time_elapsed main display)"

echo -n "${disk_properties[name]}|NN|${op_title} Finished Successfully!|$$" > ${all_files[stat]};

if [ "$disable_smart" != "y" ]; then
	echo -e "--> ATTENTION: Please take a look into the SMART report above for drive health issues.\n"
fi
echo -e "--> RESULT: ${op_title} Finished Successfully!\n\n"

#
# Saving report
#
report="${all_files[dir]}/report"

#
# Remove resume information
#
rm -f ${all_files[resume_file]}

tmux_window="preclear_disk_${disk_properties[serial]}"
if [ "$(tmux ls 2>/dev/null | grep -c "${tmux_window}")" -gt 0 ]; then
	tmux capture-pane -t "${tmux_window}" && tmux show-buffer >$report 2>&1
else
	display_status '' '' >$report
	if [ "$disable_smart" != "y" ]; then
		echo -e "--> ATTENTION: Please take a look into the SMART report above for drive health issues.\n" >>$report
	fi
	echo -e "--> RESULT: ${op_title} Finished Successfully!\n\n" >>$report
	report_tmux="preclear_disk_report_${disk_properties[name]}"

	tmux new-session -d -s "${report_tmux}"
	tmux send -t "${report_tmux}" "cat '$report'" ENTER
	sleep 1

	tmux capture-pane -t "${report_tmux}" && tmux show-buffer >$report 2>&1
	tmux kill-session -t "${report_tmux}" >/dev/null 2>&1
fi

#
# Remove empty lines
#
sed -i '/^$/{:a;N;s/\n$//;ta}' $report

#
# Save report to Flash disk
#
mkdir -p /boot/preclear_reports/
date_formatted=$(date "+%Y.%m.%d_%H.%M.%S")
file_name=$(echo "preclear_report_${disk_properties[serial]}_${date_formatted}.txt" | sed -e 's/[^A-Za-z0-9._-]/_/g')
todos < $report > "/boot/preclear_reports/${file_name}"

do_exit
