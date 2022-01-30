#!/bin/bash
# Version .1 - initial attempt at preclear.  Joe L.
# Version .5 - fixed hard-coded device name.
# Version .6 - added verification test of bytes cleared in first 512 byte block.
# Version .7 - fixed sfdsisk vs blockdev mismatch on size.
# Version .8 - replaced sfdisk partitioning with awk script, re-did
#              progress display, added disk pre/post read. Joe L.
# Version .9 - added -t option to test for pre-cleared signature
# Version .9.1 - modified "read" loop to not abort on read failure
# Version .9.2 - Added support for send staus through e-mail. Needs mail configured correctly
# Version .9.3 - Added sub-shell for background read of disk, to work around 4096 "wait" bug in bash.
# Version .9.4 - Enable SMART monitoring, just in case it is disabled on a drive.
# Version .9.5 - Added disk temperature display and disk read speed display.
# Version .9.6 - Enhanced the mail reporting to include some statistics (time, MB/s temp .. ect)
#              - Fixed a bug with using zero.txt and concurrent tests. Each test will use it's own file.
#              - Changed read block size to be bigger than 1,000,000 if smaller, to improve read/write speed
# Version .9.7 - Added verify of zeros read from entire disk in postread phase.
# Version .9.8 - Added ability to set read and write block sizes with -w and -r options.
#              - Added ability to set number of blocks to read at a time with -b option.
# Version .9.9 - Changes to not perform pre-read in multi-cycles of use immediately after post-read
#              - added "-l" option to list device names of disks not in the array.
#              - modifications to final output report for easier interpetation
#              - modification to name the temp files after the disks being cleared.
#              - Added ability to start partition at sector 64 with -A option.
# Version .9.9a - improvements to analysis report
# Version .9.9b - more improvements to analysis report
#               - fixed bug when parsing config/disk.cfg for existing assigned disks.
# Version .9.9c - fixed bug where date format change in 5.0 unRAID changed and
#                 caused "-l" option to not work as expected.
#               - more improvements to analysis report
# Version .9.9d - more improvements to analysis report
# Version 1.1   - added -C 64 and -C 63 options to convert precleared drives from
#                 sector 63 start to sector 64 start.
#               - added display of command line arguments to initial confirmation screen
#               - added -W option to skip pre-read phase and start with "write" phase.
#               - added -V option to skip pre-read and "clear" and only
#                 perform the post-read verify.
# Version 1.2   - fixed "-l" option to not exclude disks with only a "scsi-" entry in /dev/disk/by-id
# Version 1.3   - Added logic to read desired "default" Partition Type from /boot/config.
#               - Added logic to save dated copies of the final preclear and SMART reports to a
#                 "preclear_reports" subdirectory on the flash drive.
#               - Added "-R" option to suppress the copy of final reports to a "preclear_reports"
#                 directory on the flash drive. (they are always in /tmp until you reboot)
# Version 1.4   - Added "-D" option to suppress use of "-d ata" on smartctl commands
#                 Added "-d device_type" to allow use of alternate device_types as arguments to smartctl.
#                 Added "-z" option to zero the MBR and do nothing else. (remainder of the drive will not
#                 be cleared)
# Version 1.5   - Added Model/Serial number of disk to output report.
#                 Fixed default argument to smartctl when "-d" and "-D" options not given.
#                 Added intermediate report of sectors pending re-allocation.
# Version 1.6   - Fixed logic to prevent use on disk assigned to array
# Version 1.7   - Again fixed logic to deal with change in disk.cfg format.
# Version 1.8   - Changes to range of random blocks read to not read past last block on disk.
# Version 1.9   - fixed parse of default partition type from config
#                 fixed parse of assigned disks
# Version 1.10  - create preclear_stat_sdX files (read by myMain) - bjp999
# Version 1.11  - Change name of saved reports files to include serial number instead of device name.
#                 added -S option if previous behaviour is desired instead (report name includes linux device)
# Version 1.12  - Do not use "-d ata" if smartctl works without it.
#                 Create correct preclear-signature for disks over 0xFFFFFFFF blocks in size.
# Version 1.13  - 1.12beta was correct, 1.12 was not (I accidentally uploaded an older version)
#                 1.13 has the fixes and should work properly for GPT partitions and proper recognition of
#                 default in the absence of "-A" or "-a" option.
# Version 1.14  - Added text describing how -A and -a options are not used or needed on disks > 2.2TB.
#                 Added additional logic to detect assigned drives in the newest of 5.0 releases.
# Version 1.15  - Added export of LC_CTYPE=C to prevent unicode use on 64 bit printf.
#                 Added PID to preclear_stat_sdX files and support for notifications - gfjardim
#                 Add notification channel choice - gfjardim
#                 Added support for fast post read - bjp999
#                 Remove /root/mdcmd dependency - gfjardim
# Version 1.19  - Replace strings dependency, using cat -v instead - binhex
#                 Change default start sector from 1 to 64 - binhex
#                 version catchup with binhex - gfjardim
# Version 1.20  - drives > 2.2TiB and with protective MBR partition starting on sector 64 failing signature verification - gfjardim
ver="1.20"

progname=$0
options=$*

usage() {
  cat <<EOF

Usage: $progname [-t] [-n] [-N] [-W] [-R] [-m e-mail-addr] [-M 1|2|3|4 -o 1|2|3|4|5|6|7] [-c count] [-A|-a] /dev/???

To test if a drive is cleared:
       $progname -t /dev/???

To covert an already pre-cleared drive from using a start sector of 63 to a start sector of 64.
       $progname -C 64 /dev/???

To covert an already pre-cleared drive from using a start sector of 64 to a start sector of 63.
       $progname -C 63 /dev/???

To zero only the MBR of a disk (first 512 bytes)
       $progname -z /dev/???

To run the post-read-verify only on a drive.
       $progname -V [-A|-a] /dev/???

To run only the writing of zeros and post-read-verify phases (skipping the pre--read)
       $progname -W [-A|-a] /dev/???

To list device names of drives not assigned to the unRAID array:
       $progname -l

       where ??? = hda, hdb, sda, sdb, etc...

       -n = Do NOT perform preread and postread of entire disk that would allow
            SMART firmware to identify bad blocks.

       -N = Do not perform read validation during postread. (skip this step)
            (basic test to check if values read are all zero as expected.
             Skipping this test will save a few miniutes, but possibly not detect
             a drive that returns non-zero values when zeros were expected as bad.)

       -c count  =  perform count preread/clear/postread cycles
            where count is a number from 1 through 20
            If not specified, default is 1 cycle.  For large disks, 1 cycle
            can take 30 or more hours

       -t = Test if disk has pre-clear signature.  This option may NOT be
            combined with the -c or -n options.  The test does not write to
            the disk.  It makes no changes to a disk at all. It only reads
            the first 512 bytes of the disk to verify a pre-clear signature
            exists.  Note: "-t" does not read the entire disk to verify it
            it pre-cleared as that could take hours for a large disk. since
            the pre-clear-signature is written *after* a disk is entirely
            filled with zeros, if it exists, we assume the disk is cleared.

       -w size  = write block size in bytes

       -r size  = read block size in bytes

       -b count = number of blocks to read at a time

       -D disable "-d ata" used as argument to smartctl
       -d device_type = supply "-d device_type" to smartctl used to get device status

       -z       = Zero the MBR (first 512 bytes) of the disk.  Do nothing else.

       -a       = start partition on sector 63. (default when on unRAID <= 4.6)
            The -a option is completely ignored on disks > 2.2TB as they use a GPT
            partition that will always start on a 4k boundary (64).
       -A       = start partition on sector 64. (not compatible with unRAID 4.6 and prior)
            If neither option (-a or -A) is specified then the default is set based on
            the value set on the Settings page in the unRAID web-management console.

       -C 63    = convert an existing pre-cleared disk to use sector 63 as a
                  starting sector.

       -C 64    = convert an existing pre-cleared disk to use sector 64 as a
                  starting sector.

       -W       = skip pre-read, start with "write" of zeros to clear the disk.
                  useful if disk has already been completely read to locate bad sectors.

       -v = print version of $progname

       -m email@somedomain.com = optional recipient address.  If blank and -M
            option is used, it will default to default e-mail address of "root"

       -M 1 = Will send an e-mail message at the end of the final results
              (default if -m is used, but no other -M option given)

       -M 2 = Will send an e-mail same as 1 plus at the end of a cycle (if multiple
            cycles are specified)

       -M 3 = Will send an e-mail same as 2 plus at the start and end of the pre-read,
            zeroing, post-read

       -M 4 = Will send an e-mail same as 3 plus also at intervals of 25%
            during the long tests

       The -m, -M options requires that a valid working mail command is installed.

       -R     = supress the copy of the output reports to the flash drive
                A dated output report, the start and finish SMART reports are saved
                in a subdirectory named /boot/preclear_reports
                if the "-R" is NOT given. (The reports are always available
                in /tmp until you reboot, even if "-R" is specified)
       -S     = name the saved output reports by their linux device instead 
                of by the serial number of the disk.
       -f     = use fast post-read verify (courtesy of bjp999)
       -J     = don't prompt for confirmation (bjp999)

Notifications (unRAID v6 only). Must be combined with -M option:

       -o 1   = notify using browser-popups only (default);
       -o 2   = notify using e-mail only;
       -o 3   = notify using browser-popups and e-mail;
       -o 4   = notify using agents only;
       -o 5   = notify using browser-popups and agents;
       -o 6   = notify using e-mail and agents;
       -o 7   = notify using browser popups, e-mail and agents;

       Unless the -n option is specified the disk will first have its entire
       set of blocks read, then, the entire disk will be cleared by writing
       zeros to it.  Once that is done the disk will be partitioned with a
       special signature that the unRAID software will recognize when the
       drive is added to the array.  This special signature will allow the
       unraid software to recognize the disk has been pre-cleared and to skip
       an initial "clearing" step while the server remains off-line.

       The pre-read and post-read phases try their best to exercise the
       disk in a way to identify a drive prone to early failure.  It performs
       reads of random blocks of data interspersed with reads of sequential
       blocks on the disk in turn.  This program also uses non-buffered reads
       of the first and last cylinders on the disk, the goal is to perform
       those reads in between the others, and to keep the disk head
       moving much more than if it just read each linear block in turn.
EOF
  exit 0
}

cp /dev/null /tmp/preclear_assigned_disks1

list_d() {
a=0

# read the disk config file so we can tell what is assigned to the array
exec </boot/config/disk.cfg
while read config
do
    case $config in
    diskSpinupGroup*|diskSpindownDelay*|diskSecurity*|diskExport*|diskRead*|diskWrite*)
    continue
    ;;
    cacheSpinupGroup*|cacheSpindownDelay*|cacheSecurity*|cacheExport*|cacheRead*|cacheWrite*|*cacheExport*|cacheHost*)
    continue
    ;;
    parity*|disk[0-9]*|cache*)
    disk=`echo  "$config" | sed "s/\([^=]*\)=\([^=]*\)/\1/"`
    disks[$a]=$disk
    id=`echo  "$config" | sed -e "s/\([^=]*\)=\([^=]*\)/\2/" -e "s/\\r//" -e "s/\"//g"`
    [ "$id" = "" ] && continue
    # determine the disk device name
    device=`ls --time-style='+%Y-%m-%d %I:%M%p' -ld /dev/disk/by-path/$id 2>/dev/null | awk '{ print substr($10,7,3) }'`
    if [ "$device" = "" ]
    then
       device=`ls /sys/devices/$id/block 2>/dev/null`
       if [ "$device" = "" ]
       then
         device=`ls --time-style='+%Y-%m-%d %I:%M%p' -ld /dev/disk/by-id/*$id*  2>/dev/null | sed 1q |  awk '{ print substr($10,7,3) }'`
       fi
    fi
    dev[$a]="$device"
    echo "${device}|C|$config" >>/tmp/preclear_assigned_disks1
    let a=a+1
    ;;
    esac
