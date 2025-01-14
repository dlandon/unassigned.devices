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
HEIGHT=15
WIDTH=60

DOCROOT=`grep -Po '^chdir = \K.*' /etc/php-fpm.d/www.conf 2>/dev/null`
if [ -z ${DOCROOT} ];then
	DOCROOT="/usr/local/emhttp"
fi

gfjardim_script="$(DOCROOT}/plugins/unassigned.devices.preclear/scripts/preclear_disk.sh"

exec 2> >(while read err; do echo "${err}"|logger; echo "${err}"; done >&2)

exec 3>&1;

status_ok() { if [ "$1" -gt 0 ]; then return 1; else return 0; fi }


echo_tmux() {
	while true; do
		# tmux capture-pane -t "$1" 2>/dev/null;
		lines=$(tmux capture-pane -t "$1" 2>/dev/null;tmux show-buffer 2>&1 | sed '/^$/{:a;N;s/\n$//;ta}')
		for i in $(seq $(echo "$lines" | wc -l) 50 ); do echo " " >> "$2"; done
		echo "$lines" >> "$2"
		sleep 2
	done
}


dialog_main() {
	CHOICE_HEIGHT=4
	BACKTITLE="Preclear Plugin"
	TITLE="Preclear Plugin"
	MENU="Choose one of the following options:"
	OPTIONS=(1 "Start a preclear session" 2 "Stop a preclear session" 3 "Output a preclear session")
	CHOICE=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Exit" --menu "$MENU" $HEIGHT $WIDTH $CHOICE_HEIGHT "${OPTIONS[@]}" 2>&1 1>&3)
	if [ "$?" -eq 0 ]; then
		echo $CHOICE
	fi
}


dialog_script_gfjardim_op() {
	CHOICE_HEIGHT=6
	BACKTITLE="Preclear Plugin"
	TITLE="Preclear Plugin"

	QUESTION=0
	CYCLES=0
	while true; do
		case $QUESTION in
			0)	OPTIONS=()
					while read disk; do
						disk_name=$(echo $disk | cut -d "=" -f1 | xargs)
						disk_serial=$(echo $disk | cut -d "=" -f2 | xargs)
						session_exist=$(tmux ls 2>/dev/null|grep -c "preclear_disk_$disk_serial")
						if [ "$session_exist" -gt 0 ]; then
							continue
						fi
						
						pidfile="/tmp/.preclear/$disk_name/pid"
						if [ -e "$pidfile" ] && [ -e "/proc/$(cat $pidfile)" ]; then
							continue
						fi
						OPTIONS+=( "$disk_name" "$disk_serial" )
					done < <($gfjardim_script --unassigned 2>/dev/null)
					if [ "${#OPTIONS[@]}" -gt 0 ]; then
						DISK=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Back" --menu "Choose Disk:" $HEIGHT $WIDTH $((${#OPTIONS[@]} / 2 + 1)) "${OPTIONS[@]}" 2>&1 1>&3)
						if ! status_ok $?; then return 1; fi
					else
						dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --msgbox "\n\nNo unassigned disks available for preclear." $HEIGHT $WIDTH 2>&1 1>&3
						return 1
					fi
			;;
			1)	OPTIONS=(1 "Clear" 2 "Verify All the Disk" 3 "Verify MBR Only" 4 "Erase All the Disk" 5 "Erase and Clear All the Disk")
					OPERATION=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Back" --menu "Operation:" $HEIGHT $WIDTH $CHOICE_HEIGHT "${OPTIONS[@]}" 2>&1 1>&3)
					if ! status_ok $?; then QUESTION=$(($QUESTION - 2)); fi
					# if ! status_ok $?; then return 1; fi
			;;	
			2)	if [ "$OPERATION" -eq 1 ] || [ "$OPERATION" -eq 5 ]; then
						OPTIONS=(1 "1" 2 "2" 3 "3" 4 "4" 5 "5" 6 "6" 7 "7" )
						CYCLES=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Back" --menu "Cycles:" $HEIGHT $WIDTH 8 "${OPTIONS[@]}" 2>&1 1>&3)
						if ! status_ok $?; then QUESTION=$(($QUESTION - 2)); fi
					fi
			;;
			3)	OPTIONS=(1 "Browser" OFF 2 "Email" OFF 4 "Agents" OFF)
					CHANNELS=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Back" --checklist "Notifications:" $HEIGHT $WIDTH 8 "${OPTIONS[@]}" 2>&1 1>&3 )
					if ! status_ok $?; then QUESTION=$(($QUESTION - 2)); fi
					CHANNELS=$(echo $CHANNELS | awk '{sum=$1+$2+$3} END {print sum}')
					CHANNELS=${CHANNELS:-0}
			;;
			4)	if [ "$CHANNELS" -gt 0 ]; then
						OPTIONS=(1 "On preclear end" 2 "On every cycle end" 3 "On every cycle and step end" 4 "On every 25% of progress")
						FREQUENCY=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --cancel-label "Back" --menu "Notifications:" $HEIGHT $WIDTH 8 "${OPTIONS[@]}" 2>&1 1>&3)
						if ! status_ok $?; then QUESTION=$(($QUESTION - 2)); fi
					fi
			;;
			5)	if [ "$OPERATION" -eq 1 ] || [ "$OPERATION" -eq 5 ]; then
						if dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --defaultno --yesno "Skip Pre-Read:" $HEIGHT $WIDTH 2>&1 1>&3; then
							SKIP_PREREAD=y
						fi
						if dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --defaultno --yesno "Skip Post-Read:" $HEIGHT $WIDTH	2>&1 1>&3; then
							SKIP_POSTREAD=y
						fi
					fi
			;;
		esac

		QUESTION=$(($QUESTION + 1))
		if [ "$QUESTION" -gt 5 ]; then
			break
		fi
	done

	CMD="$gfjardim_script --no-prompt"
	case $OPERATION in
		2) CMD="$CMD --verify";;
		3) CMD="$CMD --signature";;
		4) CMD="$CMD --erase";;
		5) CMD="$CMD --erase-clear";;
	esac
	if [ "$CYCLES" -gt 1 ]; then
		CMD="$CMD --cycles $CYCLES"
	fi
	if [ "$CHANNELS" -gt 0 ]; then
		CMD="$CMD --notify $CHANNELS --frequency $FREQUENCY"
	fi
	if [ "$SKIP_PREREAD" == "y" ]; then
		CMD="$CMD --skip-preread"
	fi
	if [ "$SKIP_POSTREAD" == "y" ]; then
		CMD="$CMD --skip-postread"
	fi

	CMD="$CMD /dev/${DISK}"

	serial=$(lsblk -nbP -o name,serial | grep "$DISK" | cut -d '"' -f4)
	session="preclear_disk_$serial"
	tmux_exist=$(tmux ls 2>/dev/null|grep -c $session)
	if [ "$tmux_exist" -eq 0 ]; then
		tmux new-session -d -x 140 -y 200 -s "$session" 2>/dev/null
		tmux send -t "$session" "$CMD" ENTER 2>/dev/null
	fi
	sleep 1
	tmux_exist=$(tmux ls 2>/dev/null|grep -c $session)
	if [ "$tmux_exist" -gt 0 ]; then
		dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --msgbox "\n\n\n\nPreclear session for disk $serial started successfully." $HEIGHT $WIDTH 2>&1 1>&3
	fi
}


