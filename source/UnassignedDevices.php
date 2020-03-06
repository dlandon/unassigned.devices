<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2020, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
require_once("plugins/{$plugin}/include/lib.php");
require_once("webGui/include/Helpers.php");
$csrf_token = $var['csrf_token'];

if (isset($_POST['display'])) $display = $_POST['display'];
if (isset($_POST['var'])) $var = $_POST['var'];

function pid_is_running($pid) {
	return file_exists( "/proc/$pid" );
}

function is_tmux_executable() {
	return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
}

function tmux_is_session($name) {
	if (is_tmux_executable()) {
		exec('/usr/bin/tmux ls 2>/dev/null|/usr/bin/cut -d: -f1', $screens);
		return in_array($name, $screens);	} else {
		return false;
	}
}

function netmasks($netmask, $rev = false)
{
	$netmasks = [	"255.255.255.252"	=> "30",
					"255.255.255.248"	=> "29",
					"255.255.255.240"	=> "28",
					"255.255.255.224"	=> "27",
					"255.255.255.192"	=> "26",
					"255.255.255.128"	=> "25",
					"255.255.255.0"		=> "24",
					"255.255.254.0"		=> "23",
					"255.255.252.0"		=> "22",
					"255.255.248.0"		=> "21",
					"255.255.240.0" 	=> "20",
					"255.255.224.0" 	=> "19",
					"255.255.192.0" 	=> "18",
					"255.255.128.0" 	=> "17",
					"255.255.0.0"		=> "16", 
				];
	return $rev ?	array_flip($netmasks)[$netmask] : $netmasks[$netmask];
}

function render_used_and_free($partition, $mounted) {
	global $display;

	$o = "";
	if (strlen($partition['target']) && $mounted) {
		$free_pct = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
		$used_pct = 100-$free_pct;
	    if ($display['text'] % 10 == 0) {
			$o .= "<td>".my_scale($partition['used'], $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($partition['used'], $unit)." $unit</span></div></td>";
		}
	    if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$o .= "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($partition['avail'], $unit)." $unit</span></div></td>";
		}

	} else {
		$o .= "<td>-</td><td>-</td>";
	}
	return $o;
}

function render_used_and_free_disk($disk, $mounted) {
	global $display;

	$o = "";
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
			$o .= "<td>".my_scale($used, $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$used_pct%' class='".usage_color($display,$used_pct,false)."'></span><span>".my_scale($used, $unit)." $unit</span></div></td>";
		}
	    if ($display['text'] < 10 ? $display['text'] % 10 == 0 : $display['text'] % 10 != 0) {
			$o .= "<td>".my_scale($avail, $unit)." $unit</td>";
		} else {
			$o .= "<td><div class='usage-disk'><span style='margin:0;width:$free_pct%' class='".usage_color($display,$free_pct,true)."'></span><span>".my_scale($avail, $unit)." $unit</span></div></td>";
		}
	} else {
		$o .= "<td>-</td><td>-</td>";
	}
	return $o;
}

