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

script_pid=$BASHPID

debug() {
  local msg="$*"
  if [ -z "$msg" ]; then
    while read msg; do 
      cat <<< "$(date +"%b %d %T" ) preclear_queue: $msg" >> /var/log/preclear/preclear.log
      logger --id="${script_pid}" -t "preclear_queue" "${msg}"
    done
  else
    cat <<< "$(date +"%b %d %T" ) preclear_queue: $msg" >> /var/log/preclear/preclear.log
    logger --id="${script_pid}" -t "preclear_queue" "${msg}"
  fi
}

# Redirect errors to log
exec 2> >(while read err; do echo "$(date +"%b %d %T" ) preclear_queue: ${err}" >> /var/log/preclear/preclear.log; echo "${err}"; done; >&2)

get_running_sessions() {
  for file in $(ls /tmp/.preclear/*/pid 2>/dev/null); do
    pid=$(cat $file);
    if [ -e "/proc/${pid}/exe" ]; then
      echo $(echo $file | cut -d'/' -f4);
    fi
  done
}

do_clean()
{
  for disk in $(get_running_sessions); do 
    queued="/tmp/.preclear/$disk/queued"
    if [ -f "$queued" ]; then
      rm "$queued" 2>/dev/null;
      debug "Restoring $disk preclear session"
    fi
  done
  rm /var/run/preclear_queue.pid 2>/dev/null
  debug "Stopped"
  tmux kill-window -t 'preclear_queue' 2>/dev/null
}

sort_running()
{
  local sort_file="/boot/config/plugins/unassigned.devices.preclear/sort_order"
  local  -g -a sort_order
  local i=999999
  if [ -f "$sort_file" ]; then
    for disk in $(get_running_sessions); do
      line=$(grep -n "$disk" "$sort_file" | cut -d : -f 1)
      if [ -n "$line" ]; then
        sort_order[$line]=$disk
      else
        sort_order[$i]=$disk
        i=$((i+1))
      fi
    done

    for disk in "${sort_order[@]}"; do
      echo $disk
    done
  else
    get_running_sessions
  fi
}

update_slots()
{
  if [ -f "/boot/config/plugins/unassigned.devices.preclear/queue" ]; then
    queue=$(cat "/boot/config/plugins/unassigned.devices.preclear/queue")
  else
    queue=0
  fi
}

queue=${1-1};
timer=$(date '+%s')

trap update_slots HUP

echo $$ > /var/run/preclear_queue.pid
[ $queue -gt 1 ] && debug "Start queue with $queue slots" || debug "Start queue with $queue slot"

trap "do_clean;" exit

while [ -f /var/run/preclear_queue.pid ]; do
  running=0
  for disk in $(sort_running); do
    tmpdir="/tmp/.preclear/${disk}"
    queue_file="${tmpdir}/queued"
    pause_file="${tmpdir}/pause"

    if [ ! -f $pause_file -a $queue -gt 0 ]; then

      if [ $running -lt $queue ]; then
        if [ -f $queue_file ]; then
          debug "Restoring $disk preclear session"
          rm $queue_file
        fi
        running=$(( $running + 1 ))
      elif [ $running -ge $queue ]; then
        if [ ! -f $queue_file ]; then
          debug "Enqueuing $disk preclear session"
          touch $queue_file
        fi
      fi
    timer=$(date '+%s')
    fi
  done

  if [ $running -eq "0" ] && [[  $(( $(date '+%s') - $timer )) -gt 60 ]]; then
    debug "No active jobs, stopping queue manager"
    break
  fi
  sleep 1
done