dialog_stop() {
	BACKTITLE="Preclear Plugin"
	TITLE="Preclear Plugin"

	sessions=()
	while read session; do
		serial=${session/preclear_disk_/};
		disk=$(lsblk -nbP -o name,serial | grep "$serial" |cut -d '"' -f2)
		sessions+=($disk $serial)
	done < <(tmux ls 2>/dev/null|grep "preclear_disk_"|cut -d ':' -f 1)

	if [ "${#sessions[@]}" -gt 0 ]; then
		disk=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --menu "Stop Preclar Session:" $HEIGHT $WIDTH $((${#sessions[@]} / 2 + 1)) "${sessions[@]}" 2>&1 1>&3)
		if ! status_ok $?; then return 1; fi
	else
		dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --msgbox "\n\n\nThere aren't any active sessions of preclear." $HEIGHT $WIDTH 2>&1 1>&3
		return 1
	fi

	if dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --defaultno --yesno "Do you want to proceed?" $HEIGHT $WIDTH 2>&1 1>&3; then
		serial=$(lsblk -nbP -o name,serial | grep "$disk" |cut -d '"' -f4)
		session="preclear_disk_$serial"
		tmux send -t "$session" "C-c" ENTER 2>/dev/null
		tmux kill-session -t "$session" &>/dev/null
	fi
}


dialog_output() {
	BACKTITLE="Preclear Plugin"
	TITLE="Preclear Plugin"

	sessions=()
	while read session; do
		serial=${session/preclear_disk_/};
		disk=$(lsblk -nbP -o name,serial | grep "$serial" |cut -d '"' -f2)
		sessions+=($disk $serial)
	done < <(tmux ls 2>/dev/null|grep "preclear_disk_"|cut -d ':' -f 1)

	if [ "${#sessions[@]}" -gt 0 ]; then
		disk=$(dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --menu "Output Preclear Session:" $HEIGHT $WIDTH $((${#sessions[@]} / 2 + 1)) "${sessions[@]}" 2>&1 1>&3)
		if ! status_ok $?; then return 1; fi
	else
		dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --msgbox "\n\nThere aren't any active sessions of preclear." $HEIGHT $WIDTH 2>&1 1>&3
		return 1
	fi
	clear
	serial=$(lsblk -nbP -o name,serial | grep "$disk" |cut -d '"' -f4)
	session="preclear_disk_$serial"
	tmpfile=$(mktemp)
	echo_tmux "$session" "$tmpfile" &
	pid=$!
	dialog --clear --backtitle "$BACKTITLE" --title "$TITLE" --tailbox "$tmpfile" 56 140 2>&1 1>&3
	kill $pid
	rm "$tmpfile"
}

trap "clear" INT TERM EXIT SIGKILL

dialog_path=$(which dialog 2>/dev/null)
if [ -z "$dialog_path" ]; then
	dialog_install=$(find /boot/config/plugins/unassigned.devices.preclear/ -type f -iname "dialog-*.txz"|head -n 1)
	if [ -f "$dialog_install" ]; then
		installpkg "$dialog_install"
		retval=$?
		if [ "$retval" -gt 0 ]; then
			exit 0
		fi
	else
		exit 1
	fi
fi

main_option=0
while [[ true ]]; do
	case $main_option in
		1)
			# script=$(dialog_script)
			script=1
			if [ "$script" -eq 1 ]; then
				script_operation=$(dialog_script_gfjardim_op)
				main_option=0
			elif [ "$script" -eq 2 ]; then
				script_operation=$(dialog_script_op)
				if ! status_ok $?; then
					main_option=0
				fi
			fi
		;;
		2)
			stop_option=$(dialog_stop)
			if ! status_ok $?; then
				main_option=0
			fi
		;;
		3)
			output_option=$(dialog_output)
			if ! status_ok $?; then
				main_option=0
			fi
		;;
		"")
			break
		;;
		*)
			main_option=$(dialog_main)
		;;
	esac
done

exec 3>&-
clear