function render_partition($disk, $partition, $total=FALSE) {
	global $plugin, $paths, $echo, $csrf_token;

	if (! isset($partition['device'])) return array();
	$out = array();
	$mounted =	(isset($partition["mounted"])) ? $partition["mounted"] : is_mounted($partition['device']);
	if ($mounted && is_file(get_config($disk['serial'],"command.{$partition['part']}"))) {
		$fscheck = "<a title='Execute Script as udev simulating a device being installed' class='exec' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/script.php?device={$partition['device']}&owner=udev\",\"Execute Script\",600,900);'><i class='fa fa-flash partition'></i>{$partition['part']}</a>";
	} elseif ( (! $mounted &&	$partition['fstype'] != 'btrfs') ) {
		$fscheck = "<a title='File System Check' class='exec' onclick='openWindow_fsck(\"/plugins/{$plugin}/include/fsck.php?device={$partition['device']}&fs={$partition['fstype']}&type=ro&mapper={$partition['luks']}\",\"Check filesystem\",600,900);'><i class='fa fa-th-large partition'></i>{$partition['part']}</a>";
	} else {
		$fscheck = "<i class='fa fa-th-large partition'></i>{$partition['part']}";
	}

	$rm_partition = (file_exists("/usr/sbin/parted") && get_config("Config", "destructive_mode") == "enabled") ? "<span title='Remove Partition' device='{$partition['device']}' class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$disk['device']}\",\"{$partition['part']}\");'><i class='fa fa-remove hdd'></i></span>" : "";
	$mpoint = "<span>{$fscheck}<i class='fa fa-arrow-right'></i>";
	$mount_point = basename($partition['mountpoint']);
	if ($mounted) {
		$mpoint .= "<a title='Browse Share' href='/Main/Browse?dir={$partition['mountpoint']}'>{$mount_point}</a></span>";
	} else {
		$mount_point = basename($partition['mountpoint']);
		$mpoint .= "<form title='Click to Change Device Mount Point - Press Enter to save' method='POST' action='/plugins/{$plugin}/UnassignedDevices.php' target='progressFrame' class='inline'>";
		$mpoint .= "<span class='text exec'><a>{$mount_point}</a></span>";
		$mpoint .= "<input type='hidden' name='action' value='change_mountpoint'/>";
		$mpoint .= "<input type='hidden' name='serial' value='{$partition['serial']}'/>";
		$mpoint .= "<input type='hidden' name='partition' value='{$partition['part']}'/>";
		$mpoint .= "<input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden />";
		$mpoint .= "<input type='hidden' name='csrf_token' value='{$csrf_token}'/>";
		$mpoint .= "</form> {$rm_partition}</span>";
	}
	$mbutton = make_mount_button($partition);
	
	$out[] = "<tr class='$outdd toggle-parts toggle-".basename($disk['device'])."' name='toggle-".basename($disk['device'])."' style='display:none;' >";
	$out[] = "<td></td>";
	$out[] = "<td>{$mpoint}</td>";
	$out[] = "<td>{$mbutton}</td>";
	$out[] = "<td>-</td>";
	$fstype = $partition['fstype'];
	if ($total) {
		foreach ($disk['partitions'] as $part) {
			if ($part['fstype']) {
				$fstype = $part['fstype'];
				break;
			}
		}
	}

	$out[] = "<td>".($fstype == "crypto_LUKS" ? "luks" : $fstype)."</td>";
	$out[] = "<td>".my_scale($partition['size'], $unit)." $unit</td>";
	if ($total) {
		$mounted_disk = FALSE;
		$open_files = 0;
		foreach ($disk['partitions'] as $part) {
			if (is_mounted($part['device'])) {
				$open_files		+= $part['openfiles'];
				$mounted_disk	= TRUE;
			}
		}

		$out[] = "<td>".($mounted_disk && strlen($open_files) ? $open_files : "-")."</td>";
	} else {
		$out[] = "<td>".($partition['openfiles'] ? $partition['openfiles'] : "-")."</td>";
	}
	if ($total) {
		$mounted_disk = FALSE;
		foreach ($disk['partitions'] as $part) {
			if (is_mounted($part['device'])) {
				$mounted_disk = TRUE;
				break;
			}
		}
		$out[] = render_used_and_free_disk($disk, $mounted_disk);
	} else {
		$out[] = render_used_and_free($partition, $mounted);
	}
	$out[] = "<td title='Turn on to mark this Device as passed through to a VM or Docker'><input type='checkbox' class='pass_through' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['pass_through']) ? 'checked':'')."></td>";
	if ($mounted) {
		$out[] = ($disk['partitions'][0]['read_only'] == "yes") ? "<td><span>Yes</span></td>" : "<td><span>No&nbsp;</span></td>";
	} else {
		$out[] = "<td title='Turn on to Mount Device Read only'><input type='checkbox' class='read_only' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['read_only']) ? 'checked':'')."></td>";
	}
	$out[] = "<td title='Turn on to Mount Device when Array is Started'><input type='checkbox' class='automount' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td>";
	$out[] = "<td title='Turn on to Share Device with SMB and/or NFS'><input type='checkbox' class='toggle_share' info='".htmlentities(json_encode($partition))."' ".(($partition['shared']) ? 'checked':'')."></td>";
	$out[] = "<td><a title='View Device Script Log' href='/Main/ScriptLog?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><i class='fa fa-align-left'></i></a></td>";
	$out[] = "<td><a title='Edit Device Script' href='/Main/EditScript?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><i class=".( file_exists(get_config($partition['serial'],"command.1")) ? "'fa fa-code'":"'fa fa-minus-square-o'" )."'></i></a></td>";
	$out[] = "</tr>";
	return $out;
}

