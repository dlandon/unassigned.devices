<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2024, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
require_once("plugins/".$plugin."/include/lib.php");
require_once($docroot."/webGui/include/Helpers.php");

/* add translations */
$_SERVER['REQUEST_URI'] = "unassigneddevices";
require_once($docroot."/webGui/include/Translations.php");

if (isset($_POST['display'])) {
	$display = $_POST['display'];
}

/* Get the diskio scale based on the current setting. */
function my_diskio($data) {
	return my_scale($data, $unit, 1)." $unit/s";
}

/* Get the used and free space for a partition and render for html. */
function render_used_and_free($partition) {
	global $display;

	/* Only show used and free when disk is mounted. */
	if ($partition['mounted']) {
		$free_pct = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
		$used_pct = 100-$free_pct;

		/* Display of disk usage depends on global display setting. */
		if ($display['text'] % 10 == 0) {
			$out = "<td>".my_scale($partition['used'], $unit)." $unit</td>";
		} else {
			$out = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($partition['used'], $unit)." $unit</span></div></td>";
		}

		/* Display of disk usage depends on global display setting. */
		if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$out .= "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
		} else {
			$out .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($partition['avail'], $unit)." $unit</span></div></td>";
		}
	} else {
		$out = "<td></td><td></td>";
	}

	return $out;
}

/* Get the used and free space for a disk and render for html. */
function render_used_and_free_disk($disk, $mounted) {
	global $display;

	if ($mounted) {
		$size	= 0;
		$avail	= 0;
		$used	= 0;
		foreach ($disk['partitions'] as $partition) {
			$size	+= $partition['size'];
			$avail	+= $partition['avail'];
			$used 	+= $partition['used'];
		}
		$free_pct = $size ? round(100*$avail/$size) : 0;
		$used_pct = 100-$free_pct;
		if ($display['text'] % 10 == 0) {
			$out = "<td>".my_scale($used, $unit)." $unit</td>";
		} else {
			$out = "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($used, $unit)." $unit</span></div></td>";
		}
		if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$out .= "<td>".my_scale($avail, $unit)." $unit</td>";
		} else {
			$out .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($avail, $unit)." $unit</span></div></td>";
		}
	} else {
		$out = "<td></td><td></td>";
	}

	return $out;
}

/* Get the partition information and render for html. */
function render_partition($disk, $partition, $disk_line = false) {
	global $paths, $plugin, $shares_enabled;

	$out = [];
	if (! empty($disk['partitions'])) {
		$mounted		= $partition['mounted'];
		$not_unmounted	= $partition['not_unmounted'];
		$not_udev		= $partition['not_udev'];
		$cmd			= $partition['command'];
		$device			= $partition['fstype'] == "crypto_LUKS" ? $partition['luks'] : $partition['device'];
		$is_mounting	= $partition['mounting'];
		$is_unmounting	= $partition['unmounting'];
		$is_formatting	= $partition['formatting'] ?? false;
		$disabled		= ($is_mounting || $is_unmounting || $partition['running'] || ! $partition['fstype'] || $disk['array_disk']);

		/* Get the lsblk file system to compare to udev. */
		$crypto_fs_type	= $partition['file_system'];

		/* Set up icons for file system check/scrub and script execution. */
		$fstype = ($partition['fstype'] == "crypto_LUKS") ? $crypto_fs_type : $partition['fstype'];
		if (((! $disabled) && (! $mounted) && ($fstype != "apfs") && ($fstype != "btrfs") && ($fstype != "zfs")) || ((! $disabled) && ($mounted) && ($fstype == "btrfs" || $fstype == "zfs"))) {
			$file_system_check = (($fstype != "btrfs") && ($fstype != "zfs")) ? _('File System Check') : _('File System Scrub');
			$fscheck = "<a class='exec info' onclick='openWindow_fsck(\"/plugins/".$plugin."/include/fsck.php?device={$partition['device']}&fs={$partition['fstype']}&luks={$partition['luks']}&serial={$partition['serial']}&mountpoint={$partition['mountpoint']}&check_type=ro&type="._('Done')."\",\"Check filesystem\",600,900);'><i class='fa fa-check partition-hdd'></i><span>".$file_system_check."</span></a>";
		} else {
			$fscheck = "<i class='fa fa-check partition-hdd'></i></a>";
		}
		$fscheck .= $partition['part'];

		if ($mounted && is_file($cmd)) {
			if (! $disabled) {
				$fscheck .= "<a class='exec info' onclick='openWindow_fsck(\"/plugins/".$plugin."/include/script.php?device={$device}&type="._('Done')."\",\"Execute Script\",600,900);'><i class='fa fa-flash partition-script'></i><span>"._("Execute Script as udev simulating a device being installed")."</span></a>";
			} else {
				$fscheck .= "<i class='fa fa-flash partition-script'></i>";
			}
		} else if ($mounted) {
			$fscheck 	.= "<i class='fa fa-flash partition-script'></i>";
		}

		/* Add remove partition icon if destructive mode is enabled. */
		$parted				= file_exists("/usr/sbin/parted");
		$rm_partition		= ((get_config("Config", "destructive_mode") == "enabled") && ($parted) && (! $is_mounting) && (! $is_unmounting) && (! $is_formatting) && (! $disk['pass_through']) && (! $disk['partitions'][0]['disable_mount']) && (! $disk['array_disk']) && ($fstype) && ($fstype != "zfs")) ? "<a device='{$partition['device']}' class='exec info' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$partition['serial']}\",\"{$disk['device']}\",\"{$partition['part']}\");'><i class='fa fa-remove clear-hdd'></i><span>"._("Remove Partition")."</span></a>" : "";
		$mpoint				= "<span>".$fscheck;

		/* Add script log icon. */
		if ($cmd) {
			$mpoint			.= "<a class='info' href='/Main/ScriptLog?s=".$partition['serial']."&p=".$partition['part']."'><i class='fa fa-align-left partition-log'></i><span>"._("View Device Script Log")."</span></a>";
		} else {
			$mpoint			.= "<i class='fa fa-align-left partition-log' disabled></i>";
		}

		$mount_point		= basename($partition['mountpoint']);

		/* Add change mount point or browse disk share icon if disk is mounted. */
		if (($not_unmounted) || ($not_udev)) {
			$mpoint .= $mount_point."</span>";
		} else if (($mounted) && (! $is_mounting) && (! $is_unmounting)) {
			/* If the partition is mounted read only, indicate that on the mount point. */
			$read_only		= $partition['part_read_only'] ? "<font color='red'> (RO)<font>" : "";

			$mpoint			.= "<i class='fa fa-external-link partition-hdd'></i>";
			$mpoint			.= "<a title='"._("Browse Disk Share")."' href='/Main/Browse?dir={$partition['mountpoint']}'>".$mount_point."</a>".$read_only."</span>";
		} else {
			$mount_point	= basename($partition['mountpoint']);
			$disk_label		= $partition['disk_label'];
			if ((! $disk['array_disk']) && (! $mounted) && (! $is_mounting) && (! $is_unmounting)) {
				$mpoint		.= "<i class='fa fa-pencil partition-hdd'></i><a title='"._("Change Disk Mount Point")."' class='exec' onclick='chg_mountpoint(\"{$partition['serial']}\",\"{$partition['part']}\",\"{$device}\",\"{$partition['fstype']}\",\"{$mount_point}\",\"{$disk_label}\");'>{$mount_point}</a>";
			} else {
				$mpoint		.= "<i class='fa fa-pencil partition-hdd'></i>".$mount_point;
			}
			$mpoint			.= $rm_partition."</span>";
		}

		/* Make the mount button. */
		$mbutton = make_mount_button($partition);

		/* Show disk partitions if partitions enabled. */
		$style				= ((! $disk['show_partitions']) || ($disk['pass_through'])) ? "style='display:none;'" : "";
		$out[]				= "<tr class='toggle-parts toggle-".basename($disk['device'])."' name='toggle-".basename($disk['device'])."' $style>";
		$out[]				= "<td></td>";
		$out[]				= "<td>".$mpoint."</td>";
		$out[]				= ((count($disk['partitions']) > 1) && ($mounted)) ? "<td class='mount'>".$mbutton."</td>" : "<td></td>";

		/* Determine the file type of the disk by getting the first partition with a fstype. */
		$fstype	= $partition['fstype'];
		if ($disk_line) {
			foreach ($disk['partitions'] as $part) {
				if ($part['fstype']) {
					$fstype = $part['fstype'];
					break;
				}
			}
		}

		/* Disk read and write totals or rate. */
		if ($disk_line) {
			if (! isset($_COOKIE['diskio'])) {
				$out[]		= "<td>".my_number($disk['reads'])."</td>";
				$out[]		= "<td>".my_number($disk['writes'])."</td>";
			} else {
				$out[]		= "<td>".my_diskio($disk['read_rate'])."</td>";
				$out[]		= "<td>".my_diskio($disk['write_rate'])."</td>";
			}
		} else {
			$out[]			= "<td></td>";
			$out[]			= "<td></td>";
		}

		/* Set up the device settings and script settings tooltip. */
		$title				= _("Device Settings and Script");
		if ($disk_line) {
			$title			.= "<br />"._("Passed Through").": ";
			$title			.= $partition['pass_through'] ? "Yes" : "No";
			$title			.= "<br />"._("Disable Mount Button").": ";
			$title			.= $partition['disable_mount'] ? "Yes" : "No";
			$title			.= "<br />"._("Read Only").": ";
			$title			.= $partition['read_only'] ? "Yes" : "No";
			$title			.= "<br />"._("Automount").": ";
			$title			.= $disk['automount'] ? "Yes" : "No";
		}
		$title .= "<br />"._("Share").": ";

		$title .= $shares_enabled ? (($partition['shared']) ? "Yes" : "No") : "Not Enabled";
		if ($disk_line) {
			$title .= "<br />"._("Show Partitions").": ";
			$title .= $disk['show_partitions'] ? "Yes" : "No";
		} else {
			$title .= "<br />"._("Script Enabled").": ";
			$title .= $partition['enable_script'] != "false" ? "Yes" : "No";
		}

		/* Get the mounted, mounting, and unmounting status of all partitions to determine the status of the disk. */
		$mounted_disk		= $mounted;
		$mounting_disk		= $is_mounting;
		$unmounting_disk	= $is_unmounting;
		if ($disk_line) {
			foreach ($disk['partitions'] as $part) {
				if ($part['mounted']) {
					$mounted_disk = true;
				}
				if ($part['mounting']) {
					$mounting_disk = true;
				}
				if ($part['unmounting']) {
					$unmounting_disk = true;
				}
			}
		} else {
			$out[] = "<td></td>";
		}

		$device				= MiscUD::base_device(basename($device)) ;
		$serial				= $partition['serial'];
		$id_bus				= $disk['id_bus'];
		if (! $disk['array_disk']) {
			$out[]			= "<td><a class='info' href='/Main/DeviceSettings?s=".$serial."&b=".$device."&f=".$fstype."&l=".$partition['mountpoint']."&n=".($mounted_disk || $mounting_disk || $unmounting_disk)."&p=".$partition['part']."&m=".json_encode($partition)."&t=".$disk_line."&u=".$id_bus."'><i class='fa fa-gears'></i><span class='help-title'>$title</span></a></td>";
		} else {
			$out[]			= "<td><i class='fa fa-gears' disabled></i></td>";
		}

		/* Show disk and partition usage. */
		$out[] = "<td>".($fstype == "crypto_LUKS" ? $crypto_fs_type : $fstype)."</td>";
		if ($disk_line) {
			$out[]			= (! $not_unmounted) ? render_used_and_free_disk($disk, $mounted_disk) : "<td></td>";
		} else {
			$out[]			= "<td>".my_scale($partition['size'], $unit)." $unit</td>";
			$out[]			= (! $not_unmounted) ? render_used_and_free($partition) : "<td></td>";
		}

		/* Show any zvol devices. */
		if (count($disk['zvol'])) {
			foreach ($disk['zvol'] as $k => $z) {
				if ((get_config("Config", "zvols") == "yes") || ($z['mounted'])) { 
					$mbutton		= $z['active'] ? make_mount_button($z) : "";
					$fstype			= $z['file_system'];
					$out[]			= "<tr class='toggle-parts toggle-".basename($disk['device'])."' name='toggle-".basename($disk['device'])."' $style>";
					$out[]			= "<td></td><td>"._("ZFS Volume").":";

					/* Put together the file system check icon. */
					if (((! $z['mounted']) && ($fstype) && ($fstype != "btrfs")) || (($z['mounted']) && ($fstype == "btrfs" || $fstype == "zfs"))) {
						$file_system_check = (($fstype != "btrfs") && ($fstype != "zfs")) ? _('File System Check') : _('File System Scrub');
						$fscheck	= "<a class='exec info' onclick='openWindow_fsck(\"/plugins/".$plugin."/include/fsck.php?device={$z['device']}&fs={$fstype}&luks={$z['device']}&serial={$z['volume']}&mountpoint={$z['mountpoint']}&check_type=ro&type="._('Done')."\",\"Check filesystem\",600,900);'><i class='fa fa-check zfs-volume-hdd'></i><span>".$file_system_check."</span></a>";
					} else {
						$fscheck	= "<i class='fa fa-check zfs-volume-hdd'></i></a>";
					}

					if ($z['mounted']) {
						/* If the volume is mounted read only, indicate that on the mount point. */
						$read_only	= $z['zfs_read_only'] ? "<font color='red'> (RO)<font>" : "";
	
						$out[]		= "<span>".$fscheck."<i class='fa fa-external-link partition-hdd'></i><a title='"._("Browse ZFS Volume")."' href='/Main/Browse?dir=".$z['mountpoint']."'>".basename($z['mountpoint'])."</a>".$read_only."</span>";
					} elseif ($z['active']) {
						$out[]		= "<span>".$fscheck.basename($z['mountpoint'])."</span>";
					} else {
						$out[]		= "<span>".basename($z['mountpoint'])."</span>";
					}
					$out[]			= "<td class='mount'>".$mbutton."</td>";
					$out[]			= "<td></td>";
					$out[]			= "<td></td>";
					$out[]			= "<td></td>";

					/* Set up the device settings and script settings tooltip. */
					$title			= _("ZFS Volume Settings");
					$title			.= "<br />"._("Passed Through").": ";
					$title			.= $z['pass_through'] ? "Yes" : "No";
					$title			.= "<br />"._("Disable Mount Button").": ";
					$title			.= $z['disable_mount'] ? "Yes" : "No";
					$title			.= "<br />"._("Read Only").": ";
					$title			.= $z['read_only'] ? "Yes" : "No";

					$device			= basename($z['device']) ;
					$serial			= $disk['serial'];
					$volume			= $k;
					$id_bus			= "";

					if (($z['active']) && ($fstype)) {
						$out[]		= "<td><a class='info' href='/Main/DeviceSettings?s=".$serial."&b=".$volume."&f=".$z['fstype']."&l=".$z['mountpoint']."&n=".$z['mounted']."&p=".$volume."&m=".json_encode($z)."&t=false&u=".$id_bus."'><i class='fa fa-gears'></i><span class='help-title'>$title</span></a></td>";
						$out[]		= "<td>".$fstype."</td>";
						$out[]		= "<td>".my_scale($z['size'], $unit)." $unit</td>";
					} else {
						$out[]		= "<td></td>";
						$out[]		= "<td></td>";
						$out[]		= "<td></td>";
					}
					$out[]			= render_used_and_free($z);
				}
			}
		}

		$out[] = "</tr>";
	}

	return $out;
}