done

if [  -f /boot/config/super.dat ]
then
   cat -v /boot/config/super.dat | while read id
  do
           device=`ls --time-style='+%Y-%m-%d %I:%M%p' -ld /dev/disk/by-id/*$id*  2>/dev/null | sed 1q |  awk '{ print substr($10,7,3) }'`
           echo "${device}|C|$config" >>/tmp/preclear_assigned_disks1
  done
fi

if [ -f /var/local/emhttp/disks.ini ]
then
exec </var/local/emhttp/disks.ini
while read ini
do
    case $ini in
    deviceSb*)
    continue
    ;;
    device*)
      device=`echo "$ini" | sed -e "s/\([^=]*\)=\([^=]*\)/\2/" -e "s/\\r//" -e "s/\"//g"`
      echo "${device}|I|$ini" >>/tmp/preclear_assigned_disks1
      dev[$a]="$device"
      let a=a+1
    esac
done
fi

}

# list the disks that are not assigned to the array. They are the possible drives to pre-clear
list_device_names() {
  echo "====================================$ver"
  echo " Disks not assigned to the unRAID array "
  echo "  (potential candidates for clearing) "
  echo "========================================"
  rm /tmp/un_assigned_flag >/dev/null 2>&1
  cp /dev/null /tmp/preclear_candidates 2>&1
  list_d
  ls --time-style='+%Y-%m-%d %I:%M%p' /dev/disk/by-id/* -l | grep -v -- "-part" | cut -c62- | while read a b disk
  do
    disk=`echo $disk | cut -c7-`
    grep "${disk}|" /tmp/preclear_assigned_disks1 >/dev/null 2>&1
    [ $? = 0 ]  && continue
    m=`mount | grep "/dev/$disk" 2>/dev/null`
    [ $? = 0 ] && continue
    grep "$disk" /tmp/preclear_candidates >/dev/null 2>&1
    if [ $? = 1 ]
    then
      echo "     /dev/$disk = $a"
    fi
    echo $disk >>/tmp/preclear_candidates
    touch /tmp/un_assigned_flag
  done
  if [ ! -f  /tmp/un_assigned_flag ]
  then
    echo "No un-assigned disks detected"
  fi
  rm /tmp/un_assigned_flag >/dev/null 2>&1
}

# gfjardim - add notification system capability without breaking legacy mail.
send_mail() {
  subject=$(echo ${1} | tr "'" '`' )
  description=$(echo ${2} | tr "'" '`' )
  message=$(echo ${3} | tr "'" '`' )
  recipient=${4}
  if [ -f "$notify_script" ]; then
    $notify_script -e "Preclear ${model} ${serial}" -s """${subject}""" -d """${description}""" -m """${message}""" -i "normal ${notify_channels}"
  else
    echo -e "${message}" | mail -s "${subject}" "${recipient}"
  fi
}

# Keep track of the elapsed time of the preread/clear/postread process
timer()
{
    if [[ $# -eq 0 ]]; then
        echo $(date '+%s')
    else
        local  stime=$1
        etime=$(date '+%s')

        if [[ -z "$stime" ]]; then stime=$etime; fi

        dt=$((etime - stime))
        ds=$((dt % 60))
        dm=$(((dt / 60) % 60))
        dh=$((dt / 3600))
        printf '%d:%02d:%02d' $dh $dm $ds
    fi
}

preread_skip_pct=0
LC_CTYPE=C
export LC_CTYPE
short_test=0         # set to non-zero for short test for script testing - Leave 0 for normal test
let cycle_count=1
pre_read_flag=y
post_read_flag=y
test_only_flag=n
partition_64=""
use_mail=0
mail_rcpt=""
postread_error=""
skip_postread_verify="no"
read_bs=""
write_bs=""
read_blocks=""
list_drives=""
convert_type=""
convert_flag=""
device_type="-d ata"
verify_only="n"
write_flag="n"
save_report="y"
zero_mbr_only="n"
post_read_err="N"    #bjp999 4/9/11
save_report_by_dev="no"  # default is to save by model/serial num
max_mbr_size="0xFFFFFFFF" # max size of MBR partition 
over_mbr_size="n"
fast_postread="n"
noprompt="n" #bjp999 7-17-11
notify_channels="1" # gfjardim - default notify to browser popups

sb=1
default="(partition starting on sector 63)"

while getopts ":tnc:WM:m:hvDNw:r:b:AalC:VRDd:zsSfJo:" opt; do
  case $opt in
  n ) pre_read_flag=n;post_read_flag=n ;;
  N ) skip_postread_verify="yes" ;;
  t ) test_only_flag=y ;;
  c ) cycle_count=$OPTARG ;;
  C ) convert_flag=y; convert_type=$OPTARG ;;
  M ) use_mail=$OPTARG ;;
  m ) mail_rcpt=$OPTARG ;;
  r ) read_bs=$OPTARG ;;
  W ) write_flag="y"; pre_read_flag="n" ;;
  d ) device_type="-d $OPTARG" ;;
  D ) device_type="" ;;
  A ) partition_64=y
      default="(-A option elected, partition will start on sector 64"
      vdefault="(-A option elected. disk to be verified for partition starting on sector 64"
  ;;
  a ) partition_64=n
      default="(-a option elected, partition will start on sector 63 for disks <= 2.2TB and sector 64 for disks > 2.2TB)"
      vdefault="(-a option elected. disk to be verified for partition starting on sector 63 for disks <= 2.2TB and sector 64 for disks > 2.2TB)"
  ;;
  l ) list_drives=y ;;
  z ) zero_mbr_only=y ;;
  R ) save_report=n ;;
  S ) save_report_by_dev="yes" ;;
  V ) pre_read_flag=n; verify_only=y ;;
  b ) read_blocks=$OPTARG ;;
  w ) write_bs=$OPTARG ;;
  v ) echo $progname version: $ver;
      exit 0
      ;;
  h ) usage >&2 ;;
  s ) short_test=1 ;; # for debugging
  J ) noprompt="y" ;;      # bjp999 3-25-14 bypass all prompts - AUTOMATED ONLY
  f ) fast_postread="y" ;; # bjp999 3-25-14 use fast post read and verify (ignores -N)
  o ) notify_channels=$OPTARG ;; # gfjardim
  \?) usage >&2 ;;
  esac
done

# if partition_64 not specified, use the default from how unRAID is configured.
# Look in the disk.cfg file.
default_format=`grep defaultFormat /boot/config/disk.cfg | sed -e "s/\([^=]*\)=\([^=]*\)/\2/" -e "s/\\r//" -e 's/"//g'  | tr -d '\n'`
if [ "$default_format" != "" -a "$partition_64" = "" ]
then
   case "$default_format" in
   1)   partition_64=n
        default="(MBR unaligned set. Partition will start on sector 63 for disks <= 2.2TB and sector 64 for disks > 2.2TB)"
        vdefault="(MBR unaligned set. disk to be verified for partition starting on sector 63 for disks <= 2.2TB and sector 64 for disks > 2.2TB)"
   ;;
   2)   partition_64=y
        default="(MBR 4k-aligned set. Partition will start on sector 64"
        vdefault="(MBR 4k-aligned set. disk to be verified for partition starting on sector 64"
   ;;
   3)   partition_64=y
        default="(GPT 4k-aligned set. Protective MBR Partition will start on sector 64)"
        vdefault="(GPT 4k-aligned set. disk to be verified for protective MBR partition starting on sector 64)"
   ;;
   esac
fi

if [ "$partition_64" = "" ]
then
   partition_64="n"
fi
#exit


shift $(($OPTIND - 1))

if [ "$list_drives" = "y" ]
then
   if [ $# != 0 ]
   then
     echo "Error: The '-l' option may not be combined with other options" >&2
     echo "usage: $0 -l" >&2
     exit 2
   fi
   list_device_names
   exit
fi

if [ "$verify_only" = "y" ]
then
  if [ "$write_flag" = "y" ]
  then
     echo "Error: -V option may not be combined with -W option" >&2
     exit 2
  fi
  if [ "$post_read_flag" = "n" ]
  then
     echo "Error: -V option may not be combined with -n option" >&2
     exit 2
  fi
  if [ "$skip_postread_verify" = "yes" ]
  then
     echo "Error: -V option may not be combined with -N option" >&2
     exit 2
  fi
  if [ "$test_only_flag" = "yes" ]
  then
     echo "Error: -V option may not be combined with -t option" >&2
     exit 2
  fi
  if [ "$convery_flag" = "y" ]
  then
     echo "Error: -V option may not be combined with -C option" >&2
     exit 2
  fi
fi

if [ "$zero_mbr_only" = "y" ]
then
  if [ "$verify_only" = "y" ]
  then
     echo "Error: -z option may not be combined with -V option" >&2
     exit 2
  fi
  if [ "$write_flag" = "y" ]
  then
     echo "Error: -z option may not be combined with -W option" >&2
     exit 2
  fi
  if [ "$post_read_flag" = "n" ]
  then
     echo "Error: -z option may not be combined with -n option" >&2
     exit 2
  fi
  if [ "$skip_postread_verify" = "yes" ]
  then
     echo "Error: -z option may not be combined with -N option" >&2
     exit 2
  fi
  if [ "$test_only_flag" = "yes" ]
  then
     echo "Error: -z option may not be combined with -t option" >&2
     exit 2
  fi
  if [ "$convery_flag" = "y" ]
  then
     echo "Error: -z option may not be combined with -C option" >&2
     exit 2
  fi
fi

if [ $# != 1 ]
then
  usage >&2
  exit 2
fi

expand_number() {
  echo "$1" | sed -e 's/\([0-9]*\)[mM]$/\1000000/' -e 's/\([0-9]*\)[kK]$/\1000/'
}

write_bs=`expand_number $write_bs`
# validate the write block size
cc="$(echo $write_bs | sed 's/[0-9]//g')"
if [ ! -z "$cc" ]
then
  echo "error: write block size (-w NNN) must be numeric." >&2
  usage >&2
  exit 2
fi

read_bs=`expand_number $read_bs`
# validate the read block size
cc="$(echo $read_bs | sed 's/[0-9]//g')"
if [ ! -z "$cc" ]
then
  echo "error: read block size (-r NNN) must be numeric." >&2
  usage >&2
  exit 2
fi

read_blocks=`expand_number $read_blocks`
# validate the number of blocks to read per pass
cc="$(echo $read_blocks | sed 's/[0-9]//g')"
if [ ! -z "$cc" ]
then
  echo "error: Block count (-b NNN) must be numeric." >&2
  usage >&2
  exit 2
fi

# validate the cycle count
cc="$(echo $cycle_count | sed 's/[0-9]//g')"
if [ ! -z "$cc" ]
then
  echo "error: Cycle count must be numeric." >&2
  usage >&2
  exit 2
fi
if [ $cycle_count -lt 1 ]
then
  echo "error: Cycle count must be greater than 0." >&2
  usage >&2
  exit 2
fi
if [ $cycle_count -gt 20 ]
then
  echo "error: Cycle count may not be greater than 20." >&2
  usage >&2
  exit 2
fi

if [ "$test_only_flag" = "y" ]
then
  if [ "$cycle_count" != "1" -o $pre_read_flag = "n" ]
  then
    echo "error: -t option may not be combined with other options." >&2
    usage >&2
    exit 2
  fi
fi

if [ "$convert_flag" = "y" ]
then
  if [ "$convert_type" != "63" -a "$convert_type" != 64 ]
  then
    echo "error: -C option must supply starting sector (63, or 64)." >&2
    echo "example: -C 64" >&2
    echo "or       -C 63" >&2
    exit 2
  fi
fi

um="$(echo $use_mail | sed 's/[0-9]//g')"
if [ ! -z "$um" ]
then
  echo "error: -M parameter must be numeric." >&2
  usage >&2
  exit 2
fi

if [ $use_mail -gt 4 ]
then
  echo "error: -M parameter may not be greater than 4." >&2
  usage >&2
  exit 2
fi

# Check to see if Mail exists if m or M parameter is used.
if [ $use_mail -gt 0 ] || [ ! -z $mail_rcpt ]
    then
    if [ -f "/usr/local/sbin/notify" ]
    then
      # unRAID 6.0
      notify_script="/usr/local/sbin/notify"
    elif [ -f "/usr/local/emhttp/plugins/dynamix/scripts/notify" ]
    then
      # unRAID 6.1
      notify_script="/usr/local/emhttp/plugins/dynamix/scripts/notify"
    else
      # unRAID pre 6.0
      notify_script=""
    fi
    
    no_mail=`which mail 2>&1 | awk '{print $2}'`
    if [ "$no_mail" = "no" ] && [ ! -f "$notify_script" ]
    then
        echo "error: \"mail\" program does not exist." >&2
        usage >&2
        exit 2
    fi
fi

if [ $use_mail -gt 0 -a -z "$mail_rcpt" ]
then
    mail_rcpt="root" #recipient was not specified, send to root.
fi

if [ ! -z "$mail_rcpt" -a $use_mail -eq 0 ]
then
    use_mail=1
fi

theDisk=$1

disk_basename=`basename $1`
if [ -f "/tmp/postread_errors$disk_basename" ]
then
   rm "/tmp/postread_errors$disk_basename"
fi

if [ "$fast_postread" == "y" ]
then
  readvz_exists=$(which readvz 2>/dev/null|wc -l)
  if [ "$readvz_exists" -eq 0 ]
  then
    echo "error: \"readvz\" program does not exist." >&2
    usage >&2
    exit 2
  fi
fi

disk_temperature() {
        temp=`smartctl $device_type -A $theDisk | grep -i temperature | sed 1q | awk '{ print $10; }'`

        if [ "$temp" != "" ]
        then
           if [ "$temp" -gt "40" ]
           then
               if [[ $# -eq 0 ]]
               then
                   temp=${bold}$temp${norm}
               else
                   temp="-->"$temp"<--"  # Mail doesn't like bold.  Use some alternate form to get attention...
               fi
           fi
           echo -n "Disk Temperature: ${temp}C, "
        else
           echo -n ""
        fi
}

display_progress() {
dt=`disk_temperature`
echo -n $clearscreen #$goto_top
echo "================================================================== $ver"
echo "=                ${ul}unRAID server Pre-Clear disk $theDisk${noul}"
echo "=               cycle $bold$cc$norm of $cycle_count, partition start on sector $partition_start "
echo "= $preread"
echo "= $step1"
echo "= $step2"
echo "= $step3"
echo "= $step4"
echo "= $step5"
echo "= $step6"
echo "= $step7"
echo "= $step8"
echo "= $step9"
echo "= $step10"
echo -e "= $postread"
echo "${dt}Elapsed Time:  $(timer $tmr)"
if [ "$2" != "" ]
then
    kill -0 $2 >/dev/null 2>&1 && kill -USR1 $2
    sleep 1
    tail -3 /tmp/zero$disk_basename
    bytes_wrote=`tail -1 /tmp/zero$disk_basename | awk '{print $1}'`
    bw_formatted=`format_number $bytes_wrote`
    let bw=($bytes_wrote * 100)
    let percent_wrote=($bw / $total_bytes)
    nowtmr=$(timer)
      if [ $bw -gt 0 ] && [ $nowtmr -ne $zerotmr ]
      then
          cal_read_speed=$(($bw / ($nowtmr - $zerotmr) / 1000000 / 100))
      else
          cal_read_speed=0
      fi

    if [ "$sb" != "1" ]
    then
       let tot_blocks=( $total_bytes / 2048000 )
       let bytes_skipped=( 2048000 * $sb )
       skb_formatted=`format_number $bytes_skipped`
       let sk2=( $bytes_skipped * 100 )
       let percent_skipped=($sk2 / $total_bytes)
       echo "Skipping $skb_formatted bytes $sb*2048k ($percent_skipped%)"
       let percent_wrote=($percent_wrote + $percent_skipped)
    fi
    echo "Wrote $bw_formatted bytes out of $tb_formatted bytes ($percent_wrote% Done) "

    if [ $use_mail -eq 4 ]
    then
        if [ $percent_wrote -ge $report_percentage ]
        then
            let report_percentage=($report_percentage + 25)
            report_out="Zeroing Disk $theDisk in progress: ${percent_wrote}% complete.  \\n"
            report_out+="( ${bw_formatted} of $tb_formatted bytes Wrote )\\n"
            report_out+="`disk_temperature 1`\\n "
            report_out+="Next report at $report_percentage%\\n"
            report_out+="Calculated Write Speed: $cal_read_speed MB/s \\n"
            report_out+="Elapsed Time of current cycle: $(timer $zerotmr)\\n"
            report_out+="Total Elapsed time: $(timer $tmr)"

            send_mail "Preclear: Zeroing Disk $disk_basename" "Preclear: Zeroing Disk $disk_basename in Progress ${percent_wrote}% complete cycle $cc of $cycle_count" "$report_out" $mail_rcpt
        fi
    fi

    #bjp999 4/9/11
    echo "$disk_basename|NN|Zeroing$cycle_disp. ${percent_wrote}% @ $cal_read_speed MB/s ($(timer $tmr))|$$" > /tmp/preclear_stat_$disk_basename
fi

sleep $1
}

get_start_smart() {
  # just in case, enable SMART monitoring
  d=`basename $1`
  smartctl -s on $1 >/dev/null 2>&1
  echo "Disk: $1" >/tmp/smart_start_$d
  #smartctl -d ata -a $1 2>&1 | egrep -v "Power_On_Minutes|Temperature_Celsius" >>/tmp/smart_start_$d
  smartctl $device_type -a $1 2>&1  >>/tmp/smart_start_$d
  cp /tmp/smart_start_$d /var/log/
}

get_finish_smart() {
  d=`basename $1`
  echo "Disk: $1" >/tmp/smart_finish_$d
  #smartctl -d ata -a $1 2>&1 | egrep -v "Power_On_Minutes|Temperature_Celsius" >>/tmp/smart_finish_$d
  smartctl $device_type -a $1 2>&1  >>/tmp/smart_finish_$d
  cp /tmp/smart_finish_$d /var/log/
}

get_mid_smart() {
  d=`basename $1`
  echo "Disk: $1" >/tmp/smart_mid_${2}_$d
  #smartctl -d ata -a $1 2>&1 | egrep -v "Power_On_Minutes|Temperature_Celsius" >>/tmp/smart_mid_${2}_$d
  smartctl $device_type -a $1 2>&1  >>/tmp/smart_mid_${2}_$d
  cp /tmp/smart_mid_${2}_$d /var/log/
}

read_mbr() {
  # verify MBR boot area is clear
  out1=`dd bs=446 count=1 if=$theDisk 2>/dev/null        |sum|awk '{print $1}'`
  # verify partitions 2,3, & 4 are cleared
  out2=`dd bs=1 skip=462 count=48 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  # verify partition type byte is clear
  out3=`dd bs=1 skip=450 count=1 if=$theDisk  2>/dev/null|sum|awk '{print $1}'`
  # verify MBR signature bytes are set as expected
  out4=`dd bs=1 count=1 skip=511 if=$theDisk 2>/dev/null |sum|awk '{print $1}'`
  out5=`dd bs=1 count=1 skip=510 if=$theDisk 2>/dev/null |sum|awk '{print $1}'`

  # read bytes to verify partition 1 is set as expected.
  byte1=`dd bs=1 count=1 skip=446 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte2=`dd bs=1 count=1 skip=447 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte3=`dd bs=1 count=1 skip=448 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte4=`dd bs=1 count=1 skip=449 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`

  byte5=`dd bs=1 count=1 skip=450 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte6=`dd bs=1 count=1 skip=451 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte7=`dd bs=1 count=1 skip=452 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte8=`dd bs=1 count=1 skip=453 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`

  byte9=`dd bs=1 count=1 skip=454 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte10=`dd bs=1 count=1 skip=455 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte11=`dd bs=1 count=1 skip=456 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte12=`dd bs=1 count=1 skip=457 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`

  byte13=`dd bs=1 count=1 skip=458 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte14=`dd bs=1 count=1 skip=459 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte15=`dd bs=1 count=1 skip=460 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`
  byte16=`dd bs=1 count=1 skip=461 if=$theDisk 2>/dev/null|sum|awk '{print $1}'`

}

# add commas to a numeric argument for better readability
# yes, it is a "sed" script with a loop and a "goto"
# Found on the web, I did not write it myself. Original author unknown.
format_number() {
  echo " $1 " | sed -r ':L;s=\b([0-9]+)([0-9]{3})\b=\1,\2=g;t L'
}

analyze_for_errors() {
   err=""
   if [ "$2" = "" ]
   then
     sm_err=`analyze_smart $1`
   else
     post_err=`analyze_smart $1 $2`
     sm_err="$post_err"
   fi

   echo -e "$sm_err"
}

get_attr() {
  attribute_name=$1

  case $2 in
  old*)  file=$3 ;;
  new*)  file=$4 ;;
  esac

  case $2 in
  *val) col=3 ;;
  *thresh) col=5 ;;
  *status) col=8;;
  *raw) col=9;;
  esac
  sed "s/\([0-9]\) /\1-/" <$file | grep $attribute_name | sed 1q | awk '{print $'$col'}' | sed "s/^0\(.\)/\1/" | sed "s/^0\(.\)/\1/"
}

analyze_smart() {
     err=""
     attribute_changed=""
     chg_attr=""

     if [ "$#" = 2 ]
     then
     # look for changes in attributes.
     #First, get a list of the attributes, then for each list changes in an easy to read format.
     attributes=`cat $1 | sed -n "/ATTRIBUTE_NAME/,/^$/p" | grep -v "ATTRIBUTE_NAME" | grep -v "^$" | awk '{ print $1 "-" $2}'`
     chg_attr=`printf "%25s   %-7s %-7s %16s %-11s %9s" "ATTRIBUTE" "NEW_VAL" "OLD_VAL"  "FAILURE_THRESHOLD" "STATUS" "RAW_VALUE"`"\n"
     for i in $attributes
     do
        oldv=`get_attr $i old_val $1 $2`
        newv=`get_attr $i new_val $1 $2`
        let near=$newv-25 2>/dev/null
        newr=`get_attr $i new_raw $1 $2`
        oldr=`get_attr $i old_raw $1 $2`
        fthresh=`get_attr $i new_thresh $1 $2`
        stat=`get_attr $i new_status $1 $2`
        #echo "$i oldv=$oldv newv=$newv near=$near  newr=$newr oldr=$oldr fthresh=$fthresh stat=$stat"
        case "$i" in
        Current_Pending_Sector|Reallocated_Sector_Ct|Reallocated_Event_Count)
          if [ "$oldr" != "$newr" ]
          then
            l=`printf "%25s =   %3s     %3s          %3s        %-11s %s" $i $newv $oldv $fthresh $stat $newr`
            chg_attr="${chg_attr}${l}\n"
            attribute_changed="yes"
            continue;
          fi
        ;;
        esac
        [ "$oldv" = "253" ] && [ "$newv" = "200" -o "$newv" = "100" -o "$newv" = "253" ] && continue
        [ "$near" -le "$fthresh" -a "$stat" = "-" ] && stat="near_thresh"
        [ "$stat" = "-" -o "$stat" = "" ] && stat="ok"
        [ "$stat" = "ok" ] && [ "$oldv" = "$newv" ] && continue # not failing, and unchanged
        [ "$stat" = "ok" ] && [ "$oldv" = "100" -a "$newv" = "200" ] && continue # initialized to start value
        attribute_name="${i#*-}"
        l=`printf "%25s =   %3s     %3s          %3s        %-11s %s" $attribute_name $newv $oldv $fthresh $stat $newr`
        chg_attr="${chg_attr}${l}\n"
        attribute_changed="yes"
     done
     fi

     if [ "$attribute_changed" = "yes" ]
     then
       err="${err}** Changed attributes in files: $1  $2\n$chg_attr"
     fi

     if [ "$#" = 1 ]
     then
       smart_file=$1
     else
       smart_file=$2
     fi
     # next, check for individual attributes that have failed.
     failed_attributes=`grep 'FAILING_NOW' $smart_file| grep -v "No SMART attributes"`
     if [ "$failed_attributes" != "" ]
     then
        err="${err}\n*** Failing SMART Attributes in $smart_file *** \n"
        err="${err}ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
        err="$err$failed_attributes\n\n"
     else
        err="$err No SMART attributes are FAILING_NOW\n\n"
     fi


     if [ "$#" = 1 ]
     then
       # next, look for sectors pending re-allocation
       pending_sectors=`get_attr "Current_Pending_Sector"  old_raw $1`
       if [ "$pending_sectors" != "" ]
       then
          err="$err $pending_sectors sectors are pending re-allocation.\n"
       fi

       # look for re-allocated sectors
       reallocated_sectors=`get_attr "Reallocated_Sector_Ct" old_raw $1`
       if [ "$reallocated_sectors" != "" ]
       then
          err="$err $reallocated_sectors sectors had been re-allocated.\n"
       fi
     else
       # next, look for sectors pending re-allocation
       o_pending_sectors=`get_attr "Current_Pending_Sector"  old_raw $1`
       if [ "$o_pending_sectors" != "" ]
       then
          if [ "$o_pending_sectors" = "1" ]
          then
            err="$err $o_pending_sectors sector was pending re-allocation before the start of the preclear.\n"
          else
            err="$err $o_pending_sectors sectors were pending re-allocation before the start of the preclear.\n"
          fi
       fi
       if [ -f /tmp/smart_mid_pending_reallocate_$d ]
       then
          err="$err`cat /tmp/smart_mid_pending_reallocate_$d`\n"
       fi
       n_pending_sectors=`get_attr "Current_Pending_Sector"  new_raw $1 $2`
       if [ "$n_pending_sectors" != "" ]
       then
          if [ "$n_pending_sectors" = "1" ]
          then
            err="$err $n_pending_sectors sector is pending re-allocation at the end of the preclear,\n"
          else
            err="$err $n_pending_sectors sectors are pending re-allocation at the end of the preclear,\n"
          fi
       fi
       if [ "$o_pending_sectors" != "$n_pending_sectors" ]
       then
          let chg=$n_pending_sectors-$o_pending_sectors
          err="$err    a change of $chg in the number of sectors pending re-allocation.\n"
       else
          err="$err    the number of sectors pending re-allocation did not change.\n"
       fi

       # look for re-allocated sectors
       o_reallocated_sectors=`get_attr "Reallocated_Sector_Ct" old_raw $1 $2`
       if [ "$o_reallocated_sectors" != "" ]
       then
          if [ "$o_reallocated_sectors" = "1" ]
          then
            err="$err $o_reallocated_sectors sector had been re-allocated before the start of the preclear.\n"
          else
            err="$err $o_reallocated_sectors sectors had been re-allocated before the start of the preclear.\n"
          fi
       fi
       n_reallocated_sectors=`get_attr "Reallocated_Sector_Ct" new_raw $1 $2`
       if [ "$n_reallocated_sectors" != "" ]
       then
          if [ "$n_reallocated_sectors" = "1" ]
          then
            err="$err $n_reallocated_sectors sector is re-allocated at the end of the preclear,\n"
          else
            err="$err $n_reallocated_sectors sectors are re-allocated at the end of the preclear,\n"
          fi
       fi
       if [ "$o_reallocated_sectors" != "$n_reallocated_sectors" ]
       then
          let r_chg=$n_reallocated_sectors-$o_reallocated_sectors
          err="$err    a change of $r_chg in the number of sectors re-allocated.\n"
       else
          err="$err    the number of sectors re-allocated did not change.\n"
       fi
     fi

     # last, check overall health
     overall_state=`grep 'SMART overall-health self-assessment test result:' $smart_file | cut -d":" -f2`
     if [ "$overall_state" != " PASSED" ]
     then
        err="$err SMART overall-health status = ${overall_state}\n"
     fi

     echo -e "$err\n"
}

read_entire_disk( ) {
  # Get the disk geometry (cylinders, heads, sectors)
  fgeometry=`fdisk -l $1 2>/dev/null`
  units=`echo "$fgeometry" | grep Units | awk '{ print $8 }'`
  tu=$units
  while [ "$units" -lt 1000000 ]
  do
      let units=$units+$tu
  done
  if [ "$read_bs" != "" ]
  then
     units=$read_bs
  fi
  if [ "$units" -gt 8000000 ]
  then
  units=8388608
  fi

  if [ $short_test -eq 0 ]
  then
      total_bytes=`echo "$fgeometry" | grep "Disk $1" | awk '{ print $5 }'`
  else
      actual_bytes=`echo "$fgeometry" | grep "Disk $1" | awk '{ print $5 }'`
      total_bytes=1000000000 #Debug case..  Set total size to ~1GB disk to speed up test
      if [ "$actual_bytes" -lt "$total_bytes" ]
      then
          let total_bytes=( $actual_bytes / 10 )
      fi
  fi
  tb_formatted=`format_number $total_bytes`
  let blocks=($total_bytes / $units)
  let last_block=($blocks - 1)
  skip=0
  if [ "$read_blocks" != "" ]
  then
    bcount=$read_blocks
  else
    bcount=200 # count of blocks to read each time through the loop linearly
  fi
  end_of_disk="n"
  report_percentage=25
  read_speed=""
  echo "" >/tmp/read_speed$disk_basename

  while true
  do
    read_speed=`cat /tmp/read_speed$disk_basename 2>/dev/null`
    if [ "$2" = "postread" ]
    then
      let bytes_read=($skip * $units)
      let br=($bytes_read * 100)
      let percent_read=(${br} / $total_bytes)
      tb_read=`format_number $bytes_read`
      postread="${bold}Post-Read in progress: ${percent_read}% complete. $norm \\n( $tb_read of $tb_formatted bytes read ) $read_speed"
      nowtmr=$(timer)
      if [ $br -gt 0 ] && [ $nowtmr -ne $posttmr ]
      then
          cal_read_speed=$(($br / ($nowtmr - $posttmr) / 1000000 / 100))
      else
          cal_read_speed=0
      fi
      if [ $use_mail -ge 3 ] && [ $percent_read -eq 0 ] && [ $br -eq 0 ]
      then
          report_out="Post Read Started on $theDisk ${percent_read}% complete.  \\n"
          report_out+="( `format_number ${bytes_read}` of $tb_formatted bytes read ) \\n"
          report_out+="`disk_temperature 1`\\n"
          report_out+="Using Block size of `format_number $units` Bytes\\n "
          report_out+="Next report at $report_percentage%"

          send_mail "Preclear: Post Read Started on $disk_basename." "Preclear: Post Read Started on $disk_basename. Cycle $cc of $cycle_count" "$report_out" $mail_rcpt
      fi

      if [ $use_mail -eq 4 ]
      then
          if [ $percent_read -ge $report_percentage ]
          then
              let report_percentage=($report_percentage + 25)
              report_out="Post Read in progress on $theDisk: ${percent_read}% complete.  \\n"
              report_out+="( `format_number ${bytes_read}` of $tb_formatted bytes read )at ${read_speed}\\n"
              report_out+=" `disk_temperature 1` \\n "
              report_out+="Using Block size of `format_number $units` Bytes\\n "
              report_out+="Next report at $report_percentage% \\n"
              report_out+="Calculated Read Speed: $cal_read_speed MB/s \\n"
              report_out+="Elapsed Time of current cycle: $(timer $posttmr)\\n"
              report_out+="Total Elapsed time: $(timer $tmr)"

              send_mail "Preclear: Post Read in Progress on $disk_basename" "Preclear: Post Read in Progress on $disk_basename ${percent_read}% complete ($read_speed) cycle $cc of $cycle_count" "$report_out" $mail_rcpt
          fi
     fi
     #bjp999 4/9/11
     echo "$disk_basename|N" post_read_err "|Post-Read$cycle_disp. ${percent_read}% @ $cal_read_speed MB/s ($(timer $tmr))|$$" > /tmp/preclear_stat_$disk_basename
  fi

  if [ "$2" = "preread" ]
  then
      let bytes_read=($skip * $units)
      let br=($bytes_read * 100)
      let percent_read=(${br} / $total_bytes)
      bytes_read=`format_number $bytes_read`
      preread="${bold}Disk Pre-Read in progress: ${percent_read}% complete${norm}"
      step1="(${bytes_read} bytes of $tb_formatted read ) $read_speed"
      nowtmr=$(timer)
      if [ $br -gt 0 ] && [ $nowtmr -ne $pretmr ]
      then
          cal_read_speed=$(($br / ($nowtmr - $pretmr) / 1000000 / 100))
      else
          cal_read_speed=0
      fi
      if [ $use_mail -ge 3 ] && [ $percent_read -eq 0 ] && [ $br -eq 0 ]
      then
          report_out="Pre Read Started on $theDisk ${percent_read}% complete.  \\n"
          report_out+="( ${bytes_read} of $tb_formatted bytes read ) \\n"
          report_out+="`disk_temperature 1`\\n"
          report_out+="Using Block size of `format_number $units` Bytes\\n "
          report_out+="Next report at $report_percentage%"

          send_mail "Preclear: Pre Read Started on $disk_basename." "Preclear: Pre Read Started on $disk_basename. Cycle $cc of $cycle_count " "$report_out" $mail_rcpt
      fi

      if [ $use_mail -eq 4 ]
      then
          if [ $percent_read -ge $report_percentage ]
          then
              let report_percentage=($report_percentage + 25)
              report_out="Pre Read in progress on $theDisk: ${percent_read}% complete.  \\n"
              report_out+="( ${bytes_read} of $tb_formatted bytes read )at ${read_speed}\\n"
              report_out+=" `disk_temperature 1` \\n "
              report_out+="Using Block size of `format_number $units` Bytes\\n "
              report_out+="Next report at $report_percentage% \\n"
              report_out+="Calculated Read Speed: $cal_read_speed MB/s \\n"
              report_out+="Elapsed Time of current cycle: $(timer $pretmr)\\n"
              report_out+="Total Elapsed time: $(timer $tmr)"

              send_mail "Preclear: Pre Read in Progress on $disk_basename" "Preclear: Pre Read in Progress on $disk_basename ${percent_read}% complete ($read_speed) cycle $cc of $cycle_count" "$report_out" $mail_rcpt
         fi
     fi
     #bjp999 4/9/11
     echo "$disk_basename|NN|Pre-Read$cycle_disp. ${percent_read}% @ $cal_read_speed MB/s ($(timer $tmr))|$$" > /tmp/preclear_stat_$disk_basename
  fi

  if [ "$3" = "display_progress" ]
  then
      display_progress 0
  fi

  # Torture the disk, by reading random blocks from all over
  # calculate three (random) block numbers to be read somewhere
  # between block 1 and the max blocks on the drive, the goal is to shake the drive to an early
  # death if it has any mechanical issues, before it is holding data in our unRAID array.
  skip_b=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($blocks) ))
  skip_b2=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($blocks) ))
  skip_b3=$(( 0+(`head -c4 /dev/urandom| od -An -tu4`)%($blocks) ))

  # Dont read past the end of the disk. (Some disks do not like it at all)
  let last_block_read=($skip + $bcount - 1)
  if [ $last_block_read -gt $blocks ]
  then
    let skip=($blocks - $bcount)
    end_of_disk="y"
  fi

  (  # start of a subshell to work around 4096 "wait" bug in shell

    # read three random blocks from the disk and two fixed blocks.  We use a random
    # blocks to try to ensure they are not in the cache memory
    # and to get the disk head moving randomly on the disk.
    #
    # the two fixed blocks are "direct" read, bypassing the buffer cache.  They are the first
    # and last cylinder on the disk.

    # read a random block.
    dd if=$1 of=/dev/null count=1 bs=$units skip=$skip_b >/dev/null 2>&1 &
    rb1=$!

    # read the first block here, bypassing the buffer cache by use of iflag=direct
    dd if=$1 of=/dev/null count=1 bs=$units iflag=direct >/dev/null 2>&1 &
    rb2=$!

    # read a random block.
    dd if=$1 of=/dev/null count=1 bs=$units skip=$skip_b2 >/dev/null 2>&1 &
    rb3=$!

    # read the last block here, bypassing the buffer cache by use of iflag=direct
    dd if=$1 of=/dev/null count=1 bs=$units skip=$last_block iflag=direct >/dev/null 2>&1 &
    rb4=$!

    # read a random block.
    dd if=$1 of=/dev/null count=1 bs=$units skip=$skip_b3 >/dev/null 2>&1 &
    rb5=$!

    if [ "$2" = "preread" -o "$fast_postread" = "n" ]
    then
      # Now, also read the blocks linearly, from start to end, $bcount cylinders at a time.
      read_speed=`dd if=$1 bs=$units of=/dev/null count=$bcount skip=$skip conv=noerror 2>&1|awk -F',' 'END{print $NF}'`
      echo $read_speed >/tmp/read_speed$disk_basename
      if [ "$2" = "postread" -a "$skip_postread_verify" = "no" ]
      then
        # first block must be treated differently
        if [ "$skip" != "0" ]
        then
          # verify all zeros... complain if not. This read should be fast, as blocks should be in cache from prior read.
          rsum=`dd if=$1 bs=$units count=$bcount skip=$skip conv=noerror 2>/dev/null|sum| awk '{print $1}'`
          if [ "$rsum" != "00000" ]
          then
             echo "skip=$skip count=$bcount bs=$units returned $rsum instead of 00000" >>/tmp/postread_errors$disk_basename
             post_read_err="Y"          #bjp999 4/9/11
          fi
        else
            rsum=`dd if=$1 bs=512 count=8192 skip=1 conv=noerror 2>/dev/null|sum| awk '{print $1}'`
            if [ "$rsum" != "00000" ]
            then
               echo "skip=0 bs=512 count=8192 returned $rsum instead of 00000" >>/tmp/postread_errors$disk_basename
               post_read_err="Y"        #bjp999 4/9/11
            fi
        fi
      fi
    else
      read_speed=`readvz if=$1 bs=$units count=$bcount skip=$skip memcnt=50 2>>/tmp/postread_errors$disk_basename | awk '{ print $8,$9 }'`
      echo $read_speed >/tmp/read_speed$disk_basename
      if [ -s /tmp/postread_errors$disk_basename ]
      then
         post_read_err="Y"
      fi
    fi

    kill -0 $rb1 2>/dev/null && wait $rb1 # make sure the background random blocks are read before continuing
    kill -0 $rb2 2>/dev/null && wait $rb2 # make sure the background random blocks are read before continuing
    kill -0 $rb3 2>/dev/null && wait $rb3 # make sure the background random blocks are read before continuing
    kill -0 $rb4 2>/dev/null && wait $rb4 # make sure the background random blocks are read before continuing
    kill -0 $rb5 2>/dev/null && wait $rb5 # make sure the background random blocks are read before continuing

   ) # end of the subshell to work around the 4096 "wait" bug in bash 3.2

   if [ "$end_of_disk" = "y" ]
   then
      break
   fi
   if [ $preread_skip_pct -gt 0 ]
   then
      #echo "skip=$skip"
      #echo "blocks=$blocks"
      #echo "bcount=$bcount"
      #let skip=($blocks * $preread_skip_pct / 100 / $bcount * $bcount)
      #echo "newskip=$skip"
      #exit

      #let skip=($blocks * $preread_skip_pct % 100)

      let skip=($blocks * $preread_skip_pct / 100 / $bcount * $bcount)
      preread_skip_pct=0
   else
      let skip=($skip + $bcount)
   fi

 done

 if [ "$2" = "postread" ]
 then
    dposttmr=$(timer $posttmr) #calculate post read cycle time
    postdonetmr=$(timer)
    cal_post_read_speed=$(($br / ($postdonetmr - $posttmr) / 1000000 / 100))
    postread="Disk Post-Clear-Read completed                                ${bold}DONE${norm}"
    if [ $use_mail -ge 3 ]
    then
        report_out="Post Read finished on $theDisk \\n"
        report_out+="( ${tb_read} of $tb_formatted bytes read )\\n"
        report_out+="Post Read Elapsed Time: $dposttmr \\n"
        report_out+="Total Elapsed Time: $(timer $tmr)\\n"
        report_out+="`disk_temperature 1`\\n"
        report_out+="Using Block size of `format_number $units` Bytes\\n "
        report_out+="Calculated Read Speed - $cal_post_read_speed MB/s"

        send_mail "Preclear: Post Read finished on $disk_basename." "Preclear: Post Read finished on $disk_basename. Cycle $cc of $cycle_count" "$report_out" $mail_rcpt
    fi
  fi
  if [ "$2" = "preread" ]
  then
    dpretmr=$(timer $pretmr) #Calculate Pre-read cycle time
    predonetmr=$(timer)
    preread="Disk Pre-Clear-Read completed                                 ${bold}DONE${norm}"
    cal_pre_read_speed=$(($br / ($predonetmr - $pretmr) / 1000000 / 100))
    step1=""
    if [ $use_mail -ge 3 ]
    then
        report_out="Pre Read finished on $theDisk \\n"
        report_out+="( `format_number ${bytes_read}` of $tb_formatted bytes read) \\n "
        report_out+="Pre Read Elapsed Time: $dpretmr \\n"
        report_out+="Total Elapsed Time: $(timer $tmr)\\n"
        report_out+="`disk_temperature 1`\\n"
        report_out+="Using Block size of `format_number $units` Bytes\\n "
        report_out+="Calculated Read Speed - $cal_pre_read_speed MB/s"

        send_mail "Preclear: Pre Read finished on $disk_basename." "Preclear: Pre Read finished on $disk_basename. Cycle $cc of $cycle_count" "$report_out" $mail_rcpt
    fi
  fi
  if [ "$3" = "display_progress" ]
  then
    display_progress 0
  fi
}

if [ -x /usr/bin/tput ]
then
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

#----------------------------------------------------------------------------------
# Verify the disk is a block device
#----------------------------------------------------------------------------------
if [ ! -b $theDisk ]
then
    echo $clearscreen$screen_line_three
    echo "Sorry: $theDisk does not exist as a block device"
    echo "Clearing will NOT be performed"
    exit 2
fi

# read the disk config file to see if the disk is assigned to the array, just in case this
# command is run with the array stopped.
exec </boot/config/disk.cfg
#cat /boot/config/disk.cfg | while read config
while read config
do
        device=""
        case $config in
        diskSpinupGroup*|diskSpindownDelay*|diskSecurity*|diskExport*|diskRead*|diskWrite*)
        continue;
        ;;
        parity*|disk[0-9]*|cache*)
        disk=`echo  "$config" | sed "s/\([^=]*\)=\([^=]*\)/\1/"`
        disks[$a]=$disk
        id=`echo  "$config" | sed -e "s/\([^=]*\)=\([^=]*\)/\2/" -e "s/\\r//"`
        device=`ls --time-style='+%Y-%m-%d %I:%M%p' -ld /dev/disk/by-path/$id 2>/dev/null | awk '{ print substr($10,7,3) }'`
        if [ "$device" = "" ]
        then
           device=`ls /sys/devices/$id/block 2>/dev/null`
           if [ "$device" = "" ]
           then
             device=`ls --time-style='+%Y-%m-%d %I:%M%p' -ld /dev/disk/by-id/*$id*  2>/dev/null | sed 1q |  awk '{ print substr($10,7,3) }'`
           fi
        fi
        ;;
        esac
        device=/dev/$device
        if [ "$theDisk" = "$device" ]
        then
          echo $clearscreen$screen_line_three
          echo "Sorry, $theDisk is already assigned as part of the unRAID array."
          echo "Clearing will NOT be performed"
          exit 2
        fi
done
exec </dev/tty

#----------------------------------------------------------------------------------
# First, do some basic tests to ensure the disk  is not part of the arrray
# and not mounted, and not in use in any way.
#----------------------------------------------------------------------------------
devices=`/usr/local/sbin/mdcmd status | cat -v | grep rdevName | sed 's/\([^=]*\)=\([^=]\)/\/dev\/\2/'`

echo $devices | grep $theDisk >/dev/null 2>&1
if [  $? = 0 ]
then
    echo $clearscreen$screen_line_three
    echo "Sorry, but $theDisk is already assigned as part of the unRAID array."
    echo "Clearing will NOT be performed"
    exit 2
fi

#----------------------------------------------------------------------------------
#Check to see if the disk is mounted, this should detect the cache drive and the boot drive.
#----------------------------------------------------------------------------------
m=`mount | grep $theDisk 2>/dev/null`
if [ $? = 0 ]
then
    echo $clearscreen$screen_line_three
    echo "Sorry, but $theDisk is currently mounted and in use:"
    echo "$m"
    echo "Clearing will NOT be performed"
    exit 2
fi


#----------------------------------------------------------------------------------
# first verify the device is not busy
#----------------------------------------------------------------------------------
blockdev --rereadpt $theDisk
ret=$?
if [ $ret != 0 ]
then
    #If device is busy, exit here.
    echo "Sorry: Device $theDisk is busy.: $ret"
    exit 2
fi

#----------------------------------------------------------------------------------
# Is the disk responding at all?
#----------------------------------------------------------------------------------
fgeometry=`fdisk -l $theDisk 2>/dev/null`
if [ "$fgeometry" = "" ]
then
    echo "Sorry: Device $theDisk is not responding to an fdisk -l $theDisk command."
    echo "You might try power-cycling it to see if it will start responding."
    exit 2
fi

# get the disk size to determine if a GPT partition will be used by unRAID
disk_blocks=`blockdev --getsz $theDisk  | awk '{ print $1 }'`
max_mbr_blocks=`printf "%d" $max_mbr_size`
if [ $disk_blocks -ge $max_mbr_blocks ]
then
  over_mbr_size="y"
fi

#----------------------------------------------------------------------------------
# Get the disk geometry (cylinders, heads, sectors)
#----------------------------------------------------------------------------------
#geometry=`sfdisk -g $theDisk | grep sectors`
#num_cylinders=`echo "$geometry" | awk '{ print $2 }'`
#num_heads=`echo "$geometry" | awk '{ print $4 }'`
if [ "$partition_64" = "y" ]
then
  num_sectors=64
else
  #num_sectors=`echo "$geometry" | awk '{ print $6 }'`
  num_sectors=63
fi

#----------------------------------------------------------------------------------
# Calculate the partition size we will create and where we will start it.
# unRAID starts its first partition on a full cylinder. To be recognized as zeroed,
# we need to create an initial partition the same way.
#----------------------------------------------------------------------------------
full_size=`blockdev --getsz $theDisk  | awk '{ print $1 }'`
let partition_size=($full_size - $num_sectors)
partition_start=$num_sectors
#echo SIZE $partition_size
#echo START $partition_start
size1=0
size2=0
if [ "$over_mbr_size" = "y" ]
then
    size1=`printf "%d" "0x00020000"`
    size2=`printf "%d" "0xFFFFFF00"`
    partition_start=64
    partition_size=`printf "%d" 0xFFFFFFFF`
fi



verify_mbr() {
  read_mbr
  if [ "$out1" != "00000" -o "$out2" != "00000" -o "$out3" != "00000" -o "$out4" != "00170" -o "$out5" != "00085" ]
  then
    cleared_ok="n"
    echo "failed test 1"
  fi

#failed test 2 00000 00000 00002 00000
#failed test 3 00000 00255 00255 00255

  if [ "$over_mbr_size" != "y" ]
  then
      if [ "$byte1" != "00000" -o "$byte2" != "00000" -o "$byte3" != "00000" -o "$byte4" != "00000" ]
      then
      cleared_ok="n"
      echo "failed test 2 $byte1 $byte2 $byte3 $byte4"
      fi

      if [ "$byte5" != "00000" -o "$byte6" != "00000" -o "$byte7" != "00000" -o "$byte8" != "00000" ]
      then
      cleared_ok="n"
      echo "failed test 3 $byte5 $byte6 $byte7 $byte8"
      fi
  else
      if [ "$byte1" != "00000" -o "$byte2" != "00000" -o "$byte3" != "00002" -o "$byte4" != "00000" ]
      then
      cleared_ok="n"
      echo "failed test 2 $byte1 $byte2 $byte3 $byte4"
      fi

      if [ "$byte5" != "00000" -o "$byte6" != "00255" -o "$byte7" != "00255" -o "$byte8" != "00255" ]
      then
      cleared_ok="n"
      echo "failed test 3 $byte5 $byte6 $byte7 $byte8"
      fi
  fi

  byte9h=`echo $byte9|awk '{printf("%02x", $1)}'`
  byte10h=`echo $byte10|awk '{printf("%02x", $1)}'`
  byte11h=`echo $byte11|awk '{printf("%02x", $1)}'`
  byte12h=`echo $byte12|awk '{printf("%02x", $1)}'`
  byte13h=`echo $byte13|awk '{printf("%02x", $1)}'`
  byte14h=`echo $byte14|awk '{printf("%02x", $1)}'`
  byte15h=`echo $byte15|awk '{printf("%02x", $1)}'`
  byte16h=`echo $byte16|awk '{printf("%02x", $1)}'`
  sc=`printf "%d" "0x"$byte12h$byte11h$byte10h$byte9h`
  sl=`printf "%d" "0x"$byte16h$byte15h$byte14h$byte13h`

  case "$sc" in
  63|64)
    if [ "$over_mbr_size" == "y" ]; then
      partition_size=$(printf "%d" 0xFFFFFFFF)
    else
      let partition_size=($full_size - $sc)
    fi
  ;;
  1)
     if [ "$over_mbr_size" != "y" ]
     then
        cleared_ok="n"
        echo "failed test 4"
     fi
  ;;
  *)
    cleared_ok="n"
    echo "failed test 5"
  ;;
  esac

  if [ $partition_size -ne $sl ]
  then
    cleared_ok="n"
    echo "failed test 6"
  fi
}

echo $clearscreen$goto_top${bold}Pre-Clear unRAID Disk $theDisk$norm
echo "################################################################## $ver"
# first, try without a device type.
sm_out=`smartctl -a $theDisk`
smartstat=$(($? & 7))
if [ "$smartstat" = "0" ]
then
    device_type=""
fi
sm_out=`smartctl -a $device_type $theDisk`
smartstat=$(($? & 7))
if [ "$smartstat" != "0" ]
then
    echo
    if [ "$device_type" != "" ]
    then
        echo "${bold}smartctl may not be able to run on $theDisk with the $device_type option.${norm}"
    else
        echo "${bold}smartctl may not be able to run on $theDisk.${norm}"
    fi
    echo "${bold}however this should not affect the clearing of a disk.${norm}"
    echo "smartctl exit status = $smartstat"
    echo "$sm_out"$
    echo "${bold}Do you wish to continue?${norm}"
    echo -n "(Answer ${ul}Yes${noul} to continue. Capital 'Y', lower case 'es'): "

    if [ "$noprompt" = "y" ]      #bjp999 7-17-11
    then
       ans="Yes"                  #bjp999 7-17-11
    else                          #bjp999 7-17-11
       read ans
    fi                            #bjp999 7-17-11

    if [ "$ans" != "Yes" ]
    then
       exit 1
    fi
fi

echo "$sm_out" | awk '/Model/,/User Capacity/'
model=`echo "$sm_out" | awk '/Device Model/ { printf substr($0, 18,length($0)) }' | tr -d ' '`
serial=`echo "$sm_out" | awk '/Serial Number/ { printf substr($0, 18,length($0)) }' | tr -d ' '`
if [ "$convert_flag" != "y" -a "$zero_mbr_only" != "y" ]
then
  fdisk -l -u $theDisk
fi
echo "########################################################################"

if [ "$test_only_flag" = "y" ]
then
  cleared_ok="y"
  verify_mbr

  if [ "$cleared_ok" != "y" ]
  then
    echo "========================================================================$ver"
    echo "=="
    echo "== Disk $theDisk is ${bold}NOT${norm} precleared"
    echo "==" $sc $sl $partition_size
    echo "============================================================================"
  else
    echo "========================================================================$ver"
    echo "=="
    if [ "$over_mbr_size" != "y" ]
    then
        echo "== ${bold}DISK $theDisk IS PRECLEARED${norm} with a starting sector of $sc"
    else
        echo "== ${bold}DISK $theDisk IS PRECLEARED${norm} with a GPT Protective MBR"
    fi
    echo "=="
    echo "============================================================================"
  fi
  exit 0
fi

if [ "$convert_flag" = "y"  -a "$verify_only" = "n" ]
then
  cleared_ok="y"
  echo " Converting existing pre-cleared disk to start partition on sector $convert_type"
  echo "========================================================================$ver"
  echo -n " Step 1. Verifying existing pre-clear signature prior to conversion.  "
  verify_mbr
  sleep 1
  echo "${bold}DONE${norm}"

  if [ "$cleared_ok" != "y" ]
  then
    echo "========================================================================$ver"
    echo "=="
    echo "== Disk $theDisk is ${bold}NOT${norm} precleared"
    echo "== Conversion not possible"
    echo "==" $sc $sl $partition_size
    echo "============================================================================"
    exit 2
  fi
  if [ "$over_mbr_size" = "y" ]
  then
    echo "========================================================================$ver"
    echo "=="
    echo "== ${bold}DISK $theDisk IS PRECLEARED${norm} with a GPT Protective MBR"
    echo "== Conversion not possible.  All GPT partitions are automatically 4k aligned."
    echo "=="
    echo "============================================================================"
    exit 2
  fi
  case "$convert_type" in
  63)
    #convert precleared sector 64 to precleared sector 63
    if [ "$sc" = "63" ]
    then
       echo "========================================================================$ver"
       echo "== ${bold}DISK $theDisk IS ALREADY PRECLEARED with a starting sector of $sc${norm}"
       echo "=="
       echo "== Conversion is not needed."
       echo "============================================================================"
       exit 2
    else
      partition_start=63
      let partition_size=($partition_size + 1)
    fi
  ;;
  64)
    #convert precleared sector 63 to precleared sector 64
    if [ "$sc" = "64" ]
    then
       echo "========================================================================$ver"
       echo "== ${bold}DISK $theDisk IS ALREADY PRECLEARED with a starting sector of $sc${norm}"
       echo "=="
       echo "== Conversion is not needed."
       echo "============================================================================"
       exit 2
    else
      partition_start=64
      let partition_size=($partition_size - 1)
    fi
  ;;
  esac
  echo -n " Step 2. converting existing pre-clear signature:  "
  awk 'BEGIN{
  printf ("%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c",
  0,0,0,0,0,0,0,0,
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),7,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),5,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),3,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[1]),1,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),7,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),5,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),3,2)),
  strtonum("0x" substr(sprintf( "%08x\n", ARGV[2]),1,2)))
  }' $partition_start $partition_size | dd seek=446 bs=1 count=16 of=$theDisk 2>/dev/null
  sleep 1
  echo "${bold}DONE${norm}"
  echo "========================================================================$ver"
  echo "=="
  echo "== Conversion complete."
  echo "== ${bold}DISK $theDisk is now PRECLEARED with a starting sector of $partition_start${norm}"
  echo "=="
  echo "============================================================================"
  exit 0
fi

# validate the disk basename is the full disk and not a partition
bn="$(basename ${theDisk} | sed 's/[^0-9]//g')"
if [ ! -z "$bn" ]
then
        echo "error: $disk_basename is not a valid three character device name." >&2
        exit 2
fi

get_start_smart $theDisk >/dev/null 2>&1
# If the drive has already failed, warn the user now
d=`basename $1`
errs=`analyze_for_errors /tmp/smart_start_$d | grep FAILING_NOW | grep -v "No SMART attributes"`
if [ "$errs" != "" ]
then
        echo "error: $disk_basename is currently failing SMART tests." >&2
        echo "$errs"
        echo "Full SMART report is in /tmp/smart_start_$d"
        echo "########################################################################"
fi

echo "invoked as " $progname $options
echo "########################################################################"
if [ "$partition_64" = "y" ]
then
  if [ "$verify_only" = "y" ]
  then
    #echo "the '-A' option requests this disk be verified for a 4k-aligned partition"
    echo $vdefault
  else
    if [ "$zero_mbr_only" = "y" ]
    then
      echo "The -z option requests that $theDisk will have its MBR zeroed"
    else
      echo $default
    fi
  fi
else
  if [ "$verify_only" = "y" ]
  then
    #echo "This disk will be verified for a traditional partition starting on sector 63."
    echo $vdefault
  else
    if [ "$zero_mbr_only" = "y" ]
    then
      echo "The -z option requests that $theDisk will have its MBR zeroed"
    else
      #echo "This disk will be cleared for a traditional partition starting on sector 63."
      echo $default
      echo "(it will not be 4k-aligned)"
    fi
  fi
fi

if [ "$zero_mbr_only" = "y" ]
then
  echo "Are you absolutely sure you want to zero the MBR of this drive?"
  echo -n "(Answer ${ul}Yes${noul} to continue. Capital 'Y', lower case 'es'): "

  if [ "$noprompt" = "y" ]      #bjp999 7-17-11
  then
     ans="Yes"                  #bjp999 7-17-11
  else                          #bjp999 7-17-11
     read ans
  fi                            #bjp999 7-17-11

  if [ "$ans" = "Yes" ]
  then
    echo "zeroing MBR only"
    dd if=/dev/zero bs=512 count=1 of=$theDisk
    hdparm -z $theDisk # Reload partition table
    echo "zeroing MBR complete."
    echo "Verification of MBR zero starting."
    read_mbr
    if [ "$out1" != "00000" -o "$out2" != "00000" -o "$out3" != "00000" -o "$out4" != "00000" -o "$out5" != "00000" ]
    then
      echo "==================================================$ver"
      echo "== ${bold}SORRY: Disk $theDisk MBR was NOT zeroed${norm}"
      echo "======================================================"
    else
      echo "==================================================$ver"
      echo "== ${bold}Disk $theDisk MBR was zeroed${norm}"
      echo "======================================================"
    fi
  else
      echo "==================================================$ver"
      echo "== ${bold}Disk $theDisk was not changed${norm}"
      echo "======================================================"
  fi
  exit
fi

if [ "$verify_only" = "y" ]
then
  echo "Are you sure you want to verify this drive? (it will not be writen to at all)"
else
  echo "Are you absolutely sure you want to clear this drive?"
fi
echo -n "(Answer ${ul}Yes${noul} to continue. Capital 'Y', lower case 'es'): "
if [ "$noprompt" = "y" ]      #bjp999 7-17-11
then
   ans="Yes"                  #bjp999 7-17-11
else                          #bjp999 7-17-11
   read ans
fi                            #bjp999 7-17-11
if [ "$ans" != "Yes" ]
then
    if [ "$verify_only" = "y" ]
    then
      echo "${bold}Verification will NOT be performed${norm}"
    else
      echo "${bold}Clearing will NOT be performed${norm}"
    fi
    exit 2
fi
tmr=$(timer)
cyctmr=$tmr  #Set start of 1st cycle timer
disk_start_temp=`smartctl $device_type -A $theDisk | grep -i temperature | sed 1q | awk '{ print $10; }'`

let cc=0

get_start_smart $theDisk >/dev/null 2>&1
mid_err=""
cp /dev/null /tmp/smart_mid_pending_reallocate_$d

while test $cc -lt $cycle_count
do
  preread=""
  step1=""
  step2=""
  step3=""
  step4=""
  step5=""
  step6=""
  step7=""
  step8=""
  step9=""
  step10=""
  postread=""
  let cc=($cc+1)

  #bjp999 4/9/11 V
  if [ $cycle_count -eq 1 ]
  then
     cycle_disp=""
  else
     cycle_disp=" ($cc of $cycle_count)"
  fi
  #bjp999 4/9/11 ^

  if [ "$pre_read_flag" = "y" -a $cc = 1 ]
  then
   pretmr=$(timer) #get preread start time
   read_entire_disk $theDisk preread display_progress
   get_mid_smart $theDisk preread$cc >/dev/null 2>&1

   m_pending_sectors=`get_attr "Current_Pending_Sector"  old_raw /tmp/smart_mid_preread${cc}_$d`
   if [ "$m_pending_sectors" != "" ]
   then
      if [ "$m_pending_sectors" = "1" ]
      then
        echo " $m_pending_sectors sector was pending re-allocation after pre-read in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
      else
        echo " $m_pending_sectors sectors were pending re-allocation after pre-read in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
      fi
   fi
  fi

  zerotmr=$(timer) #get zeroing start time
  if [ "$verify_only" != "y" ]
  then

  step1="${bold}Step 1 of 10 - Copying zeros to first 2048k bytes${norm}"
  display_progress 5

  #----------------------------------------------------------------------------------
  # skip the MBR, clear remainder of first 2048k bytes.
  #----------------------------------------------------------------------------------
  dd if=/dev/zero bs=512 seek=1 of=$theDisk  count=4096 2>/dev/null
  hdparm -z $theDisk   # Reload the partition table
  step1="Step 1 of 10 - Copying zeros to first 2048k bytes             ${bold}DONE${norm}"

  display_progress 5

  step2="${bold}Step 2 of 10 - Copying zeros to remainder of disk to clear it ${norm}"
  step3=" **** This will take a while... you can follow progress below:"

  report_percentage=25
  if [ $use_mail -ge 3 ]
  then
      report=$(echo -e "Zeroing Disk $theDisk Started.  \\n`disk_temperature 1`\\n")
      send_mail "Preclear: Zeroing Disk $disk_basename Started." "Preclear: Zeroing Disk $disk_basename Started. Cycle $cc of $cycle_count" "$report" $mail_rcpt
  fi



  display_progress 5

  #----------------------------------------------------------------------------------
  # Get total bytes so we can calculate percentage done. (if Pre read wasn't run)
  #----------------------------------------------------------------------------------
  fgeometry=`fdisk -l $1`
  units=`echo "$fgeometry" | grep Units | awk '{ print $8 }'`
  if [ $short_test -eq 0 ]
      then
      total_bytes=`echo "$fgeometry" | grep "Disk $1" | awk '{ print $5 }'`
  else
      total_bytes=8589934592   # 2048k * 4096
      #total_bytes=1073741824   # 2048k * 512
  fi

  tb_formatted=`format_number $total_bytes`



  #----------------------------------------------------------------------------------
  # clear remainder of drive from MBR onward (using a slightly larger block size)
  #----------------------------------------------------------------------------------
  #  dd if=/dev/zero bs=2048k seek=1 of=$theDisk &
  if [ "$write_bs" = "" ]
  then
    write_bs="2048k"
  fi

  if [ $short_test -eq 0 ]
  then
      dd if=/dev/zero bs=$write_bs seek=$sb of=$theDisk  2> /tmp/zero$disk_basename &
  else
      dd if=/dev/zero bs=2048k seek=1 of=$theDisk count=4096 2> /tmp/zero$disk_basename & #If short test is requested..  Do only 4096 blocks
  fi
  dd_pid=$!

  # if we are interrupted, kill the background zero of the disk.
  trap 'kill $dd_pid 2>/dev/null;exit' 2
  while kill -0 $dd_pid >/dev/null 2>&1
  do
      display_progress 10 $dd_pid
  done
  get_mid_smart $theDisk after_zero$cc >/dev/null 2>&1
#/tmp/smart_mid_after_zero1_sdb
#/tmp/smart_mid_after_zero2_sdb
#/tmp/smart_mid_after_zero3_sdb
#/tmp/smart_mid_pending_reallocate_sdb
#/tmp/smart_mid_post_read1_sdb
#/tmp/smart_mid_post_read2_sdb
#/tmp/smart_mid_preread1_sdb
  m_pending_sectors=`get_attr "Current_Pending_Sector"  old_raw /tmp/smart_mid_after_zero${cc}_$d`
  if [ "$m_pending_sectors" != "" ]
  then
     if [ "$m_pending_sectors" = "1" ]
     then
        echo " $m_pending_sectors sector was pending re-allocation after zero of disk in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
     else
        echo " $m_pending_sectors sectors were pending re-allocation after zero of disk in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
     fi
  fi
  dzerotmr=$(timer $zerotmr)
  zerodonetmr=$(timer)
  cal_zero_write_speed=$(($total_bytes / ($zerodonetmr - $zerotmr) / 1000000 ))
  if [ $use_mail -ge 3 ]
      then
      report_out="Zeroing Disk $theDisk Done. \\n"
      report_out+="Zeroing Elapsed Time: $dzerotmr \\n"
      report_out+="Total Elapsed Time: $(timer $tmr)\\n"
      report_out+="`disk_temperature 1`\\n"
      report_out+="Calculated Write Speed: $cal_zero_write_speed MB/s"

      send_mail "Preclear: Zeroing Disk $disk_basename Done." "Preclear: Zeroing Disk $disk_basename Done. Cycle $cc of $cycle_count" "$report_out" $mail_rcpt
  fi

  #bjp999 4/9/11
  echo "$disk_basename|NN|Creating Boot Record /^nPartition Table ($(timer $tmr))|$$" > /tmp/preclear_stat_$disk_basename

  step2="Step 2 of 10 - Copying zeros to remainder of disk to clear it ${bold}DONE${norm}"
  step3="Step 3 of 10 - Disk is now cleared from MBR onward.           ${bold}DONE${norm}"

  display_progress 5

  step4="${bold}Step 4 of 10 - Clearing MBR bytes for partition 2,3 & 4${norm}"

  display_progress 5

  dd if=/dev/zero bs=1 seek=462 count=48 of=$theDisk

  step4="Step 4 of 10 - Clearing MBR bytes for partition 2,3 & 4       ${bold}DONE${norm}"

  display_progress 5

  step5="${bold}Step 5 of 10 - Clearing MBR code area${norm}"

  display_progress 5

  dd if=/dev/zero bs=446 count=1 of=$theDisk

  step5="Step 5 of 10 - Clearing MBR code area                         ${bold}DONE${norm}"

  display_progress 5

  step6="${bold}Step 6 of 10 - Setting MBR signature bytes${norm}"

  display_progress 2

  # set MBR signature in last two bytes in MBR
  # two byte MBR signature
  echo -ne "\0252" | dd bs=1 count=1 seek=511 of=$theDisk
  echo -ne "\0125" | dd bs=1 count=1 seek=510 of=$theDisk

  step6="Step 6 of 10 - Setting MBR signature bytes                    ${bold}DONE${norm}"

  display_progress 5

  step7="${bold}Step 7 of 10 - Setting partition 1 to precleared state${norm}"

  display_progress 5

  #----------------------------------------------------------------------------------
  # Create the partition data for the 16 bytes in the MBR that define the first
  # partition.  Write it to the MBR.  These bytes define the start and
  # end of the first partition
  #----------------------------------------------------------------------------------
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
  }' $size1 $size2 $partition_start $partition_size | dd seek=446 bs=1 count=16 of=$theDisk

  step7="Step 7 of 10 - Setting partition 1 to precleared state        ${bold}DONE${norm}"

  step8="${bold}Step 8 of 10 - Notifying kernel we changed the partitioning${norm}"
  display_progress 5

  # let the kernel know we changed the partitioning
  blockdev --rereadpt $theDisk

  step8="Step 8 of 10 - Notifying kernel we changed the partitioning   ${bold}DONE${norm}"
  step9="${bold}Step 9 of 10 - Creating the /dev/disk/by* entries${norm}"

  display_progress 5

  #----------------------------------------------------------------------------------
  # create the /dev/disk/by* entries
  #----------------------------------------------------------------------------------
  /etc/rc.d/rc.udev restart 2>/dev/null 2>&1
  sleep 5

  step9="Step 9 of 10 - Creating the /dev/disk/by* entries             ${bold}DONE${norm}"
  step10="${bold}Step 10 of 10 - Verifying the clear has been successful.${norm}"

  display_progress 5
  else
    step7="Step 1 through 9 skipped, verify phase will be performed next"
  fi

  #now, verify the clear has been successful. Set the "out" variables...

  if [ "$verify_only" = "y" ]
  then
     step1="invoked as: $progname $options"
     step8="Step 10 of 10 - Verifying if the MBR is cleared."
     display_progress 1
     cleared_ok="y" # assume ok until we learn otherwise
     verify_mbr
     if [ "$cleared_ok" = "y" ]
     then
        if [ "$sc" = "63" -a "$partition_64" = "y" ]
        then
           step8="Step 10 of 10 - ${bold}The MBR is NOT verified 4k-aligned cleared.${norm}"
           step9="${bold}However, its MBR is verified for non-4k-alignment${norm}"
           step10="${bold}starting on sector 63${norm}"
        else
          if [ "$sc" = "64" -a "$partition_64" = "n" ]
          then
             step8="Step 10 of 10 - ${bold}The MBR is NOT verified un-aligned cleared.${norm}"
             step9="${bold}However, its MBR is verified cleared for 4k-alignment${norm}"
             step10="${bold}starting on sector 64${norm}"
          else
             step9="Step 10 of 10 - The MBR is verified cleared         ${bold}DONE${norm}"
             step10="for a partition starting on sector $sc."
          fi
        fi
     else
        step9="Step 10 of 10 - ${bold}The MBR is NOT verified cleared.${norm}     ${bold}DONE${norm}"
     fi
  else
     read_mbr
     step10="Step 10 of 10 - Verifying if the MBR is cleared.              ${bold}DONE${norm}"
  fi



  display_progress 1

  if [ "$out1" != "00000" -o "$out2" != "00000" -o "$out3" != "00000" -o "$out4" != "00170" -o "$out5" != "00085" ]
  then
    echo "========================================================================$ver"
    echo "=="
    if [ "$verify_only" = "y" ]
    then
      echo "== ${bold}SORRY: Disk $theDisk MBR NOT precleared${norm}"
    else
      echo "== ${bold}SORRY: Disk $theDisk MBR could NOT be precleared${norm}"
    fi
    echo "=="
    [ "$out1" != "00000" ] && echo "== out1= $out1"
    [ "$out2" != "00000" ] && echo "== out2= $out2"
    [ "$out3" != "00000" ] && echo "== out3= $out3"
    [ "$out4" != "00170" ] && echo "== out4= $out4"
    [ "$out5" != "00085" ] && echo "== out5= $out5"
    [ "$postread_error" != "" ] && echo "== $postread_error"
    echo "============================================================================"
    dd if=$theDisk count=1 | od -x
    if [ $use_mail -ge 1 ]
    then
        if [ "$verify_only" = "y" ]
        then
            send_mail "Preclear: FAIL! Verify Disk $disk_basename Failed!!!" "Preclear: FAIL! Verify Disk $disk_basename Failed!!! Cycle $cc of $cycle_count" "Preclear Verify Disk $theDisk FAILED!!!!.  " $mail_rcpt
        else
            send_mail "Preclear: FAIL! Preclearing Disk $disk_basename Failed!!!" "Preclear: FAIL! Preclearing Disk $disk_basename Failed!!! Cycle $cc of $cycle_count" "Preclear Disk $theDisk FAILED!!!!.  " $mail_rcpt
        fi
    fi

    exit 1
  else
    if [ "$post_read_flag" = "y" ]
    then
      posttmr=$(timer) #get postread start time
      read_entire_disk $theDisk postread display_progress
      if [ "$cc" != "$cycle_count" ]
      then
         get_mid_smart $theDisk post_read$cc >/dev/null 2>&1
         m_pending_sectors=`get_attr "Current_Pending_Sector"  old_raw /tmp/smart_mid_post_read${cc}_$d`
         if [ "$m_pending_sectors" != "" ]
         then
            if [ "$m_pending_sectors" = "1" ]
            then
                echo " $m_pending_sectors sector was pending re-allocation after post-read in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
            else
                echo " $m_pending_sectors sectors were pending re-allocation after post-read in cycle $cc of $cycle_count." >>/tmp/smart_mid_pending_reallocate_$d
            fi
         fi
      fi
    fi
  fi
  dcyctmr=$(timer $cyctmr) #delta cycle time
  cyctmr=$(timer)  #reset cycle timer
  if [ $use_mail -ge 2 ]
      then
      if [ $cc -lt $cycle_count ]
          then
          report_out="========================================================================$ver\n"
          report_out+="==\n"
          report_out+="== Disk $theDisk has successfully finished a preclear cycle\n"
          report_out+="==\n"
          report_out+="== Finished Cycle $cc of $cycle_count cycles\n"
          report_out+="==\n"
          if [ "$pre_read_flag" = "y" ]  # Don't report Pre read time if there was no Pre/Post read.
          then
              report_out+="== Using read block size = `format_number ${units}` Bytes\n"
              report_out+="== Last Cycle's Pre Read Time  : $dpretmr ($cal_pre_read_speed MB/s)\n"
          fi
          if [ "$verify_only" != "y" ] # Don't report zero time if there was no zeroing.
          then
              report_out+="== Last Cycle's Zeroing time   : $dzerotmr ($cal_zero_write_speed MB/s)\n"
          fi
          if [ "$pre_read_flag" = "y" ] # Don't report Post read time if there was no Pre/Post read.
          then
              report_out+="== Last Cycle's Post Read Time : $dposttmr ($cal_post_read_speed MB/s)\n"
          fi
          report_out+="== Last Cycle's Total Time     : $dcyctmr\n"
          report_out+="==\n"
          report_out+="== Total Elapsed Time $(timer $tmr)\n"
          report_out+="==\n"
          if [ "$disk_start_temp" != "" ] # Don't report Disk Temperature if there was no Temp reported.
          then
              report_out+="== Disk Start Temperature: ${disk_start_temp}C\n"
              report_out+="==\n"
              report_out+="== Current `disk_temperature 1`\n"
          fi
          report_out+="==\n"
          report_out+="== Starting next cycle\n"
          report_out+="==\n"
          report_out+="========================================================================$ver\n"
          send_mail "Preclear: Disk $disk_basename PASSED cycle $cc!" "Preclear: Disk $disk_basename PASSED cycle $cc! Starting Next cycle" "$report_out" $mail_rcpt
      fi
  fi
done

# if debug mode, make certain the disk is not mistaken for a fully cleared disk.
if [ $short_test -ne 0 -a "$verify_only" != "y" ]
then
  echo -ne "\001" | dd bs=1 count=1 of=$theDisk
fi
if [ -f "/tmp/postread_errors$disk_basename" ]
then
   postread_error=`head "/tmp/postread_errors$disk_basename" 2>/dev/null`
else
   postread_error=""
fi

if [ "$verify_only" = "y" ]
then
   if [ "$postread_error" != "" ]
   then
     echo "========================================================================$ver"
     echo "=="
     echo "== ${bold}Disk $theDisk has NOT been verified successfully${norm}"
     echo "==" $postread_error
     echo "============================================================================"
     report_out="========================================================================$ver\n"
     report_out+="== invoked as: $progname $options\n"
     report_out+="==\n"
     report_out+="== Disk $theDisk has NOT been successfully verified as precleared\n"
     report_out+="== Postread detected un-expected non-zero bytes on disk"
     report_out+="==\n"
     status_out="YY|Disk Not Verified Precleared"    #bjp999 4/9/11
   else
     echo "========================================================================$ver"
     echo "=="
     echo "== ${bold}Disk $theDisk has been verified precleared${norm}"
     echo "== with a starting sector of $partition_start"
     echo "============================================================================"
     report_out="========================================================================$ver\n"
     report_out+="== invoked as: $progname $options\n"
     report_out+="==\n"
     report_out+="== Disk $theDisk has been verified precleared\n"
     report_out+="== with a starting sector of $partition_start \n"
     status_out="YN|Disk Verified Precleared"        #bjp999 4/9/11
   fi
else
   if [ "$postread_error" != "" ]
   then
     echo "========================================================================$ver"
     echo "== $model   $serial"
     echo "== ${bold}Disk $theDisk has NOT been precleared successfully${norm}"
     echo "==" $postread_error
     echo "============================================================================"
     report_out="========================================================================$ver\n"
     report_out+="== invoked as: $progname $options\n"
     report_out+="==\n"
     report_out+="== Disk $theDisk has NOT been successfully precleared\n"
     report_out+="== Postread detected un-expected non-zero bytes on disk"
     report_out+="==\n"
     status_out="YY|Preclear Not Successful"  #bjp999 4/9/11
   else
     echo "========================================================================$ver"
     echo "== $model   $serial"
     echo "== ${bold}Disk $theDisk has been successfully precleared${norm}"
     echo "== with a starting sector of $partition_start"
     echo "============================================================================"
     report_out="========================================================================$ver\n"
     report_out+="== invoked as: $progname $options\n"
     report_out+="== $model   $serial\n"

     report_out+="== Disk $theDisk has been successfully precleared\n"
     report_out+="== with a starting sector of $partition_start \n"
     status_out="YN|Preclear Successful"      #bjp999 4/9/11
   fi
fi

#bjp999 4/9/11 V
stat_pre=""
stat_post=""
stat_zero=""
#echo "pre_read_flag = '$pre_read_flag'"
#echo "verify_only = '$verify_only'"
if [ "$pre_read_flag" = "y" ]
then
    stat_pre="^n... Pre-Read time $dpretmr ($cal_pre_read_speed MB/s)"
    stat_post="^n... Post-Read time $dposttmr ($cal_post_read_speed MB/s)"
fi
if [ "$verify_only" != "y" ]
then
    stat_zero="^n... Zeroing time $dzerotmr ($cal_zero_write_speed MB/s)"
fi
#echo "status_out= $status_out"
echo "$disk_basename|${status_out}^n... Total time $(timer $tmr)${stat_pre}${stat_zero}${stat_post}|$$" > /tmp/preclear_stat_$disk_basename
#bjp999 4/9/11 ^

if [ "$cycle_count" -eq "1" ]
then
    report_out+="== Ran 1 cycle\n"
else
    report_out+="== Ran $cycle_count cycles\n"
fi
report_out+="==\n"
if [ "$pre_read_flag" = "y" ]  # Don't report Pre read time if there was no Pre/Post read.
then
    report_out+="== Using :Read block size = ${units} Bytes\n"
    report_out+="== Last Cycle's Pre Read Time  : $dpretmr ($cal_pre_read_speed MB/s)\n"
fi

if [ "$verify_only" != "y" ] # Don't report zero time if there was no zeroing.
then
    report_out+="== Last Cycle's Zeroing time   : $dzerotmr ($cal_zero_write_speed MB/s)\n"
fi
if [ "$pre_read_flag" = "y" ] # Don't report Post read time if there was no Pre/Post read.
then
    report_out+="== Last Cycle's Post Read Time : $dposttmr ($cal_post_read_speed MB/s)\n"
fi
report_out+="== Last Cycle's Total Time     : $dcyctmr\n"
report_out+="==\n"
report_out+="== Total Elapsed Time $(timer $tmr)\n"
report_out+="==\n"
if [ "$disk_start_temp" != "" ] # Don't report Disk Temperature if there was no Temp reported.
then
    report_out+="== Disk Start Temperature: ${disk_start_temp}C\n"
    report_out+="==\n"
    report_out+="== Current `disk_temperature 1`\n"
fi
report_out+="==\n"
report_out+="============================================================================\n"


get_finish_smart $theDisk >/dev/null 2>&1

errs=`analyze_for_errors /tmp/smart_start_$d /tmp/smart_finish_$d`

echo "$errs "


report_out+="$errs \n"
report_out+="============================================================================\n"
echo -e "$report_out" >/tmp/preclear_report_$d
if [ "$save_report" = "y" ]
then
    mkdir -p /boot/preclear_reports
    dt=`date "+%Y-%m-%d"`
    if [ "$save_report_by_dev" = "yes" ]
    then
      todos < /tmp/preclear_report_$d > /boot/preclear_reports/preclear_rpt_${d}_${dt}.txt
      todos < /tmp/smart_start_$d > /boot/preclear_reports/preclear_start_${d}_${dt}.txt
      todos < /tmp/smart_finish_$d > /boot/preclear_reports/preclear_finish_${d}_${dt}.txt
    else # name the reports by their serial number
      todos < /tmp/preclear_report_$d > "/boot/preclear_reports/preclear_rpt_${serial}_${dt}".txt
      todos < /tmp/smart_start_$d > "/boot/preclear_reports/preclear_start_${serial}_${dt}".txt
      todos < /tmp/smart_finish_$d > "/boot/preclear_reports/preclear_finish_${serial}_${dt}".txt
    fi
fi
report_out+="============================================================================\n"
report_out+="==\n"
report_out+="== S.M.A.R.T Initial Report for $theDisk \n"
report_out+="==\n"
report_out+="`cat /tmp/smart_start_$d` \n"
report_out+="==\n"
report_out+="============================================================================\n"
report_out+="\n"
report_out+="\n"
report_out+="\n"
report_out+="============================================================================\n"
report_out+="==\n"
report_out+="== S.M.A.R.T Final Report for $theDisk \n"
report_out+="==\n"
report_out+="`cat /tmp/smart_finish_$d` \n"
report_out+="==\n"
report_out+="============================================================================\n"
echo -e "$report_out " | logger -tpreclear_disk-diff -plocal7.info -i

if [ $use_mail -ge 1 ]
then
  send_mail "Preclear: PASS! Preclearing Disk $disk_basename Finished!!!" "Preclear: PASS! Preclearing Disk $disk_basename Finished!!! Cycle $cc of $cycle_count" "${report_out}" $mail_rcpt
fi