function make_mount_button($device) {
	global $paths, $Preclear;

	$button = "<span style='width:auto;text-align:right;'><button type='button' device='{$device['device']}' class='array' context='%s' role='%s' %s><i class='%s'></i>	%s</button></span>";
	if (isset($device['partitions'])) {
		$mounted = isset($device['mounted']) ? $device['mounted'] : in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $device['partitions']));
		$disable = count(array_filter($device['partitions'], function($p){ if (! empty($p['fstype']) && $p['fstype'] != "precleared") return TRUE;})) ? "" : "disabled";
		$format	 = (isset($device['partitions']) && ! count($device['partitions'])) || $device['partitions'][0]['fstype'] == "precleared" ? true : false;
		$context = "disk";
	} else {
		$mounted =	(isset($device["mounted"])) ? $device["mounted"] : is_mounted($device['device']);
		$disable = (! empty($device['fstype']) && $device['fstype'] != "precleared") ? "" : "disabled";
		$format	 = ((isset($device['fstype']) && empty($device['fstype'])) || $device['fstype'] == "precleared") ? true : false;
		$context = "partition";
	}
	$is_mounting	= array_values(preg_grep("@/mounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_mounting	= (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
	$is_unmounting	= array_values(preg_grep("@/unmounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_unmounting	= (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
	$is_formatting	= array_values(preg_grep("@/formatting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_formatting	= (time() - filemtime($is_formatting) < 300) ? TRUE : FALSE;

	$dev			= basename($device['device']);
	$preclearing	= $Preclear ? $Preclear->isRunning(basename($device['device'])) : false;
	if ($device['size'] == 0) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-erase', 'Insert');
	} elseif ($format) {
		$disable = (file_exists("/usr/sbin/parted") && get_config("Config", "destructive_mode") == "enabled") ? "" : "disabled";
		$disable = $preclearing ? "disabled" : $disable;
		$button = sprintf($button, $context, 'format', $disable, 'fa fa-erase', 'Format');
	} elseif ($is_mounting) {
		$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Mounting...');
	} elseif ($is_unmounting) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Unmounting...');
	} elseif ($is_formatting) {
		$button = sprintf($button, $context, 'format', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Formatting...');
	} elseif ($mounted) {
		if ($device['fstype'] == "crypto_LUKS") {
			$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-export', 'Unmount');
		} else {
			$cmd = get_config($device['serial'],"command.1");
			$running = ($cmd != "") ? (shell_exec("/usr/bin/ps -ef | grep '".basename($cmd)."' | grep -v 'grep'") != "" ? TRUE : FALSE) : FALSE;
			if ($running) {
				$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-export', 'Running...');
			} else {
				$button = sprintf($button, $context, 'umount', '', 'fa fa-export', 'Unmount');
			}
		}
	} else {
		$disable = (is_pass_through($device['serial']) || $preclearing) ? "disabled" : $disable;
		$button = sprintf($button, $context, 'mount', $disable, 'fa fa-import', 'Mount');
	}
	return $button;
}


switch ($_POST['action']) {
	case 'get_content':
		unassigned_log("Starting page render [get_content]", "DEBUG");
		$time		 = -microtime(true); 
		$disks = get_all_disks_info();

		echo "<table class='disk_status wide usb_disks'><thead><tr><td>Device</td><td>Identification</td><td></td><td>Temp</td><td>FS</td><td>Size</td><td>Open files</td><td>Used</td><td>Free</td><td>Pass Through</td><td>Read Only</td><td>Auto Mount</td><td>Share&nbsp;&nbsp;</td><td>Log</td><td>Script</td></tr></thead>";
		echo "<tbody>";
		$odd="odd";
		if ( count($disks) ) {
			foreach ($disks as $disk) {
				$mounted		= isset($disk['mounted']) ? $disk['mounted'] : in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $disk['partitions']));
				$disk_name		= basename($disk['device']);
				$p				= (count($disk['partitions']) > 0) ? render_partition($disk, $disk['partitions'][0], TRUE) : FALSE;
				$preclearing	= $Preclear ? $Preclear->isRunning($disk_name) : false;
				$is_precleared	= ($disk['partitions'][0]['fstype'] == "precleared") ? true : false;
				$flash			= ($disk['partitions'][0]['fstype'] == "vfat") ? true : false;
				if ( ($mounted || is_file($disk['partitions'][0]['command']) || $preclearing) && ! is_pass_through($disk['serial']) ) {
					$disk_running	= array_key_exists("running", $disk) ? $disk["running"] : is_disk_running($disk['device']);
					$disk['temperature'] = $disk['temperature'] ? $disk['temperature'] : get_temp(substr($disk['device'],0,10), $disk_running);
				}
				$temp = my_temp($disk['temperature']);

				$mbutton = make_mount_button($disk);

				$preclear_link = ($disk['size'] !== 0 && ! $flash	&& ! $mounted && $Preclear && ! $preclearing) ? "&nbsp;&nbsp;".$Preclear->Link($disk_name, "icon") : "";

				if ( $p	&& ! ($is_precleared || $preclearing) )
				{
					$add_toggle = TRUE;
					$hdd_serial ="<span title='Click to view partitions/mount points' class='exec toggle-hdd' hdd='{$disk_name}'>
									<i class='fa fa-database hdd'></i>
									<i class='fa fa-plus-circle fa-append'></i></span>";
				}
				else
				{
					$add_toggle = FALSE;
					$hdd_serial = "<span class='toggle-hdd' hdd='{$disk_name}'>
									<i class='fa fa-database hdd'></i></span>";
				}

				$hdd_serial .= "<a href=\"#\" title=\"Disk Log Information\" onclick=\"openBox('/webGui/scripts/disk_log&amp;arg1={$disk_name}','Disk Log Information',600,900,false);return false\"><i class='fa fa-hdd-o icon'></i></a>
								{$disk['serial']}
								{$preclear_link}
								<span id='preclear_{$disk['serial_short']}' style='display:block;'></span>";

				echo "<tr class='$odd toggle-disk'>";
				if ( $flash || $preclearing ) {
					echo "<td><img src='/plugins/{$plugin}/images/green-blink.png'> {$disk_name}</td>";
				} else {
					echo "<td title='SMART Attributes on {$disk_name}'><img src='/plugins/{$plugin}/images/".($disk_running ? "green-on.png":"green-blink.png" )."'>";
					echo "<a href='/Main/New?name={$disk_name}'> {$disk_name}</a></td>";
				}
				echo "<td>{$hdd_serial}</td>";
				echo "<td class='mount'>{$mbutton}</td>";
				echo "<td>{$temp}</td>";
				echo ($p)?$p[5]:"<td>-</td>";
				echo "<td>".my_scale($disk['size'],$unit)." {$unit}</td>";
				echo ($p)?$p[7]:"<td>-</td>";
				echo ($p)?$p[8]:"<td>-</td><td>-</td>";
				echo ($p)?$p[9]:"<td>-</td>";
				echo ($p)?$p[10]:"<td>-</td>";
				echo ($p)?$p[11]:"<td>-</td>";
				echo ($p)?$p[12]:"<td>-</td>";
				echo ($p)?$p[13]:"<td>-</td>";
				echo ($p)?$p[14]:"<td>-</td>";
				echo "</tr>";
				if ($add_toggle)
				{
					echo "<tr>";
					foreach ($disk['partitions'] as $partition) {
						foreach (render_partition($disk, $partition) as $l)
						{
							echo $l;
						}
					}
					echo "</tr>";
				} 
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		} else {
			echo "<tr><td colspan='12' style='text-align:center;'>No unassigned disks available.</td></tr>";
		}
		echo "</tbody></table>";

		# SAMBA Mounts
		echo "<div id='smb_tab' class='show-complete'>";

		echo "<div id='title'><span class='left'><img src='/plugins/{$plugin}/icons/smbsettings.png' class='icon'>SMB Shares &nbsp;| &nbsp;<img src='/plugins/{$plugin}/icons/nfs.png' class='icon'>NFS Shares &nbsp;| &nbsp;<img src='/plugins/{$plugin}/icons/iso.png' class='icon' style='width:16px;'>ISO File Shares</span></div>";
		echo "<table class='disk_status wide samba_mounts'><thead><tr><td>Device</td><td>Source</td><td>Mount point</td><td></td><td>Remove</td><td>Size</td><td>Used</td><td>Free</td><td>Auto mount</td><td>Log</td><td>Script</td></tr></thead>";

		echo "<tbody>";
		# SAMBA Mounts
		$ds1 = time();
		$samba_mounts = get_samba_mounts();
		unassigned_log("get_samba_mounts: ".(time() - $ds1)."s","DEBUG");
		$odd="odd";
		if (count($samba_mounts)) {
			foreach ($samba_mounts as $mount)
			{
				$is_alive = $mount['is_alive'];
				$mounted = is_mounted($mount['device']);
				echo "<tr class='$odd'>";
				$protocol = $mount['protocol'] == "NFS" ? "nfs" : "smb";
				printf( "<td><img src='/plugins/{$plugin}/images/%s'>%s</td>", ( $is_alive ? "green-on.png":"green-blink.png" ), $protocol);
				echo "<td><div><i class='fa fa-globe hdd'></i><span style='margin:3px;'></span>{$mount['name']}</div></td>";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					echo "<td><i class='fa fa-download hdd'></i><span style='margin:2px;'></span><a title='Browse Remote SMB/NFS Share' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					echo "<td>
						<form title='Click to change Remote SMB/NFS Mount Point - Press Enter to save' method='POST' action='/plugins/{$plugin}/UnassignedDevices.php' target='progressFrame' class='inline'>
						<i class='fa fa-download hdd'></i>
						<span style='margin:1px;' class='text exec'><a>{$mount_point}</a></span>
						<input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden />
						<input type='hidden' name='action' value='change_samba_mountpoint'/>
						<input type='hidden' name='device' value='{$mount['name']}'/>
						<input type='hidden' name='csrf_token' value='{$csrf_token}'/>
						</form>
						</td>";
				}

				$disabled = $is_alive ? "enabled":"disabled";
				$cmd = get_samba_config($mount['device'],"command");
				$running = ($cmd != "") ? (shell_exec("/usr/bin/ps -ef | grep '".basename($cmd)."' | grep -v 'grep'") != "" ? TRUE : FALSE) : FALSE;
				if ($running) {
					echo "<td><span style='width:auto;text-align:right;'><button type='button' style='padding:2px 7px 2px 7px;<i class='fa fa-export' disabled></i>Running...</button></span></td>";
				} else {
					echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' device ='{$mount['device']}' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'umount','{$mount['device']}');\"><i class='fa fa-export'></i>Unmount</button>" : "<button type='button' device ='{$mount['device']}' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='fa fa-import'></i>Mount</button>")."</span></td>";
				}
				echo $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount['name']}\");' title='Remove Remote SMB/NFS Share'> <i class='fa fa-remove hdd'></i></a></td>";
				echo "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
				echo render_used_and_free($mount, $mounted);
				echo "<td title='Turn on to Mount Remote SMB/NFS Share when Array is Started'><input type='checkbox' class='samba_automount' device='{$mount['name']}' ".(($mount['automount']) ? 'checked':'')."></td>";
				echo "<td><a title='View Remote SMB/NFS Script Log' href='/Main/ScriptLog?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class='fa fa-align-left'></i></a></td>";
				echo "<td><a title='Edit Remote SMB/NFS Script' href='/Main/EditScript?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class=".( file_exists(get_samba_config($mount['device'],"command")) ? "'fa fa-code'":"'fa fa-minus-square-o'" )."'></i></a></td>";
				echo "</tr>";
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		}

		# ISO file Mounts
		$iso_mounts = get_iso_mounts();
		if (count($iso_mounts)) {
			foreach ($iso_mounts as $mount) {
				$mounted = is_mounted($mount['device']);
				$is_alive = is_file($mount['file']);
				echo "<tr class='$odd'>";
				printf( "<td><img src='/plugins/{$plugin}/images/%s'>iso</td>", ( $is_alive ? "green-on.png":"green-blink.png" ));
				$devname = basename($mount['device']);
				echo "<td><i class='fa fa-bullseye'></i><span style='margin:0px;'></span>{$mount['device']}</td>";
				$mount_point = basename($mount['mountpoint']);
				if ($mounted) {
					echo "<td><i class='fa fa-download'></i><span style='margin:0px;'></span><a title='Browse ISO File Share' href='/Main/Browse?dir={$mount['mountpoint']}'>{$mount_point}</a></td>";
				} else {
					echo "<td>
						<form title='Click to change ISO File Mount Point - Press Enter to save' method='POST' action='/plugins/{$plugin}/UnassignedDevices.php' target='progressFrame' class='inline'>
						<i class='fa fa-download hdd'></i>
						<span style='margin:0px;' class='text exec'><a>{$mount_point}</a></span>
						<input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden />
						<input type='hidden' name='action' value='change_iso_mountpoint'/>
						<input type='hidden' name='device' value='{$mount['device']}'/>
						<input type='hidden' name='csrf_token' value='{$csrf_token}'/>
						</form>
						</td>";
				}
				$disabled = $is_alive ? "enabled":"disabled";
				$cmd = get_iso_config($mount['device'],"command");
				$running = ($cmd != "") ? (shell_exec("/usr/bin/ps -ef | grep '".basename($cmd)."' | grep -v 'grep'") != "" ? TRUE : FALSE) : FALSE;
				if ($running) {
					echo "<td><span style='width:auto;text-align:right;'><button type='button' style='padding:2px 7px 2px 7px;<i class='fa fa-export' disabled></i>Running...</button></span></td>";
				} else {
					echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' device='{$mount['device']}' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'umount','{$mount['device']}');\"><i class='fa fa-export'></i>Unmount</button>" : "<button type='button' device='{$mount['device']}' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'mount','{$mount['device']}');\" {$disabled}><i class='fa fa-import'></i>Mount</button>")."</span></td>";
				}
				echo $mounted ? "<td><i class='fa fa-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_iso_config(\"{$mount['device']}\");' title='Remove ISO File Share'> <i class='fa fa-remove hdd'></i></a></td>";
				echo "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
				echo render_used_and_free($mount, $mounted);
				echo "<td title='Turn on to Mount ISO File when Array is Started'><input type='checkbox' class='iso_automount' device='{$mount['device']}' ".(($mount['automount']) ? 'checked':'')."></td>";
				echo "<td><a title='View ISO File Script Log' href='/Main/ScriptLog?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class='fa fa-align-left'></i></a></td>";
				echo "<td><a title='Edit ISO File Script' href='/Main/EditScript?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><i class=".( file_exists(get_iso_config($mount['device'],"command")) ? "'fa fa-code'":"'fa fa-minus-square-o'" )."'></i></a></td>";
				echo "</tr>";
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		}
		if (! count($samba_mounts) && ! count($iso_mounts)) {
			echo "<tr><td colspan='12' style='text-align:center;'>No Remote SMB/NFS or ISO File Shares configured.</td></tr>";
		}
		echo "</tbody></table><button type='button' onclick='add_samba_share();'>Add Remote SMB/NFS Share</button>";
		echo "<button type='button' onclick='add_iso_share();'>Add ISO File Share</button></div>";

		$config_file = $GLOBALS["paths"]["config_file"];
		$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
		$disks_serials = array();
		foreach ($disks as $disk) $disks_serials[] = $disk['partitions'][0]['serial'];
		$ct = "";
		foreach ($config as $serial => $value) {
			if($serial == "Config") continue;
			if (! preg_grep("#{$serial}#", $disks_serials)){
				$mountpoint	= basename(get_config($serial, "mountpoint.1"));
				$ct .= "<tr><td><img src='/plugins/{$plugin}/images/green-blink.png'> missing</td><td>$serial"."   ($mountpoint)</td>";
				$ct .= "<td title='Turn on to Mount Device Read only'><input type='checkbox' class='read_only' serial='{$serial}' ".( is_read_only($serial) ? 'checked':'')."></td>";
				$ct .= "<td title='Turn on to Mount Device when Array is Started'><input type='checkbox' class='automount' serial='{$serial}' ".( is_automount($serial) ? 'checked':'' )."></td>";
				$ct .= "<td><a title='Edit Device Script' href='/Main/EditScript?s=".urlencode($serial)."&l=".urlencode(basename($mountpoint))."&p=".urlencode("1")."'><i class=".( file_exists(get_config($serial,"command.1")) ? "'fa fa-code'":"'fa fa-minus-square-o'" )."'></i></a></td>";
				$ct .= "<td title='Remove Device configuration' colspan='7'><a style='cursor:pointer;' onclick='remove_disk_config(\"{$serial}\")'>Remove</a></td></tr>";
			}
		}
		if (strlen($ct)) {
			echo "<div id='smb_tab' class='show-complete'><div id='title'><span class='left'><img src='/plugins/{$plugin}/icons/historical.png' class='icon'>Historical Devices</span></div>";
			echo "<table class='disk_status wide usb_absent'><thead><tr><td>Device</td><td>Serial Number (Mountpoint)</td><td>Read Only</td><td>Auto mount</td><td>Script</td><td colspan='7'>Config</td></tr></thead><tbody>{$ct}</tbody></table></div>";
		}
		unassigned_log("Total render time: ".($time + microtime(true))."s", "DEBUG");
		break;

	case 'detect':
		echo json_encode(array("reload" => is_file($paths['reload']), "diskinfo" => 0));
		break;

	case 'remove_hook':
		@unlink($paths['reload']);
		break;
		
	case 'get_content_json':
		unassigned_log("Starting json reply action [get_content_json]", "DEBUG");
		$time		 = -microtime(true); 
		$disks = get_all_disks_info();
		echo json_encode($disks);
		unassigned_log("Total render time: ".($time + microtime(true))."s", "DEBUG");
		break;

	/*	CONFIG	*/
	case 'automount':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'automount' => toggle_automount($serial, $status) ));
		break;

	case 'get_command':
		$serial = urldecode(($_POST['serial']));
		$part	 = urldecode(($_POST['part']));
		echo json_encode(array( 'command' => get_config($serial, "command.{$part}"), "background" =>	get_config($serial, "command_bg.{$part}") ));
		break;

	case 'set_command':
		$serial = urldecode(($_POST['serial']));
		$part = urldecode(($_POST['part']));
		$cmd = urldecode(($_POST['command']));
		set_config($serial, "command_bg.{$part}", urldecode($_POST['background']));
		echo json_encode(array( 'result' => set_config($serial, "command.{$part}", $cmd)));
		break;

	case 'remove_config':
		$serial = urldecode(($_POST['serial']));
		echo json_encode(array( 'result' => remove_config_disk($serial)));
		break;

	case 'toggle_share':
		$info = json_decode(html_entity_decode($_POST['info']), true);
		$status = urldecode(($_POST['status']));
		$result = toggle_share($info['serial'], $info['part'],$status);
		echo json_encode(array( 'result' => $result));
		if ($result && strlen($info['target'])) {
			add_smb_share($info['mountpoint'], $info['label']);
			add_nfs_share($info['mountpoint']);
		} else {
			rm_smb_share($info['mountpoint'], $info['label']);
			rm_nfs_share($info['mountpoint']);
		}
		break;

	case 'read_only':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'read_only' => toggle_read_only($serial, $status) ));
		break;

	case 'pass_through':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'pass_through' => toggle_pass_through($serial, $status) ));
		break;

	/*	DISK	*/
	case 'mount':
		$device = urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned mount '$device' &>/dev/null", $out, $return);
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'umount':
		$device = urldecode($_POST['device']);
		exec("plugins/{$plugin}/scripts/rc.unassigned umount '$device' &>/dev/null", $out, $return);
		echo json_encode(["status" => $return ? false : true ]);
		break;

	case 'rescan_disks':
		exec("/sbin/udevadm trigger --action=change 2>&1");
		break;

	case 'format_disk':
		$device = urldecode($_POST['device']);
		$fs = urldecode($_POST['fs']);
		@touch(sprintf($paths['formatting'],basename($device)));
		echo json_encode(array( 'status' => format_disk($device, $fs)));
		@unlink(sprintf($paths['formatting'],basename($device)));
		break;

	/*	SAMBA	*/
	case 'list_samba_hosts':
		$workgroup = urldecode($_POST['workgroup']);
		$network = $_POST['network'];
		$names = [];
		foreach ($network as $iface)
		{
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			exec("plugins/{$plugin}/scripts/port_ping.sh {$ip} {$netmask} 445", $hosts);
			foreach ($hosts as $host) {
				$name=trim(shell_exec("/usr/bin/nmblookup -A '$host' 2>/dev/null | grep -v 'GROUP' | grep -Po '[^<]*(?=<00>)' | head -n 1"));
				$names[]= $name ? $name : $host;
			}
			natsort($names);
		}
		echo implode(PHP_EOL, $names);
		// exec("/usr/bin/nmblookup --option='disable netbios'='No' '$workgroup' | awk '{print $1}'", $output);
		// echo timed_exec(10, "/usr/bin/smbtree --servers --no-pass | grep -v -P '^\w+' | tr -d '\\' | awk '{print $1}' | sort");
		break;

	case 'list_samba_shares':
		$ip = urldecode($_POST['IP']);
		$user = isset($_POST['USER']) ? $_POST['USER'] : NULL;
		$pass = isset($_POST['PASS']) ? $_POST['PASS'] : NULL;
		$domain = isset($_POST['DOMAIN']) ? $_POST['DOMAIN'] : NULL;
		file_put_contents("{$paths['authentication']}", "username=".$user."\n");
		file_put_contents("{$paths['authentication']}", "password=".$pass."\n", FILE_APPEND);
		file_put_contents("{$paths['authentication']}", "domain=".$domain."\n", FILE_APPEND);
		$list = shell_exec("/usr/bin/smbclient -t2 -g -L '$ip' --authentication-file='{$paths['authentication']}' 2>/dev/null | /usr/bin/awk -F'|' '/Disk/{print $2}' | grep -v '\\$' | sort");
		@unlink("{$paths['authentication']}");
		echo $list;
		break;

	/*	NFS	*/
	case 'list_nfs_hosts':
		$network = $_POST['network'];
		foreach ($network as $iface)
		{
			$ip = $iface['ip'];
			$netmask = $iface['netmask'];
			echo shell_exec("/usr/bin/timeout -s 13 5 plugins/{$plugin}/scripts/port_ping.sh {$ip} {$netmask} 2049 2>/dev/null | sort -n -t . -k 1,1 -k 2,2 -k 3,3 -k 4,4");
		}
		break;

	case 'list_nfs_shares':
		$ip = urldecode($_POST['IP']);
		$rc = timed_exec(10, "/usr/sbin/showmount --no-headers -e '{$ip}' 2>/dev/null | rev | cut -d' ' -f2- | rev | sort");
		echo $rc ? $rc : " ";
		break;

	/* SMB SHARES */
	case 'add_samba_share':
		$ip = urldecode($_POST['IP']);
		$ip = implode("",explode("\\", $ip));
		$ip = stripslashes(trim($ip));
		$protocol = urldecode($_POST['PROTOCOL']);
		$user = isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$domain = isset($_POST['DOMAIN']) ? urldecode($_POST['DOMAIN']) : "";
		$pass = isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$path = isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";
		$path = implode("",explode("\\", $path));
		$path = stripslashes(trim($path));
		$share = basename($path);
		if ($share) {
			$device = ($protocol == "NFS") ? "{$ip}:{$path}" : "//".strtoupper($ip)."/{$share}";
			if (strpos($path, "$") === FALSE) {
				set_samba_config("{$device}", "protocol", $protocol);
				set_samba_config("{$device}", "ip", (is_ip($ip) ? $ip : strtoupper($ip)));
				set_samba_config("{$device}", "path", $path);
				set_samba_config("{$device}", "user", $user);
				set_samba_config("{$device}", "domain", $domain);
				set_samba_config("{$device}", "pass", encrypt_data($pass));
				set_samba_config("{$device}", "share", safe_name($share));
			} else {
				unassigned_log("Share '{$device}' contains a '$' character.	It cannot be mounted.");
			}
		}
		break;

	case 'remove_samba_config':
		$device = urldecode(($_POST['device']));
		remove_config_samba($device);
		break;

	case 'samba_automount':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'automount' => toggle_samba_automount($device, $status) ));
		break;

	case 'set_samba_command':
		$device = urldecode(($_POST['device']));
		$cmd = urldecode(($_POST['command']));
		set_samba_config($device, "command_bg", urldecode($_POST['background'])) ;
		echo json_encode(array( 'result' => set_samba_config($device, "command", $cmd)));
		break;

	/* ISO FILE SHARES */
	case 'add_iso_share':
		$file = isset($_POST['ISO_FILE']) ? urldecode($_POST['ISO_FILE']) : "";
		$file = implode("",explode("\\", $file));
		$file = stripslashes(trim($file));
		if (is_file($file)) {
			$info = pathinfo($file);
			$share = $info['filename'];
			set_iso_config("{$file}", "file", $file);
			set_iso_config("{$file}", "share", $share);
		} else {
			unassigned_log("ISO File '{$file}' not found.");
		}
		break;

	case 'remove_iso_config':
		$device = urldecode(($_POST['device']));
		remove_config_iso($device);
		break;

	case 'iso_automount':
		$device = urldecode(($_POST['device']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'automount' => toggle_iso_automount($device, $status) ));
		break;

	case 'set_iso_command':
		$device = urldecode(($_POST['device']));
		$cmd = urldecode(($_POST['command']));
		set_iso_config($device, "command_bg", urldecode($_POST['background'])) ;
		echo json_encode(array( 'result' => set_iso_config($device, "command", $cmd)));
		break;

	/*	MISC */
	case 'rm_partition':
		$device = urldecode($_POST['device']);
		$partition = urldecode($_POST['partition']);
		remove_partition($device, $partition );
		break;

	case 'change_mountpoint':
		$serial = urldecode($_POST['serial']);
		$partition = urldecode($_POST['partition']);
		$mountpoint = safe_name(basename(urldecode($_POST['mountpoint'])));
		if ($mountpoint != "") {
			$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
			set_config($serial, "mountpoint.{$partition}", $mountpoint);
		}
		require_once("update.htm");
		break;

		case 'change_samba_mountpoint':
			$device = urldecode($_POST['device']);
			$mountpoint = safe_name(basename(urldecode($_POST['mountpoint'])));
			if ($mountpoint != "") {
				$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
				set_samba_config($device, "mountpoint", $mountpoint);
			}
			require_once("update.htm");
			break;

		case 'change_iso_mountpoint':
			$device = urldecode($_POST['device']);
			$mountpoint = safe_name(basename(urldecode($_POST['mountpoint'])));
			if ($mountpoint != "") {
				$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
				set_iso_config($device, "mountpoint", $mountpoint);
			}
			require_once("update.htm");
			break;
	}
?>