/* Format the mount button based on status of the device. */
function make_mount_button($device) {
	global $paths;

	/* If the device array has partitions, this is the disk device array. */
	if (isset($device['partitions'])) {
		/* Disk device mount button. */
		$context		= "disk";

		/* A partition on the disk is mounted. */
		$mounted		= $device['mounted'];

		/* The disk was not unmounted before being removed and the reinstalled. */
		$not_unmounted	= $device['not_unmounted'];

		/* A disk partition is mounting. */
		$is_mounting	= $device['mounting'];

		/* A disk partition is unmounting. */
		$is_unmounting	= $device['unmounting'];

		/* Disk is formatting. */
		$is_formatting	= $device['formatting'];

		/* Disk is preclearing. */
		$preclearing	= $device['preclearing'];

		/* Disk script or user script is running. */
		$is_running		= $device['running'];

		/* Disk is a pool disk. */
		$pool_disk		= $device['pool_disk'];

		/* Udev and lsblk do not agree on the file system type. */
		$not_udev		= $device['not_udev'];

		/* Disable mount button enabled. */
		$disable_mount	= $device['disable_mount'];

		/* Is this device enabled with valid partitions. */
		$disable		= $device['disable'];

		/* Disk can be formatted if there are no partitions. */
		$pass_through	= $device['pass_through'];
		$format			= ((! count($device['partitions'])) && (! $pass_through));

		/* This is not a zvol device. */
		$zvol_device	= false;

		/* A pool disk can be part of a disk pool or a disk with a file system and no partition. */
		$no_partition	= ($device['fstype'] && $format);
	} else {
		/* Partition mount button. */
		$context		= "partition";

		/* The partition is mounted. */
		$mounted		= $device['mounted'];

		/* Mount button is disabled if there is no file system present. */
		$disable		= (! $device['fstype']);

		/* Find conditions to disable the 'Mount' button. */
		$disable_mount	= $device['disable_mount'];

		/* Is the disk not unmounted properly? */
		$not_unmounted = $device['not_unmounted'];

		/* Is the disk file system not matching udev file system? */
		$not_udev		= $device['not_udev'];

		/* Check the state of mounting, unmounting, and running. */
		$is_mounting	= $device['mounting'];
		$is_unmounting	= $device['unmounting'];
		$is_running		= $device['running'];

		/* Is this a zvol device? */
		$zvol_device	= $device['file_system'];

		/* The partition is set as passed through. */
		$pass_through	= $device['pass_through'];

		/* Things not related to a partition. */
		$is_formatting	= false;
		$preclearing	= false;
		$format			= false;
		$pool_disk		= false;
		$no_partition	= false;
	}

	/* Set up the mount button operation and text. */
	$buttonFormat = "<button device='{$device['device']}' class='mount' context='%s' role='%s' %s><i class='%s'></i>%s</button>";

	/* Initialize variables. */
	$role			= "";
	$text			= "";
	$class			= "";
	$spinner_class	= "fa fa-spinner fa-spin orb";
	$ban_class		= "fa fa-ban orb";

	switch (true) {
		case ($pass_through):
			$class		= $ban_class;
			$disable	= true;
			$text		= _('Passed');
			break;

		case ($no_partition):
			$class		= $ban_class;
			$disable	= true;
			$text		= _('Partition');
			break;

		case ($pool_disk):
			$disable	= true;
			$text		= _('Pool');
			break;

		case (($device['size'] == 0) && (! $zvol_device)):
			$disable	= true;
			$text		= _('Mount');
			break;

		case ($device['array_disk']):
		case (! $device['ud_device']):
			$class		= $ban_class;
			$disable	= true;
			$text		= _('Array');
			break;

		case ($not_udev):
			$class		= $ban_class;
			$disable	= true;
			$text		= _('Udev');
			break;

		case ($not_unmounted):
			$class		= $ban_class;
			$disable	= true;
			$text		= _('Reboot');
			break;

		case ($preclearing):
			$disable	= true;
			$text		= _('Preclear');
			break;

		case ($is_formatting):
			$class		= $spinner_class;
			$disable	= true;
			$text		= _('Formatting');
			break;

		case ($format):
			$role		= 'format';
			$disable 	= false;
			$text		= _('Format');
			break;

		case ($is_mounting):
			$class		= $spinner_class;
			$role		= 'mount';
			$disable	= true;
			$text		= _('Mounting');
			break;

		case ($is_unmounting):
			$class		= $spinner_class;
			$role		= 'umount';
			$disable	= true;
			$text		= _('Unmounting');
			break;

		case ($mounted):
			if ($is_running) {
				$class		= $spinner_class;
				$role		= 'mount';
				$disable	= true;
				$text		= _('Running');
			} else {
				$class		= $disable_mount ? $ban_class : "";
				$role		= 'umount';
				$disable 	= $disable_mount ? true : $disable;
				$text		= _('Unmount');
			}
			break;

		default:
			$class		= $disable_mount ? $ban_class : "";
			$role		= 'mount';
			$disable 	= $disable_mount ? true : $disable;
			$text 		= _('Mount');
			break;
	}

	/* Build the mount button. */
	$button = sprintf($buttonFormat, $context, $role, ($disable ? "disabled" : ""), $class, $text);

	return $button;
}

switch ($_POST['action']) {
	case 'get_content':
		/* Update the UD webpage content. */

		/* Start time for disk ops. Used for debugging. */
		$time		= -microtime(true); 

		unassigned_log("Debug: Begin - Refreshing content...", $UPDATE_DEBUG);

		/* Check for a recent hot plug event. */
		if (file_exists($paths['hotplug_event'])) {
			exec("/usr/bin/rm -f ".escapeshellarg($paths['hotplug_event']));

			unassigned_log("Debug: Processing Hotplug event...", $UDEV_DEBUG);

			/* Tell Unraid to update list of unassigned devices in devs.ini. */
			exec("/usr/local/sbin/emcmd cmdHotplug='apply'");

			/* Get all updated unassigned disks and update devX designations for newly found unassigned devices. */
			$all_disks = get_all_disks_info();
			foreach ($all_disks as $disk) {
				/* This is the device label and is either a 'devX' designation or a disk alias. */
				$unassigned_dev	= $disk['unassigned_dev'];

				/* If the unassigned_dev value is not set or is 'dev' or 'sd', and not equal to ud_dev value the then assign it the 'devX' value. */
				if (((! $unassigned_dev) || (is_sd_device($unassigned_dev))) || ((is_dev_device($unassigned_dev)) && ($unassigned_dev != $disk['ud_dev']))) {
					set_config($disk['serial'], "unassigned_dev", $disk['ud_dev']);
				}
			}

			/* Update the preclear diskinfo for the hot plugged device. */
			if (file_exists("/etc/rc.d/rc.diskinfo")) {
				exec("/etc/rc.d/rc.diskinfo force & 2>/dev/null");
			}
		} else {
			/* Get all unassigned disks if we don't have a hot plug event. */
			$all_disks = get_all_disks_info();
		}

		/* Create empty array of share names for duplicate share checking. */
		$share_names	= [];
		$disk_uuid		= [];

		/* Create array of disk names. */
		$disk_names		= [];

		/* Disk devices. */
		$o_disks		= "";

		unassigned_log("Debug: Update disk devices...", $UPDATE_DEBUG);

		/* Is parted installed? */
		$parted					= file_exists("/usr/sbin/parted");

		/* Get updated disks info in case devices have been hot plugged. */
		if ( count($all_disks) ) {
			foreach ($all_disks as $disk) {
				$disk_device		= basename($disk['device']);
				$disk_dev			= $disk['ud_dev'];
				$disk_name			= $disk['unassigned_dev'] ?: $disk['ud_dev'];
				$parts				= (! empty($disk['partitions'])) ? render_partition($disk, $disk['partitions'][0], true) : false;
				$preclearing		= $disk['preclearing'];
				$temp				= my_temp($disk['temperature']);

				/* See if any partitions are mounted. */
				$mounted			= $disk['mounted'];

				/* Was the disk unmounted properly? */
				$not_unmounted		= $disk['not_unmounted'];

				/* See if any partitions have a file system. */
				$file_system		= $disk['file_system'];

				/* Create the mount button. */
				$mbutton				= make_mount_button($disk);

				/* Set up the preclear link for preclearing a disk. */
				$preclear_link			= (($Preclear) && ($disk['size'] !== 0) && (! $file_system) && (! $mounted) && (! $disk['formatting']) && (! $preclearing) && (! $disk['array_disk']) && (! $disk['pass_through']) && (! $disk['fstype'])) ? "&nbsp;&nbsp;".$Preclear->Link($disk_device, "icon") : "";

				/* Add the clear disk icon. */
				$clear_disk				= (($parted) && (get_config("Config", "destructive_mode") == "enabled") && (! $mounted) && (! $disk['mounting']) && (! $disk['unmounting']) && (! $disk['formatting']) && (! $disk['pass_through']) && (! $disk['array_disk']) && (! $preclearing) && (((! $parts) && ($disk['fstype'])) || (($parts) && (! $disk['partitions'][0]['pool']) && (! $disk['partitions'][0]['disable_mount']))) ) ? "<a device='{$disk['device']}' class='exec info' style='color:#CC0000;font-weight:bold;' onclick='clr_disk(this,\"{$disk['serial']}\",\"{$disk['device']}\");'><i class='fa fa-remove clear-hdd'></i><span>"._("Clear Disk")."</span></a>" : "";

				/* Show disk icon based on SSD or spinner disk. */
				$disk_icon = $disk['ssd'] ? "icon-nvme" : "fa fa-hdd-o";

				/* Disk log. */
				$hdd_serial = "<a class='info' href=\"#\" onclick=\"openTerminal('disklog', '{$disk_device}')\"><i class='".$disk_icon." icon partition-log'></i><span>"._("Disk Log Information")."</span></a>";
				if ($parts) {
					$add_toggle = true;
					if ($disk['pass_through']) {
						$hdd_serial		.="<span><i class='fa fa-plus-square fa-append grey-orb orb'></i></span>";
					} else if (! $disk['show_partitions']) {
						$hdd_serial		.="<span title ='"._("Click to view/hide partitions and mount points")."'class='exec toggle-hdd' hdd='".$disk_device."'><i class='fa fa-plus-square fa-append'></i></span>";
					} else {
						$hdd_serial		.="<span><i class='fa fa-minus-square fa-append grey-orb orb'></i></span>";
					}
				} else {
					$add_toggle	= false;
					$hdd_serial			.= "<span class='toggle-hdd' hdd='".$disk_device."'></span>";
				}

				$device		= $disk['ud_device'] ? " (".$disk_device.")" : "";
				$hdd_serial .= $disk['serial'].$device.$preclear_link.$clear_disk."<span id='preclear_".$disk['serial_short']."' style='display:block;'></span>";

				$o_disks .= "<tr class='toggle-disk'>";
				if (! $disk['ud_unassigned_dev']) {
					if (! $disk['array_disk']) {
						$disk_display = $disk_name;
					} else {
						$disk_display = $disk['ud_dev'];
					}
				} else {
					$disk_display = substr($disk_name, 0, 3)." ".substr($disk_name, 3);
					$disk_display = my_disk($disk_display);
				}

				/* Device table element. */
				$o_disks .= "<td>";
				if (! $disk['ud_device']) {
					$str		= "New?name";
					$o_disks	.= "<i class='fa fa-circle ".($disk['running'] ? "green-orb" : "grey-orb" )." orb'></i>";
				} else {
					$str		= "Device?name";
					$orb		= (($disk['spinning']) ? "green-orb" : "grey-orb")." orb";
					if (! $preclearing) {
						if (! is_disk_spin($disk['ud_dev'], $disk['spinning'])) {
							if ($disk['spinning']) {
								$spin			= "spin_down_disk";
								$tool_tip		= _("Click to spin down device");
							} else {
								$spin			= "spin_up_disk";
								$tool_tip		= _("Click to spin up device");
							}
							$o_disks		.= "<a style='cursor:pointer' class='exec info' onclick='{$spin}(\"{$disk_dev}\")'><i id='disk_orb-{$disk_dev}' class='fa fa-circle {$orb}'></i><span>{$tool_tip}</span></a>";
						} else {
							$o_disks		.= "<i class='fa fa-refresh fa-spin {$orb}'></i>";
						}
					} else {
						$o_disks .= "<i class='fa fa-circle {$orb}'></i>";
					}
				}
				$luks_lock		= $mounted ? "<i class='fa fa-unlock-alt fa-append green-orb'></i>" : "<i class='fa fa-lock fa-append grey-orb'></i>";
				$o_disks		.= ((count($disk['partitions']) > 0) && ($disk['partitions'][0]['fstype'] == "crypto_LUKS")) ? $luks_lock : "";
				$o_disks		.= "<a href='/Main/".$str."=".$disk_dev."'>".$disk_display."</a>";
				$o_disks		.= "</td>";

				/* Device serial number. */
				$o_disks		.= "<td>{$hdd_serial}</td>";

				/* Mount button. */
				$o_disks		.= "<td class='mount'>{$mbutton}</td>";

				/* Disk temperature. */
				$o_disks		.= "<td>{$temp}</td>";

				if (! $parts) {
					$rw = get_disk_reads_writes($disk['ud_dev'], $disk['device']);
					if (! isset($_COOKIE['diskio'])) {
						$reads	= my_number($rw[0]);
						$writes	= my_number($rw[1]);
					} else {
						$reads	= my_diskio($rw[2]);
						$writes	= my_diskio($rw[3]);
					}
				}

				/* Reads. */
				$o_disks		.= ($parts) ? $parts[4] : "<td>".$reads."</td>";

				/* Writes. */
				$o_disks		.= ($parts) ? $parts[5] : "<td>".$writes."</td>";

				/* Settings. */
				$o_disks		.= ($parts) ? $parts[6] : "<td></td>";

				/* File system. */
				$o_disks		.= ($parts) ? $parts[7] : "<td>".$disk['fstype']."</td>";

				/* Disk size. */
				$o_disks		.= "<td>".my_scale($disk['size'], $unit)." {$unit}</td>";

				/* Disk used and free space. */
				$o_disks		.= (($parts) && (! $not_unmounted)) ? $parts[8] : "<td></td><td></td>";

				$o_disks		.= "</tr>";

				if ($add_toggle)
				{
					$o_disks	.= "<tr>";
					foreach ($disk['partitions'] as $partition) {
						foreach (render_partition($disk, $partition) as $l)
						{
							$o_disks .= $l;
						}
					}
					$o_disks	.= "</tr>";
				}

				/* Add to share names and disk names. */
				if (! $disk['formatting']) {
					for ($i = 0; $i < count($disk['partitions']); $i++) {
						if (($disk['unassigned_dev']) && (! in_array($disk['unassigned_dev'], $disk_names))) {
							$disk_names[$disk_device] = $disk['unassigned_dev'];
						}
						if ($disk['partitions'][$i]['fstype']) {
							$dev		= ($disk['partitions'][$i]['fstype'] == "crypto_LUKS") ? $disk['partitions'][$i]['luks'] : $disk['partitions'][$i]['device'];

							/* Check if this disk uuid has already been entered in the share_names array. */
							$mountpoint					= basename($disk['partitions'][$i]['mountpoint']);
							$uuid		 				= $disk['partitions'][$i]['uuid'];
							if (($uuid) && (isset($disk_uuid[$uuid]))) {
								$disk_uuid[$uuid]		= $disk_uuid[$uuid].",".$dev;
							} else if ($uuid) {
								$disk_uuid[$uuid]		= $dev;
							}

							$share_names				= array_flip($share_names);
							$share_names[$mountpoint]	= $disk_uuid[$uuid] ?: $dev;
							$share_names				= array_flip($share_names);
						}
					}
				}
			}
		} else {
			$o_disks .= "<tr><td colspan='11' style='text-align:center;'>"._('No Unassigned Disks available').".</td></tr>";
		}

		$time		+= microtime(true);
		unassigned_log("Update Disks took ".sprintf('%f', $time)."s!", $UPDATE_DEBUG);

		unassigned_log("Debug: Update Remote Mounts...", $UPDATE_DEBUG);

		/* SAMBA Mounts. */
		$o_remotes = "";

		/* Start time for remote shares ops. */
		$time = -microtime(true);

		/* Get all the samba mounts. */
		$samba_mounts = get_samba_mounts();
		if (count($samba_mounts)) {
			foreach ($samba_mounts as $mount)
			{
				$is_alive		= $mount['alive'];
				$is_available	= $mount['available'];
				$mounted		= $mount['mounted'];

				/* Is the device mounting or unmounting. */
				$is_mounting	= $mount['mounting'];
				$is_unmounting	= $mount['unmounting'];

				/* Populate the table row for this device. */
				$o_remotes		.= "<tr>";

				/* What type of mount is this? */
				$protocol		= $mount['protocol'];

				/* Orb and Protocol table element. */
				$o_remotes		.= sprintf( "<td><a class='info'><i class='fa fa-circle %s orb'></i><span>"._("Remote Server is")." %s</span></a>%s</td>", ( $is_alive ? "green-orb" : "grey-orb" ), ( $is_alive ? _("online") : _("offline") ), $protocol);

				/* Source table element. */
				$o_remotes		.= "<td>{$mount['name']}</td>";
				$mount_point	= (! $mount['invalid']) ? basename($mount['mountpoint']) : "-- "._("Invalid Configuration - Remove and Re-add")." --";

				/* Mount point table element. */
				$o_remotes		.= "<td>";

				/* Add the view log icon. */
				if ($mount['command']) {
					$o_remotes	.= "<a class='info' href='/Main/ScriptLog?d=".$mount['device']."'><i class='fa fa-align-left samba-log'></i><span>"._("View Remote SMB")."/"._("NFS Script Log")."</span></a>";
				} else {
					$o_remotes	.= "<i class='fa fa-align-left samba-log'></i>";
				}

				if (($mounted) && ($is_alive) && ($is_available) && (! $is_mounting) && (! $is_unmounting)) {
					/* If the partition is mounted read only, indicate that on the mount point. */
					$read_only	= $mount['remote_read_only'] ? "<font color='red'> (RO)<font>" : "";

					$o_remotes	.= "<i class='fa fa-external-link mount-share'></i><a title='"._("Browse Remote SMB")."/"._("NFS Share")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a>".$read_only;
				} else if (($is_alive) && ($is_available) && (! $is_mounting) && (! $is_unmounting) && (! $mount['invalid'])) {
					$o_remotes	.= "<i class='fa fa-pencil mount-share'></i>";
					$o_remotes	.= "<a title='"._("Change Remote SMB")."/"._("NFS Mount Point")."' class='exec' onclick='chg_samba_mountpoint(\"{$mount['name']}\",\"{$mount_point}\");'>{$mount_point}</a>";
				} else {
					$o_remotes	.= $mount_point;
				}
				$o_remotes			.= "</td>";

				/* Mount button table element. */
				/* Make the mount button. */
				$disable	= (($mount['fstype'] == "root") && ($var['shareDisk'] == "yes" || $var['mdState'] != "STARTED")) ? "disabled" : (($is_alive || $mounted) ? false : true);
				$disable	= (($mount['disable_mount']) || ($mount['invalid'])) ? true : $disable;
				
				/* Set up the mount button operation and text. */
				$buttonFormat	= "<td><button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, '%s', '{$mount['device']}');\" %s><i class='%s'></i>%s</button></td>";

				/* Initilize variables. */
				$operation		= "";
				$text			= "";
				$class			= "";
				$spinner_class	= "fa fa-spinner fa-spin orb";

				switch (true) {
					case ($mount['running']):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Running');
						break;

					case ($is_mounting):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Mounting');
						break;

					case ($is_unmounting):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Unmounting');
						break;

					default:
						if ($mounted) {
							$operation	= "umount";
							$class		= "mount";
							$text		= _('Unmount');
						} else {
							$operation	= "mount";
							$class		= "mount";
							$text		= _('Mount');
						}
						break;
				}

				/* Build the mount button. */
				$button = sprintf($buttonFormat, $operation, ($disable ? "disabled" : ""), $class, $text);

				$o_remotes			.= $button;

				$compressed_name	= MiscUD::compress_string($mount['name']);

				/* Remove SMB/NFS remote share table element. */
				$o_remotes			.= "<td>";
				$o_remotes			.= ($mounted || $is_mounting || $is_unmounting) ? "<i class='fa fa-remove'></i>" : "<a class='exec info' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount['device']}\", \"{$compressed_name}\", \"{$protocol}\");'><i class='fa fa-remove'></i><span>"._("Remove Remote SMB")."/"._("NFS Share")."</span></a>";
				$o_remotes			.= "</td>";

				/* Empty table element. */
				$o_remotes			.= "<td></td>";

				$title 				= _("Remote SMB")."/".("NFS Settings and Script");
				$title 				.= "<br />"._("Disable Mount Button").": ";
				$title 				.= ($mount['disable_mount']) ? "Yes" : "No";
				$title 				.= "<br />"._("Read Only").": ";
				$title 				.= $mount['read_only'] ? "Yes" : "No";
				$title 				.= "<br />"._("Automount").": ";
				$title 				.= $mount['automount'] ? "Yes" : "No";
				$title 				.= "<br />"._("Share").": ";
				$title 				.= $shares_enabled ? (($mount['smb_share']) ? "Yes" : "No") : "Not Enabled";
				$title				.= "<br />"._("Script Enabled").": ";
				$title				.= $mount['enable_script'] != "false" ? "Yes" : "No";

				/* Settings icon table element. */
				$o_remotes			.= "<td>";
				if (! $mount['invalid']) {
					$o_remotes		.= "<a class='info' href='/Main/DeviceSettings?d=".$mount['device']."&l=".$mount['mountpoint']."&n=".($mounted || $is_mounting || $is_unmounting)."&j=".$mount['name']."&m=".json_encode($mount)."'><i class='fa fa-gears'></i><span class='help-title'>$title</span></a>";
				} else {
					$o_remotes		.= "<i class='fa fa-gears disabled'>";
				}
				$o_remotes			.= "</td>";

				/* Empty table element. */
				$o_remotes			.= "<td></td>";

				/* Size, used, and free table elements. */
				$o_remotes			.= "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				$o_remotes			.= render_used_and_free($mount);

				/* End of table row, */
				$o_remotes			.= "</tr>";

				/* Add to the share names. */
				$share_names[$mount['name']] = $mount_point;
			}
		}

		$time		+= microtime(true);
		unassigned_log("Upadte Remote Mounts took ".sprintf('%f', $time)."s!", $UPDATE_DEBUG);

		unassigned_log("Debug: Update ISO mounts...", $UPDATE_DEBUG);

		/* ISO file Mounts. */

		/* Start time for ISO file ops. */
		$time = -microtime(true);

		$iso_mounts = get_iso_mounts();
		if (count($iso_mounts)) {
			foreach ($iso_mounts as $mount) {
				$device			= $mount['device'];
				$mounted		= $mount['mounted'];

				/* Is the device mounting or unmounting. */
				$is_mounting	= $mount['mounting'];
				$is_unmounting	= $mount['unmounting'];

				$is_alive		= $mount['alive'];

				/* Populate the iso line for this device. */
				$o_remotes		.= "<tr>";

				/* Device table element. */
				$o_remotes		.= sprintf( "<td><a class='info'><i class='fa fa-circle %s orb'></i><span>"._("ISO File is")." %s</span></a>ISO</td>", ( $is_alive ? "green-orb" : "grey-orb" ), ( $is_alive ? _("online") : _("offline") ));
				$o_remotes		.= "<td>{$mount['device']}</td>";
				
				/* Mount point table element. */
				$o_remotes			.= "<td>";

				$mount_point	= (! $mount['invalid']) ? basename($mount['mountpoint']) : "-- "._("Invalid Configuration - Remove and Re-add")." --";
				if ($mount['command']) {
					$o_remotes .= "<a class='info' href='/Main/ScriptLog?i=".$mount['device']."'><i class='fa fa-align-left samba-log'></i><span>"._("View ISO File Script Log")."</span></a>";
				} else {
					$o_remotes .= "<i class='fa fa-align-left samba-log' disabled></i>";
				}

				if ((! $is_mounting) && (! $is_unmounting) && ($mounted)) {
					$o_remotes .= "<i class='fa fa-external-link mount-share'></i><a title='"._("Browse ISO File Share")."' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a>";
				} else {
					$o_remotes	.= "<i class='fa fa-pencil mount-share'></i>";
					if ((! $is_mounting) && (! $is_unmounting)) {
						$o_remotes	.= "<a title='"._("Change ISO File Mount Point")."' class='exec' onclick='chg_iso_mountpoint(\"{$mount['device']}\",\"{$mount_point}\");'>{$mount_point}</a>";
					} else {
						$o_remotes	.= $mount_point;
					}
				}
				$o_remotes			.= "</td>";

				/* Remove ISO mount table element. */
				$disable = $is_alive ? "" : "disabled";

				/* Set up the mount button operation and text. */
				$buttonFormat	= "<td><button class='mount' device='{$mount['device']}' onclick=\"disk_op(this, '%s', '{$mount['device']}');\" %s><i class='%s'></i>%s</button></td>";

				/* Initilize variables. */
				$operation		= "";
				$text			= "";
				$class			= "";
				$spinner_class	= "fa fa-spinner fa-spin orb";

				switch (true) {
					case ($mount['running']):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Running');
						break;

					case ($is_mounting):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Mounting');
						break;

					case ($is_unmounting):
						$disable	= true;
						$class		= $spinner_class;
						$text		= _('Unmounting');
						break;

					default:
						if ($mounted) {
							$operation	= "umount";
							$class		= "mount";
							$text		= _('Unmount');
						} else {
							$operation	= "mount";
							$class		= "mount";
							$text		= _('Mount');
						}
						break;
				}

				/* Build the mount button. */
				$button = sprintf($buttonFormat, $operation, ($disable ? "disabled" : ""), $class, $text);

				$o_remotes			.= $button;

				$compressed_device	= MiscUD::compress_string($mount['device']);
				$o_remotes .= $mounted ? "<td><i class='fa fa-remove'></i></td>" : "<td><a class='exec info' style='color:#CC0000;font-weight:bold;' onclick='remove_iso_config(\"{$mount['device']}\", \"{$compressed_device}\");'> <i class='fa fa-remove'></i><span>"._("Remove ISO File Share")."</span></a></td>";

				$title				= _("ISO File Settings and Script");
				$title				.= "<br />"._("Automount").": ";
				$title				.= $mount['automount'] ? "Yes" : "No";
				$title				.= "<br />"._("Share").": Yes";
				$title				.= "<br />"._("Script Enabled").": ";
				$title				.= $mount['enable_script'] != "false" ? "Yes" : "No";

				/* Empty table element. */
				$o_remotes			.= "<td></td>";

				/* Device settings table element. */
				$o_remotes			.= "<td>";
				if (! $mount['invalid']) {
					$o_remotes		.= "<a class='info' href='/Main/DeviceSettings?i=".$device."&l=".$mount['mountpoint']."&n=".($mounted || $is_mounting || $is_unmounting)."&j=".$mount['file']."'><i class='fa fa-gears'></i><span class='help-title'>$title</span></a>";
				} else {
					$o_remotes		.= "<i class='fa fa-gears' disabled></i>";
				}
				$o_remotes			.= "</td>";

				/* Empty table element. */
				$o_remotes			.= "<td></td>";

				/* Size, used, and free table elements. */
				$o_remotes			.= "<td>".my_scale($mount['size'], $unit)." $unit</td>";
				$o_remotes			.= render_used_and_free($mount);

				/* End of table row. */
				$o_remotes			.= "</tr>";

				/* Add to the share names. */
				$share_names[$mount['device']] = $mount_point;
			}
		}

		/* If there are no remote or ISO mounts, show message. */
		if (! count($samba_mounts) && ! count($iso_mounts)) {
			$o_remotes .= "<tr><td colspan='11' style='text-align:center;'>"._('No Remote SMB')."/"._('NFS or ISO File Shares configured').".</td></tr>";
		}

		$time		+= microtime(true);
		unassigned_log("Update ISO Files took ".sprintf('%f', $time)."s!", $UPDATE_DEBUG);

		unassigned_log("Debug: Update Historical Devices...", $UPDATE_DEBUG);

		/* Historical devices. */
		/* Start time for Historical Devices ops. */
		$time = -microtime(true);

		$o_historical = "";

		/* Get the UD configuration. */
		$config			= $ud_config;

		ksort($config, SORT_NATURAL);
		$disks_serials	= [];
		foreach ($all_disks as $disk) {
			$disks_serials[] = $disk['serial'];
		}

		/* Organize the historical devices. */
		$historical = [];
		foreach ($config as $serial => $value) {
			if ($serial != "Config") {
				if (! preg_grep("#{$serial}#", $disks_serials)){
					if ((isset($config[$serial]['unassigned_dev'])) && ($config[$serial]['unassigned_dev']) && (! in_array($config[$serial]['unassigned_dev'], $disk_names))) {
						$disk_names[$serial] = $config[$serial]['unassigned_dev'];
					}
					$mountpoint		= isset($config[$serial]['mountpoint.1']) ? basename($config[$serial]['mountpoint.1']) : "";
					$mntpoint		= ($mountpoint) ? " (".$mountpoint.")" : "";
					$disk_dev		= (isset($config[$serial]['unassigned_dev'])) ? $config[$serial]['unassigned_dev'] : "";
					$disk_dev		= ($disk_dev) ? $disk_dev : _("none");

					/* Create a unique disk_display string to be sure each device is unique. */
					$disk_display	= $disk_dev."_".$serial;

					/* Save this devices info. */
					$historical[$disk_display]['serial']		= $serial;
					$historical[$disk_display]['device']		= $disk_dev;
					$historical[$disk_display]['mntpoint']		= $mntpoint;
					$historical[$disk_display]['mountpoint']	= $mountpoint;
				}
			}
		}
		ksort($historical, SORT_NATURAL);

		/* Display the historical devices. */
		foreach ($historical as $disk_display => $value) {
			/* See if the disk is in standby and can be attached. */
			$is_standby		= (MiscUD::get_device_host($historical[$disk_display]['serial']) && (empty(glob("/dev/disk/by-id/*-".$historical[$disk_display]['serial']."*"))));
	
			/* Add to the historical devices. */

			/* Start of taable row for this device. */
			$o_historical	.= "<tr>";

			$o_historical	.= sprintf( "<td><a class='info'><i class='fa fa-minus-circle %s orb'></i><span>"._("Historical Device is")." %s</span></a>".$historical[$disk_display]['device']."</td>", ( $is_standby ? "green-orb" : "grey-orb" ), ( $is_standby ? _("in standby") : _("offline") ));

			$o_historical	.= "<td>";
			$o_historical	.= $historical[$disk_display]['serial'].$historical[$disk_display]['mntpoint'];
			$o_historical	.= "<td>";

			/* Empty table element. */
			$o_historical	.= "<td></td>";

			/* Remove device table element. */
			$o_historical	.= "<td>";
			if (! $is_standby) {
				$compressed_serial	= MiscUD::compress_string($historical[$disk_display]['serial']);
				$o_historical .= "<a style='color:#CC0000;font-weight:bold;cursor:pointer;' class='exec info' onclick='remove_disk_config(\"{$historical[$disk_display]['serial']}\", \"{$compressed_serial}\")'><i class='fa fa-remove' disabled></i><span>"._("Remove Device Configuration")."</span></a>";
			} else {
				$o_historical .= "<i class='fa fa-remove' disabled></i>";
			}
			$o_historical	.= "</td>";

			/* Device settings table element. */
			$o_historical	.= "<td>";
			$o_historical	.= "<a class='info' href='/Main/DeviceSettings?s=".$historical[$disk_display]['serial']."&l=".$historical[$disk_display]['mountpoint']."&p="."1"."&t=true'><i class='fa fa-gears'></i><span>"._("Historical Device Settings and Script")."</span></a>";
			$o_historical	.= "</td>";


			/* Empty table elements. */
			$o_historical .= "<td></td><td></td><td></td><td></td><td></td>";

			/* End of table row for this device. */
			$o_historical	.= "</tr>";
		}

		$time		+= microtime(true);
		unassigned_log("Update Historical Devices took ".sprintf('%f', $time)."s!", $UPDATE_DEBUG);

		unassigned_log("Debug: End", $UPDATE_DEBUG);

		/* Save the current disk names for a duplicate check. */
		MiscUD::save_json($paths['disk_names'], $disk_names);

		/* Save the UD share names for duplicate check. */
		MiscUD::save_json($paths['share_names'], $share_names);

		echo json_encode(array( 'disks' => $o_disks, 'remotes' => $o_remotes, 'historical' => $o_historical ));
		break;

	case 'update_ping':
		/* Refresh the ping status in the background. */
		exec($docroot."/plugins/".$plugin."/scripts/get_ud_stats ping & 2>/dev/null");
		break;

	case 'get_content_json':
		/* Get the UD disk info and return in a json format. */
		$all_disks	= get_all_disks_info();
		echo json_encode($all_disks);
		break;

	/*	CONFIG	*/
	case 'automount':
		/* Update auto mount configuration setting. */
		$serial = urldecode($_POST['device']);
		$status = urldecode($_POST['status']);

		$result	= toggle_automount($serial, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'show_partitions':
		/* Update show partitions configuration setting. */
		$serial = urldecode($_POST['serial']);
		$status = urldecode($_POST['status']);

		$result	= set_config($serial, "show_partitions", ($status == "true") ? "yes" : "no");
		echo json_encode(array( 'result' => $result ));
		break;

	case 'background':
		/* Update background configuration setting. */
		$device	= urldecode($_POST['device']);
		$part	= urldecode($_POST['part']);
		$status = urldecode($_POST['status']) == "yes" ? "true" : "false";

		$result	= set_config($device, "command_bg.{$part}", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'enable_script':
		/* Update the enable script configuration setting. */
		$device	= urldecode($_POST['device']);
		$part	= urldecode($_POST['part']);
		$status = urldecode($_POST['status']) == "yes" ? "true" : "false";

		$result	= set_config($device, "enable_script.{$part}", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'set_command':
		/* Set the user command configuration setting. */
		$serial		= urldecode($_POST['serial']);
		$part		= urldecode($_POST['part']);
		$cmd		= urldecode($_POST['command']);
		$user_cmd	= urldecode($_POST['user_command']);

		$file_path = pathinfo($cmd);
		if ((isset($file_path['dirname'])) && ($file_path['dirname'] == "/boot/config/plugins/unassigned.devices") && ($file_path['filename'])) {
			set_config($serial, "user_command.{$part}", $user_cmd);
			$result	= set_config($serial, "command.{$part}", $cmd);
			echo json_encode(array( 'result' => $result ));
		} else {
			if ($cmd) {
				unassigned_log("Warning: Cannot use '{$cmd}' as a device script file name.");
			}
			echo json_encode(array( 'result' => false ));
		}
		break;

	case 'set_volume':
		/* Set apfs volume configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$part	= urldecode($_POST['part']);
		$vol	= urldecode($_POST['volume']);

		$result	= set_config($serial, "volume.{$part}", $vol);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'set_name':
		/* Set disk name configuration setting. */
		$serial		= urldecode($_POST['serial']);
		$dev		= urldecode($_POST['device']);

		$name		= safe_name(urldecode($_POST['name']));
		$disk_names = MiscUD::get_json($paths['disk_names']);

		/* Remove our disk name. */
		unset($disk_names[$dev]);
		unset($disk_names[$serial]);

		/* Is the name already being used? */
		$dev_name	= get_disk_dev($dev);
		$result		= true;
		if ($name != $dev_name) {
			if ((! $name) || (! is_dev_device($name)) && (! is_sd_device($name))) {
				if (! in_array($name, $disk_names)) {
					if (! $name) {
						$name	= $dev_name;
					}
					$ser		= $serial;
					$ser		.= $dev ? " (".$dev.")" : "";	
					$old_name	= get_config($serial, "unassigned_dev");
					if (($old_name) != ($name)) {
						unassigned_log("Set Disk Name on '".$ser."' to '".$name."'");
						$result	= set_config($serial, "unassigned_dev", $name);
					}
				} else {
					unassigned_log("Warning: Disk Name '".$name."' is already being used on another device.");
					$result		= false;
				}
			} else {
				unassigned_log("Warning: Disk Name cannot be another device designation.");
				$result		= false;
			}
		}

		echo json_encode(array( 'result' => $result ));
		break;

	case 'remove_config':
		/* Remove historical disk configuration. */
		$serial	= urldecode($_POST['serial']);

		$result	= remove_config_disk($serial);
		echo json_encode($result);
		break;

	case 'toggle_share':
		/* Toggle the share configuration setting. */
		$info	= json_decode($_POST['info'], true);
		$status	= urldecode($_POST['status']);

		$result	= toggle_share($info['serial'], $info['part'], $status);
		if (($result) && ($info['target'])) {
			$fat_fruit	= (($info['fstype'] == "vfat") || ($info['fstype'] == "exfat"));
			add_smb_share($info['mountpoint'], (! $info['part_read_only']), $fat_fruit);
			add_nfs_share($info['mountpoint']);
		} else if ($info['mounted']) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_historical_share':
		/* Toggle the historical share configuration setting. */
		$serial		= urldecode($_POST['serial']);
		$partition	= urldecode($_POST['part']);
		$status		= urldecode($_POST['status']);

		$result		= toggle_share($serial, $partition, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_read_only':
		/* Toggle the disk read only configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$part	= urldecode($_POST['part']);
		$status	= urldecode($_POST['status']);

		$result	= toggle_read_only($serial, $status, $part);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_pass_through':
		/* Toggle the disk pass through configuration setting. */
		$serial	= urldecode($_POST['serial']);
		$part	= urldecode($_POST['part']);
		$status	= urldecode($_POST['status']);

		$result	= toggle_pass_through($serial, $status, $part);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_disable_mount':
		/* Toggle the disable mount button setting. */
		$serial	= urldecode($_POST['device']);
		$part	= urldecode($_POST['part']);
		$status	= urldecode($_POST['status']);

		$result	= toggle_disable_mount($serial, $status, $part);
		echo json_encode(array( 'result' => $result ));
		break;

	/* ALL DEVICES */
	case 'mount':
		/* Mount a disk device. */
		$device	= urldecode($_POST['device']);

		$return = trim(shell_exec("/usr/bin/nice plugins/".$plugin."/scripts/rc.unassigned mount ".escapeshellarg($device)." & 2>/dev/null") ?? "");
		echo json_encode($return == "success");
		break;

	case 'umount':
		/* Unmount a disk device. */
		$device	= urldecode($_POST['device']);

		$return = trim(shell_exec("/usr/bin/nice plugins/".$plugin."/scripts/rc.unassigned umount ".escapeshellarg($device)." & 2>/dev/null") ?? "");
		echo json_encode($return == "success");
		break;

	case 'get_device_script':
		/* Get the contents of the device script file. */
		$file			= urldecode($_POST['file']);
		if ($file) {
			$result		= file_get_contents($file);
		} else {
			$result		= "";
		}
		echo json_encode($result);
		break;

	/* DISK */
	case 'rescan_disks':
		/* Refresh all disk partition information, update config files from flash, and clear status files. */
		exec($docroot."/plugins/".$plugin."/scripts/copy_config.sh");

		$sf		= $paths['dev_state'];
		if (is_file($sf)) {
			$devs = @parse_ini_file($sf, true);
			foreach ($devs as $d) {
				$device = "/dev/".$d['device'];

				/* Refresh partition information. */
				exec("/usr/sbin/partprobe ".escapeshellarg($device));
			}
		}

		unassigned_log("Refreshed Disks and Configuration.");
		unassigned_log("Debug: Rescan Disks: initiated a Hotplug event.", $UDEV_DEBUG);

		/* Set flag to tell Unraid to update devs.ini file of unassigned devices. */
		sleep(1);
		@file_put_contents($paths['hotplug_event'], "");

		echo json_encode(true);
		break;

	case 'format_disk':
		/* Format a disk. */
		$device		= urldecode($_POST['device']);
		$fs			= urldecode($_POST['fs']);
		$pass		= isset($_POST['pass']) ? urldecode($_POST['pass']) : "";
		$pool_name	= isset($_POST['pool_name']) ? urldecode($_POST['pool_name']) : "";

		$pool_name	= safe_name($pool_name, false);

		/* Create the state file. */
		@touch(sprintf($paths['formatting'], basename($device)));

		/* Give things a chance to settle.  This gives the pageRefresh a chance to catch up. */
		usleep(500 * 1000);

		/* Format the disk. */
		$result		= format_disk($device, $fs, $pass, $pool_name);
		echo json_encode(array( 'status' => $result ));

		/* Erase the state file. */
		@unlink(sprintf($paths['formatting'], basename($device)));
		break;

	/*	SAMBA	*/
	case 'list_samba_hosts':
		/* Get a list of samba hosts. */
		$network	= $_POST['network'];

		$names		= [];
		foreach ($network as $iface) {
			$ip			= $iface['ip'];
			$netmask 	= $iface['netmask'];
			if (MiscUD::is_ip($ip) && MiscUD::is_ip($netmask)) {
				/* Check for SMB servers having their port open for SMB. */
				exec($docroot."/plugins/".$plugin."/scripts/hosts_port_ping.sh ".escapeshellarg($ip)." ".escapeshellarg($netmask)." ".SMB_PORT, $hosts);

				/* Do a name lookup on each IP address found in hosts. */
				foreach ($hosts as $host) {
					/* Resolve name as a local server. */
					$name	= trim(shell_exec("/sbin/arp -a ".escapeshellarg($host)." 2>&1 | grep -v 'arp:' | /bin/awk '{print $1}'") ?? "");
					if ($name) {
						$name		= strtoupper($name);
						if ($name == "?") {
							/* Look up the server name using nmblookup. */
							$name		= trim(shell_exec("/usr/bin/nmblookup -A ".escapeshellarg($host)." 2>/dev/null | grep -v 'GROUP' | grep -Po '[^<]*(?=<00>)' | head -n 1") ?? "");
						}
					} else if ($host == $_SERVER['SERVER_ADDR']) {
						$name		= strtoupper($var['NAME']);
					}
					$name			= str_replace( array(".".$local_tld, ".".$default_tld), "", $name);
					$names[] 		= $name ? $name : $host;
				}
			}
		}
		$names	= array_unique($names);
		natsort($names);
		echo implode(PHP_EOL, $names);
		break;

	case 'list_samba_shares':
		/* Get a list of samba shares for a specific host. */
		$ip			= urldecode($_POST['IP']);
		$user		= isset($_POST['USER']) ? $_POST['USER'] : "";
		$pass		= isset($_POST['PASS']) ? $_POST['PASS'] : "";
		$domain		= isset($_POST['DOMAIN']) ? $_POST['DOMAIN'] : "";

		$ip			= implode("",explode("\\", $ip));
		$ip			= strtoupper(stripslashes(trim($ip)));

		/* Remove the 'local' and 'default' tld reference as they are unnecessary. */
		if (! MiscUD::is_ip($ip)) {
			$ip		= str_replace( array(".".$local_tld, ".".$default_tld), "", $ip);
		}

		/* Create the credentials file. */
		@file_put_contents("{$paths['authentication']}", "username=".$user."\n");
		@file_put_contents("{$paths['authentication']}", "password=".$pass."\n", FILE_APPEND);
		@file_put_contents("{$paths['authentication']}", "domain=".$domain."\n", FILE_APPEND);

		/* Update this server status before listing shares. */
		exec($docroot."/plugins/".$plugin."/scripts/get_ud_stats is_online ".escapeshellarg($ip)." "."SMB");

		/* Get a list of samba shares on this server. */
		$list	= shell_exec("/usr/bin/smbclient -t2 -g -L ".escapeshellarg($ip)." --authentication-file=".escapeshellarg($paths['authentication'])." 2>/dev/null | /usr/bin/awk -F'|' '/Disk/{print $2}' | sort");

		/* Shred the authentication file and remove the credential variables. */
		exec("/bin/shred -u ".escapeshellarg($paths['authentication']));
		unset($user);
		unset($pass);
		unset($domain);
		echo $list;
		break;

	/*	NFS	*/
	case 'list_nfs_hosts':
		/* Get a list of nfs hosts. */
		$network	= $_POST['network'];
		$names		= [];

		foreach ($network as $iface) {
			$ip			= $iface['ip'];
			$netmask 	= $iface['netmask'];
			if (MiscUD::is_ip($ip) && MiscUD::is_ip($netmask)) {
				/* Check for NFS servers having their port open for NFS. */
				exec($docroot."/plugins/".$plugin."/scripts/hosts_port_ping.sh ".escapeshellarg($ip)." ".escapeshellarg($netmask)." ".NFS_PORT." 2>/dev/null", $hosts);

				/* Do a name lookup on each IP address found in hosts. */
				foreach ($hosts as $host) {
					/* Resolve name as a local server. */
					$name	= trim(shell_exec("/sbin/arp -a ".escapeshellarg($host)." 2>&1 | grep -v 'arp:' | /bin/awk '{print $1}'") ?? "");
					if ($name) {
						$name		= strtoupper($name);
						if ($name == "?") {
							$name	= "";
						}
					} else if ($host == $_SERVER['SERVER_ADDR']) {
						$name		= strtoupper($var['NAME']);
					}

					$name			= str_replace( array(".".$local_tld, ".".$default_tld), "", $name);
					$names[] 		= $name ? $name : $host;
				}
			}
		}
		$names	= array_unique($names);
		natsort($names);
		echo implode(PHP_EOL, $names);
		break;

	case 'list_nfs_shares':
		/* Get a list of nfs shares for a specific host. */
		$ip			= urldecode($_POST['IP']);

		$ip			= implode("",explode("\\", $ip));
		$ip			= strtoupper(stripslashes(trim($ip)));

		/* Remove the 'local' and 'default' tld reference as they are unnecessary. */
		if (! MiscUD::is_ip($ip)) {
			$ip		= str_replace( array(".".$local_tld, ".".$default_tld), "", $ip);
		}

		/* Update this server status before listing shares. */
		exec($docroot."/plugins/".$plugin."/scripts/get_ud_stats is_online ".escapeshellarg($ip)." "."NFS");

		/* List the shares. */
		$result		= timed_exec(10, "/usr/sbin/showmount --no-headers -e ".escapeshellarg($ip)." 2>/dev/null | rev | cut -d' ' -f2- | rev | sort");
		$rc			= ($result != "command timed out") ? $result : "";
		echo $rc ? $rc : " ";
		break;

	/* SMB SHARES */
	case 'add_samba_share':
		/* Add a samba share configuration. */
		$ip			= urldecode($_POST['IP']);
		$protocol	= urldecode($_POST['PROTOCOL']);
		$user		= isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$domain		= isset($_POST['DOMAIN']) ? urldecode($_POST['DOMAIN']) : "";
		$pass		= isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$path		= isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";

		$rc			= true;

		$ip			= implode("",explode("\\", $ip));
		$ip			= strtoupper(stripslashes(trim($ip)));
		$path		= implode("",explode("\\", $path));
		$path		= stripslashes(trim($path));
		$share		= basename($path);

		/* Remove the 'local' and 'default' tld reference as they are unnecessary. */
		if (! MiscUD::is_ip($ip)) {
			$ip		= str_replace( array(".".$local_tld, ".".$default_tld), "", $ip);
		}

		/* See if there is another mount with a different protocol. */
		foreach (get_samba_mounts() as $mount) {
			if (($mount['ip'] == $ip) && (basename($mount['path']) == basename($path)) && ($mount['protocol'] != $protocol)) {
				$same_protocol	= $mount['protocol'];
				$rc	= false;
			}
		}

		if ($rc) {
			/* Don't save any information if the share is blank. */
			if (! empty($share)) {
				/* Clean up the device name so it is safe for php. */
				$safe_path		= safe_name($path, false);
				$device	= ($protocol == "NFS") ? $ip.":".$safe_path : "//".$ip."/".$safe_path;

				/* Remove dollar signs in device. */
				$device	= str_replace("$", "", $device);

				/* Set this configuration. */
				set_samba_config($device, "protocol", $protocol);
				set_samba_config($device, "ip", (MiscUD::is_ip($ip) ? $ip : strtoupper($ip)));
				set_samba_config($device, "path", $path);

				if ($protocol == "SMB") {
					set_samba_config($device, "user", $user);
					set_samba_config($device, "domain", $domain);
					set_samba_config($device, "pass", encrypt_data($pass));
				}

				set_samba_config($device, "share", $share);
			} else {
				unassigned_log("Warning: share cannot be blank.");
				$rc	= false;
			}
		} else {
			unassigned_log("Warning: '".$share."' is already added as a '".$same_protocol."' share.");
		}
		echo json_encode($rc);
		break;

	case 'remove_samba_config':
		/* Remove samba configuration. */
		$device		= urldecode($_POST['device']);

		$result		= remove_config_samba($device);
		echo json_encode($result);
		break;

	case 'samba_automount':
		/* Set samba auto mount configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);

		$result		= toggle_samba_automount($device, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_samba_share':
		/* Toggle samba share configuration setting. */
		$info		= json_decode($_POST['info'], true);
		$status		= urldecode($_POST['status']);

		$result		= toggle_samba_share($info['device'], $status);
		if (($result) && ($info['target'])) {
			add_smb_share($info['mountpoint'], $info['fstype'] == "root");
			add_nfs_share($info['mountpoint']);
		} else if ($info['mounted']) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
		}
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_samba_readonly':
		/* Toggle the disable mount button setting. */
		$serial	= urldecode($_POST['serial']);
		$status	= urldecode($_POST['status']);

		$result	= toggle_samba_readonly($serial, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'toggle_samba_disable_mount':
		/* Toggle the disable mount button setting. */
		$device	= urldecode($_POST['device']);
		$status	= urldecode($_POST['status']);

		$result	= toggle_samba_disable_mount($device, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'samba_background':
		/* Set samba share background configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";

		$result		= set_samba_config($device, "command_bg", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'samba_enable_script':
		/* Set samba share enable script configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";

		$result		= set_samba_config($device, "enable_script", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'samba_enable_encryption':
		/* Set samba mount encryption configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);

		$result		= set_samba_config($device, "encryption", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'set_samba_command':
		/* Set samba share user command configuration setting. */
		$device		= urldecode($_POST['device']);
		$cmd		= urldecode($_POST['command']);
		$user_cmd	= urldecode($_POST['user_command']);

		$file_path = pathinfo($cmd);
		if ((isset($file_path['dirname'])) && ($file_path['dirname'] == "/boot/config/plugins/unassigned.devices") && ($file_path['filename'])) {
			set_samba_config($device, "user_command", urldecode($_POST['user_command']));
			$result		= set_samba_config($device, "command", $cmd);
			echo json_encode(array( 'result' => $result ));
		} else {
			if ($cmd) {
				unassigned_log("Warning: Cannot use '{$cmd}' as a device script file name.");
			}
			echo json_encode(array( 'result' => false ));
		}
		break;

	/* ISO FILE SHARES */
	case 'add_iso_share':
		/* Add iso file share. */
		$rc			= true;
		$file		= isset($_POST['ISO_FILE']) ? urldecode($_POST['ISO_FILE']) : "";
		$file 		= implode("", explode("\\", $file));
		$file		= stripslashes(trim($file));
		if (is_file($file)) {
			$info = pathinfo($file);

			/* Clean up the file name for the share so php won't be upset. */
			$share	= safe_name($info['filename']);

			/* Clean up the file name to use as the device. */
			$device		= safe_name($info['basename']);

			set_iso_config("{$device}", "file", $file);
			set_iso_config("{$device}", "share", $share);
		} else {
			unassigned_log("ISO File '{$file}' not found.");
			$rc		= false;
		}
		echo json_encode($rc);
		break;

	case 'remove_iso_config':
		/* Remove the iso share configuration. */
		$device = urldecode($_POST['device']);
		$result	= remove_config_iso($device);
		echo json_encode($result);
		break;

	case 'iso_automount':
		/* Set the iso auto mount configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']);
		$result		= toggle_iso_automount($device, $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'iso_background':
		/* Set the background configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";
		$result		= set_iso_config($device, "command_bg", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'iso_enable_script':
		/* Set the enable configuration setting. */
		$device		= urldecode($_POST['device']);
		$status		= urldecode($_POST['status']) == "yes" ? "true" : "false";
		$result		= set_iso_config($device, "enable_script", $status);
		echo json_encode(array( 'result' => $result ));
		break;

	case 'set_iso_command':
		/* Set the iso command file configuration setting. */
		$device		= urldecode($_POST['device']);
		$cmd		= urldecode($_POST['command']);
		$file_path = pathinfo($cmd);
		if ((isset($file_path['dirname'])) && ($file_path['dirname'] == "/boot/config/plugins/unassigned.devices") && ($file_path['filename'])) {
		$result		= set_iso_config($device, "command", $cmd);
			echo json_encode(array( 'result' => $result ));
		} else {
			if ($cmd) {
				unassigned_log("Warning: Cannot use '{$cmd}' as a device script file name.");
			}
			echo json_encode(array( 'result' => false ));
		}
		break;

	/* ROOT SHARES */
	case 'add_root_share':
		/* Add root file share. */
		$ip			= strtoupper($var['NAME']);
		$path		= urldecode($_POST['path']);
		$device		= "//".$ip.$path;
		$share		= basename($path);

		$rc			= true;

		/* See if there is already a rootshare mount. */
		foreach (get_samba_mounts() as $mount) {
			if (($mount['path'] != $path) && ($mount['protocol'] == "ROOT")) {
				$rc	= false;
			}
		}
		if ($rc) {
			set_samba_config("{$device}", "protocol", "ROOT");
			set_samba_config("{$device}", "ip", $ip);
			set_samba_config("{$device}", "path", $path);
			set_samba_config("{$device}", "share", safe_name($share, false));
		} else {
			unassigned_log("Warning: Root Share is already assigned!");
		}

		echo json_encode($rc);
		break;

	case 'remove_root_config':
		/* Remove the root share configuration. */
		$device = urldecode($_POST['device']);
		$result	= remove_config_samba($device);
		echo json_encode($result);
		break;

	/*	MISC */
	case 'rm_partition':
		/* Remove a partition from a disk. */
		$serial		= urldecode($_POST['serial']);
		$device		= urldecode($_POST['device']);
		$partition	= urldecode($_POST['partition']);

		/* A disk can't be set to automount. */
		if (is_automount($serial)) {
			toggle_automount($serial, false);
		}
		$result		= remove_partition($device, $partition);
		echo json_encode($result);
		break;

	case 'clr_disk':
		/* Remove all partitions from a disk. */
		$serial		= urldecode($_POST['serial']);
		$device		= urldecode($_POST['device']);

		/* A disk can't be set to automount. */
		if (is_automount($serial)) {
			toggle_automount($serial, false);
		}
		$result		= remove_all_partitions($device);
		echo json_encode($result);
		break;

	case 'spin_down_disk':
		/* Spin down a disk device. */
		$device		= urldecode($_POST['device']);

		/* Set the spinning_down state. */
		$tc			= $paths['run_status'];
		$run_status	= file_exists($tc) ? json_decode(file_get_contents($tc), true) : [];
		if ($run_status[$device]['running'] == "yes") {
			$run_status[$device]['spin_time']	= time();
			$run_status[$device]['spin']		= "down";
			@file_put_contents($tc, json_encode($run_status));
			$result	= MiscUD::spin_disk(true, $device);
			echo json_encode($result);
		}
		break;

	case 'spin_up_disk':
		/* Spin up a disk device. */
		$device		= urldecode($_POST['device']);

		/* Set the spinning_up state. */
		$tc			= $paths['run_status'];
		$run_status	= file_exists($tc) ? json_decode(file_get_contents($tc), true) : [];
		if ($run_status[$device]['running'] == "no") {
			$run_status[$device]['spin_time']	= time();
			$run_status[$device]['spin']		= "up";
			@file_put_contents($tc, json_encode($run_status));
			$result	= MiscUD::spin_disk(false, $device);
			echo json_encode($result);
		}
		break;

	case 'chg_mountpoint':
		/* Change a disk mount point. */
		$serial			= urldecode($_POST['serial']);
		$partition		= urldecode($_POST['partition']);
		$device			= urldecode($_POST['device']);
		$fstype			= urldecode($_POST['fstype']);
		$mountpoint		= basename(safe_name(urldecode($_POST['mountpoint']), false));
		if ((! is_dev_device($mountpoint)) && (! is_sd_device($mountpoint))) {
			$result		= change_mountpoint($serial, $partition, $device, $fstype, $mountpoint);
		} else {
			unassigned_log("Warning: Mount Point cannot be a device designation.");
			$result		= false;
		}
		echo json_encode($result);
		break;

	case 'chg_samba_mountpoint':
		/* Change a samba share mount point. */
		$device			= urldecode($_POST['device']);
		$mountpoint		= basename(safe_name(basename(urldecode($_POST['mountpoint'])), false));
		$result			= change_samba_mountpoint($device, $mountpoint);
		echo json_encode($result);
		break;

	case 'chg_iso_mountpoint':
		/* Change an iso file mount point. */
		$device			= urldecode($_POST['device']);
		$mountpoint		= safe_name(basename(urldecode($_POST['mountpoint'])), false);
		$result			= change_iso_mountpoint($device, $mountpoint);
		echo json_encode($result);
		break;

	default:
		unassigned_log("Undefined POST action - ".$_POST['action'].".");
		break;
	}
?>
