<?PHP
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>

<?PHP
$plugin = "unassigned.devices";
require_once("plugins/${plugin}/include/lib.php");
require_once("webGui/include/Helpers.php");

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

function render_used_and_free($partition) {
	global $display;

	$o = "";
	if (strlen($partition['target'])) {
		if (!$display['text']) {
			$o .= "<td>".my_scale($partition['used'], $unit)." $unit</td>";
			$o .= "<td>".my_scale($partition['avail'], $unit)." $unit</td>";
		} else {
			$free = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
			$used = 100-$free;
			extract(parse_ini_file('/etc/unraid-version'));
			if (version_compare($version, '6.1.7', '>=')) {
				$o .= "<td><div class='usage-disk'><span style='margin:0;width:{$used}%' class='".usage_color($display,$used,false)."'><span>".my_scale($partition['used'], $unit)." $unit</span></span></div></td>";
				$o .= "<td><div class='usage-disk'><span style='margin:0;width:{$free}%' class='".usage_color($display,$free,true)."'><span>".my_scale($partition['avail'], $unit)." $unit</span></span></div></td>";
			} else {
				$o .= "<td><div class='usage-disk'><span style='margin:0;width:{$used}%' class='".usage_color($used,false)."'><span>".my_scale($partition['used'], $unit)." $unit</span></span></div></td>";
				$o .= "<td><div class='usage-disk'><span style='margin:0;width:{$free}%' class='".usage_color($free,true)."'><span>".my_scale($partition['avail'], $unit)." $unit</span></span></div></td>";
			}
		}
	} else {
		$o .= "<td>-</td><td>-</td>";
	}
	return $o;
}

function render_partition($disk, $partition) {
	global $plugin, $paths, $echo;

	if (! isset($partition['device'])) return array();
	$out = array();
	$mounted = is_mounted($partition['device']);
	if ($mounted && is_file(get_config($disk[serial],"command.{$partition[part]}"))) {
		$fscheck = "<a title='Execute Script as udev simulating a device being installed.' class='exec' onclick='openWindow_fsck(\"/plugins/${plugin}/include/script.php?device={$partition[device]}&owner=udev\",\"Execute Script\",600,900);'><i class='glyphicon glyphicon-flash partition'></i>{$partition[part]}</a>";
	} elseif ( (! $mounted &&  $partition['fstype'] != 'btrfs') ) {
		$fscheck = "<a title='File System Check.' class='exec' onclick='openWindow_fsck(\"/plugins/${plugin}/include/fsck.php?device={$partition[device]}&fs={$partition[fstype]}&type=ro\",\"Check filesystem\",600,900);'><i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}</a>";
	} else {
		$fscheck = "<i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}";
	}

	$rm_partition = (get_config("Config", "destructive_mode") == "enabled") ? "<span title='Remove Partition.' class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$disk[device]}\",\"{$partition[part]}\");'><i class='glyphicon glyphicon-remove hdd'></i></span>" : "";
	$mpoint = "<div>{$fscheck}<i class='glyphicon glyphicon-arrow-right'></i>";
	if ($mounted) {
		$mpoint .= "<a title='Browse Share.' href='/Main/Browse?dir={$partition[mountpoint]}'>{$partition[mountpoint]}</a></div>";
	} else {
		$mount_point = basename($partition[mountpoint]);
		$mpoint .= "<form title='Click to Change Mount Point.' method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_mountpoint&serial={$partition[serial]}&partition={$partition[part]}' target='progressFrame' style='display:inline;margin:0;padding:0;'><span class='text exec'><a>{$partition[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden /></form> {$rm_partition}</div>";
	}
	$mbutton = make_mount_button($partition);
  
	$out[] = "<tr class='$outdd toggle-parts toggle-".basename($disk['device'])."' style='__SHOW__' >";
	$out[] = "<td></td>";
	$out[] = "<td>{$mpoint}</td>";
	$out[] = "<td class='mount'>{$mbutton}</td>";
	$out[] = "<td>-</td>";
	$out[] = "<td >".$partition['fstype']."</td>";
	$out[] = "<td>".my_scale($partition['size'], $unit)." $unit</td>";
	$out[] = "<td>".(strlen($partition['target']) ? shell_exec("/usr/bin/lsof '${partition[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td>";
	$out[] = render_used_and_free($partition);
	$out[] = "<td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='automount' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td>";
	$out[] = "<td title='Turn on to Share Device with SMB and/or NFS.'><input type='checkbox' class='toggle_share' info='".htmlentities(json_encode($partition))."' ".(($partition['shared']) ? 'checked':'')."></td>";
	$out[] = "<td><a title='View Log.' href='/Main/ViewLog?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><img src='/plugins/${plugin}/icons/view_log.png' style='cursor:pointer;width:16px;'></a></td>";
	$out[] = "<td><a title='Edit Device Script.' href='/Main/EditScript?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><img src='/plugins/${plugin}/icons/edit_script.png' style='cursor:pointer;width:16px;".( (get_config($partition['serial'],"command_bg.{$partition[part]}") == "true") ? "":"opacity: 0.4;" )."'></a></td>";
	$out[] = "<tr>";
	return $out;
}

function make_mount_button($device) {
	global $paths, $Preclear;
	$button = "<span style='width:auto;text-align:right;'><button type='button' device='{$device[device]}' class='array' context='%s' role='%s' %s><i class='%s'></i>  %s</button></span>";
	if (isset($device['partitions'])) {
		$mounted = in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $device['partitions']));
		$disable = count(array_filter($device['partitions'], function($p){ if (! empty($p['fstype']) && $p['fstype'] != "precleared") return TRUE;})) ? "" : "disabled";
		$format = (isset($device['partitions']) && ! count($device['partitions'])) || $device['partitions'][0]['fstype'] == "precleared" ? true : false;
		$context = "disk";
	} else {
		$mounted = is_mounted($device['device']);
		$disable = (! empty($device['fstype']) && $device['fstype'] != "precleared") ? "" : "disabled";
		$format = ((isset($device['fstype']) && empty($device['fstype'])) || $device['fstype'] == "precleared") ? true : false;
		$context = "partition";
	}
	$is_mounting   = array_values(preg_grep("@/mounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_mounting   = (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
	$is_unmounting = array_values(preg_grep("@/unmounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
	$is_unmounting = (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
	$dev           = basename($device['device']);
	$preclearing   = $Preclear ? $Preclear->isRunning(basename($device['device'])) : false;
	if ($device['size'] == 0) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'glyphicon glyphicon-erase', 'Insert');
	} elseif ($format) {
		$disable = get_config("Config", "destructive_mode") == "enabled" ? "" : "disabled";
		$disable = $preclearing ? "disabled" : $disable;
		$button = sprintf($button, $context, 'format', $disable, 'glyphicon glyphicon-erase', 'Format');
	} elseif ($is_mounting) {
		$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Mounting...');
	} elseif ($is_unmounting) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Unmounting...');
	} elseif ($mounted) {
		$button = sprintf($button, $context, 'umount', '', 'glyphicon glyphicon-export', 'Unmount');
	} else {
		$disable = $preclearing ? "disabled" : $disable;
		$button = sprintf($button, $context, 'mount', $disable, 'glyphicon glyphicon-import', 'Mount');
	}
	return $button;
}

switch ($_POST['action']) {
	case 'get_content':
		$disks = get_all_disks_info();
		echo "<table class='disk_status wide usb_disks'><thead><tr><td>Device</td><td>Identification</td><td></td><td>Temp</td><td>FS</td><td>Size</td><td>Open files</td><td>Used</td><td>Free</td><td>Auto mount</td><td>Share</td><td>Log</td><td>Script</td></tr></thead>";
		echo "<tbody>";
		if ( count($disks) ) {
			$odd="odd";
			foreach ($disks as $disk) {
				$mounted       = in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $disk['partitions']));
				$disk_name     = basename($disk['device']);
				$p             = (count($disk['partitions']) > 0) ? render_partition($disk, $disk['partitions'][0]) : FALSE;
				$preclearing   = $Preclear ? $Preclear->isRunning($disk_name) : false;
				$is_precleared = ($disk['partitions'][0]['fstype'] == "precleared") ? true : false;
				$flash         = ($disk['partitions'][0]['fstype'] == "vfat" || $disk['partitions'][0]['fstype'] == "exfat") ? true : false;
				if ($mounted || is_file($disk['partitions'][0]['command']) || $preclearing) {
					$disk['temperature'] = get_temp($disk['device']);
				}
				$temp = my_temp($disk['temperature']);

				$mbutton = make_mount_button($disk);

				$preclear_link = ($disk['size'] !== 0 && ! $flash  && ! $mounted && $Preclear && ! $preclearing) ? "&nbsp;&nbsp;".$Preclear->Link($disk_name, "icon") : "";

				if ( $p  && ! ($is_precleared || $preclearing) )
				{
					$add_toggle = TRUE;
					$hdd_serial = "<span title='Click to view partitions/mount points.' class='exec toggle-hdd' hdd='{$disk_name}'>
												 	<i class='glyphicon glyphicon-hdd hdd'></i>
													<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>
												</span>
												{$disk[serial]}
												{$preclear_link}
												<div id='preclear_{$disk_name}'></div>";
				}
				else
				{
					$add_toggle = FALSE;
					$hdd_serial = "<span class='toggle-hdd' hdd='{$disk_name}'>
												 	<i class='glyphicon glyphicon-hdd hdd'></i>
												 </span>
												 <span style='margin:4px;'></span>
												 {$disk[serial]}
												 {$preclear_link}
												 <div id='preclear_{$disk_name}'></div>";
				}

				echo "<tr class='{$odd} toggle-disk'>";
				if ( $flash || (!is_file($disk['partitions'][0]['command']) && ! $mounted && ! $preclearing) ) {
					echo "<td><img src='/webGui/images/green-blink.png'> {$disk_name}</td>";
				} else {
					echo "<td title='SMART Attributes on {$disk_name}.'><img src='/webGui/images/".(is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" )."'>";
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
				echo "</tr>";
				if ($add_toggle)
				{
					foreach ($disk['partitions'] as $partition) {
						foreach (render_partition($disk, $partition) as $l) echo str_replace("__SHOW__", (count($disk['partitions']) >1 ? "display:none;":"display:none;" ), $l );
					}
				}
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		} else {
			echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
		}
		echo "</tbody></table>";

		# SAMBA Mounts
		echo "<div id='smb_tab' class='show-complete'>";
		echo "<div id='title'><span class='left'><img src='/plugins/dynamix/icons/smbsettings.png' class='icon'>SMB Shares &nbsp;| &nbsp;<img src='/webGui/icons/nfs.png' class='icon'>NFS Shares &nbsp;| &nbsp;<img src='/plugins/${plugin}/icons/iso.png' class='icon' style='width:16px;'>ISO File Shares</span></div>";
		echo "<table class='disk_status wide samba_mounts'><thead><tr><td>Device</td><td>Source</td><td>Mount point</td><td></TD><td>Remove</td><td>Size</td><td>Used</td><td>Free</td><td>Auto mount</td><td>Log</td><td>Script</td></tr></thead>";
	    echo "<tbody>";
		# SAMBA Mounts
		$samba_mounts = get_samba_mounts();
		if (count($samba_mounts)) {
			$odd="odd";
			foreach ($samba_mounts as $mount) {
				$mounted = is_mounted($mount['device']);
				$is_alive = (trim(exec("/bin/ping -c 1 -W 1 {$mount[ip]} >/dev/null 2>&1; echo $?")) == 0 ) ? TRUE : FALSE;
				echo "<tr class='$odd'>";
				$protocol = $mount['protocol'] == "NFS" ? "nfs" : "smb";
				printf( "<td><img src='/webGui/images/%s'>%s</td>", ( $is_alive ? "green-on.png":"green-blink.png" ), $protocol);
				echo "<td><div><i class='glyphicon glyphicon-globe hdd'></i><span style='margin:4px;'></span>{$mount[device]}</div></td>";
				if ($mounted) {
					echo "<td><i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'><a title='Browse Remote SMB/NFS Share.' href='/Shares/Browse?dir={$mount[mountpoint]}'>{$mount[mountpoint]}</a></td>";
				} else {
					$mount_point = basename($mount[mountpoint]);
					echo "<td><form title='Click to change Remote SMB/NFS Mount Point.' method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_samba_mountpoint&device={$mount[device]}' target='progressFrame' style='display:inline;margin:0;padding:0;'>
					<i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'></span><span class='text exec'><a>{$mount[mountpoint]}</a></span>
					<input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden />
					</form></td>";
				}
				echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'umount','{$mount[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'mount','{$mount[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span></td>";
				echo $mounted ? "<td><i class='glyphicon glyphicon-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount[device]}\");' title='Remove Remote SMB/NFS Share.'> <i class='glyphicon glyphicon-remove hdd'></i></a></td>";
				echo "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
				echo render_used_and_free($mount);
				echo "<td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='samba_automount' device='{$mount[device]}' ".(($mount['automount']) ? 'checked':'')."></td>";
				echo "<td><a title='View SMB/NFS Share Log.' href='/Main/ViewLog?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><img src='/plugins/${plugin}/icons/view_log.png' style='cursor:pointer;width:16px;'></a></td>";
				echo "<td><a title='Edit Remote SMB/NFS Share Script.' href='/Main/EditScript?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><img src='/plugins/${plugin}/icons/edit_script.png' style='cursor:pointer;width:16px;".( (get_samba_config($mount['device'],"command_bg") == "true") ? "":"opacity: 0.4;" )."'></a></td>";
				echo "</tr>";
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		}

		# Iso file Mounts
		$iso_mounts = get_iso_mounts();
		if (count($iso_mounts)) {
			foreach ($iso_mounts as $mount) {
				$mounted = is_mounted($mount['device']);
				$is_alive = is_file($mount['file']);
				echo "<tr class='$odd'>";
				printf( "<td><img src='/webGui/images/%s'>iso</td>", ( $is_alive ? "green-on.png":"green-blink.png" ));
				$devname = $mount['device'];
				if (strlen($devname) > 50) {
					$devname = substr($devname, 0, 10)."<strong>...</strong>".basename($devname);
				}
				echo "<td><div><i class='glyphicon glyphicon-cd hdd'></i><span style='margin:4px;'></span>${devname}</div></td>";
				if ($mounted) {
					echo "<td><i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'><a title='Browse Iso File Share.' href='/Shares/Browse?dir={$mount[mountpoint]}'>{$mount[mountpoint]}</a></td>";
				} else {
					$mount_point = basename($mount[mountpoint]);
					echo "<td><form title='Click to change Iso File Mount Point.' method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_iso_mountpoint&device={$mount[device]}' target='progressFrame' style='display:inline;margin:0;padding:0;'>
					<i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'></span><span class='text exec'><a>{$mount[mountpoint]}</a></span>
					<input class='input' type='text' name='mountpoint' value='{$mount_point}' hidden />
					</form></td>";
				}
				echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'umount','{$mount[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'mount','{$mount[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span></td>";
				echo $mounted ? "<td><i class='glyphicon glyphicon-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_iso_config(\"{$mount[device]}\");' title='Remove Iso FIle Share.'> <i class='glyphicon glyphicon-remove hdd'></i></a></td>";
				echo "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
				echo render_used_and_free($mount);
				echo "<td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='iso_automount' device='{$mount[device]}' ".(($mount['automount']) ? 'checked':'')."></td>";
				echo "<td><a title='View Iso File Share Log.' href='/Main/ViewLog?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><img src='/plugins/${plugin}/icons/view_log.png' style='cursor:pointer;width:16px;'></a></td>";
				echo "<td><a title='Edit Iso File Share Script.' href='/Main/EditScript?i=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><img src='/plugins/${plugin}/icons/edit_script.png' style='cursor:pointer;width:16px;".( (get_iso_config($mount['device'],"command_bg") == "true") ? "":"opacity: 0.4;" )."'></a></td>";
				echo "</tr>";
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		}
		if (! count($samba_mounts) && ! count($iso_mounts)) {
			echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No Remote SMB/NFS or Iso File Shares configured.</td></tr>";
		}
		echo "</tbody></table><button type='button' onclick='add_samba_share();'>Add Remote SMB/NFS Share</button>";
		echo "<button type='button' onclick='add_iso_share();'>Add Iso File Share</button></div>";

		$config_file = $GLOBALS["paths"]["config_file"];
		$config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
		$disks_serials = array();
		foreach ($disks as $disk) $disks_serials[] = $disk['partitions'][0]['serial'];
		$ct = "";
		foreach ($config as $serial => $value) {
			if($serial == "Config") continue;
			if (! preg_grep("#${serial}#", $disks_serials)){
				$ct .= "<tr><td><img src='/webGui/images/green-blink.png'> missing</td><td>$serial</td><td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='automount' serial='${serial}' ".( is_automount($serial) ? 'checked':'' )."></td><td colspan='7'><a style='cursor:pointer;' onclick='remove_disk_config(\"${serial}\")'>Remove</a></td></tr>";
			}
		}
		if (strlen($ct)) {
			echo "<div id='smb_tab' class='show-complete'><div id='title'><span class='left'><img src='/plugins/{$plugin}/icons/hourglass.png' class='icon'>Historical Devices</span></div>";
			echo "<table class='disk_status wide usb_absent'><thead><tr><td>Device</td><td>Serial Number</td><td>Auto mount</td><td colspan='7'>Remove config</td></tr></thead><tbody>${ct}</tbody></table></div>";
		}

		echo 
		'<script type="text/javascript">
		$(".automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({show_labels: false, checked:checked});});
		$(".automount").change(function(){$.post(URL,{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});

		$(".samba_automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({show_labels: false, checked:checked});});
		$(".samba_automount").change(function(){$.post(URL,{action:"samba_automount",device:$(this).attr("device"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});

		$(".iso_automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({show_labels: false, checked:checked});});
		$(".iso_automount").change(function(){$.post(URL,{action:"iso_automount",device:$(this).attr("device"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});

		$(".toggle_share").each(function(){var checked = $(this).is(":checked");$(this).switchButton({show_labels: false, checked:checked});});
		$(".toggle_share").change(function(){$.post(URL,{action:"toggle_share",info:$(this).attr("info"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.result);},"json");});
		$(".text").click(showInput);$(".input").blur(hideInput);
		$(function(){
			$(".toggle-hdd").click(function(e) {
				$(this).disableSelection();disk = $(this).attr("hdd");el = $(this);
				$(".toggle-"+disk).slideToggle(0,function(){
					if ( $("tr.toggle-"+disk+":first").is(":visible") ){
						el.find(".glyphicon-append").addClass("glyphicon-minus-sign").removeClass("glyphicon-plus-sign");
					} else {
						el.find(".glyphicon-append").removeClass("glyphicon-minus-sign").addClass("glyphicon-plus-sign");
					}
				});
			});
		});

		function rm_preclear(dev) {
			$.post(URL,{action:"rm_preclear",device:dev}).always(function(){usb_disks(tab_usbdisks)});
		}
		$(".show-complete").css("display", $(".complete-switch").is(":checked") ? "block" : "none");
		$("button[role=mount]").add("button[role=umount]").click(function(){disk_op(this, $(this).attr("role"), $(this).attr("device"));});
		$("button[role=format]").click(function(){format_disk(this, $(this).attr("context"), $(this).attr("device"));});
		</script>';
		break;
	case 'detect':
		echo json_encode(array("reload" => is_file($paths['reload'])));
		break;
	case 'remove_hook':
		@unlink($paths['reload']);
		break;

	/*  CONFIG  */
	case 'automount':
		$serial = urldecode(($_POST['serial']));
		$status = urldecode(($_POST['status']));
		echo json_encode(array( 'automount' => toggle_automount($serial, $status) ));
		break;
	case 'get_command':
		$serial = urldecode(($_POST['serial']));
		$part   = urldecode(($_POST['part']));
		echo json_encode(array( 'command' => get_config($serial, "command.{$part}"), "background" =>  get_config($serial, "command_bg.{$part}") ));
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

	/*  DISK  */
	case 'mount':
		$device = urldecode($_POST['device']);
		exec("plugins/${plugin}/scripts/rc.unassigned mount '$device' >/dev/null 2>&1 &");
		break;
	case 'umount':
		$device = urldecode($_POST['device']);
		exec("plugins/${plugin}/scripts/rc.unassigned umount '$device' >/dev/null 2>&1 &");
		break;
	case 'rescan_disks':
		exec("/sbin/udevadm trigger --action=change 2>&1");
		break;
	case 'format_disk':
		$device = urldecode($_POST['device']);
		$fs = urldecode($_POST['fs']);
		echo json_encode(array( 'result' => format_disk($device, $fs)));
		break;
	case 'format_partition':
		$device = urldecode($_POST['device']);
		$fs = urldecode($_POST['fs']);
		echo json_encode(array( 'result' => format_partition($device, $fs)));
		break;

	/*  SAMBA  */
	case 'list_samba_hosts':
		$ip = shell_exec("/usr/bin/nmblookup -M -- - 2>/dev/null | /usr/bin/grep -Pom1 '^\S+'");
		echo shell_exec("/usr/bin/smbclient -g -L '$ip' -U% 2>/dev/null|/usr/bin/awk -F'|' '/Server\|/{print $2}'|sort");
		break;
	case 'list_samba_shares':
		$ip = urldecode($_POST['IP']);
		$user = isset($_POST['USER']) ? urlencode($_POST['USER']) : NULL;
		$pass = isset($_POST['PASS']) ? urlencode($_POST['PASS']) : NULL;
		$login = $user ? ($pass ? "-U '{$user}%{$pass}'" : "-U '{$user}' -N") : "-U%";
		echo shell_exec("/usr/bin/smbclient -g -L '$ip' $login 2>/dev/null|/usr/bin/awk -F'|' '/Disk/{print $2}'|sort");
		break;

	/*  NFS  */
	case 'list_nfs_shares':
		$ip = urldecode($_POST['IP']);
		foreach ( explode(PHP_EOL, shell_exec("/usr/sbin/showmount --no-headers -e '{$ip}' 2>/dev/null|/usr/bin/cut -d'*' -f1|sort")) as $name ) {
			$name = trim($name)."\n";
			echo $name;
		}
		break;

	/* SMB SHARES */
	case 'add_samba_share':
		$ip = urldecode($_POST['IP']);
		$protocol = urldecode($_POST['PROTOCOL']);
		$user = isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$pass = isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$path = isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";
		$share = basename($path);
		$device = ($protocol == "NFS") ? "${ip}:/${path}" : "//${ip}/${share}";
		if (strpos($path, "$") === FALSE) {
			set_samba_config("${device}", "protocol", $protocol);
			set_samba_config("${device}", "ip", $ip);
			set_samba_config("${device}", "path", $path);
			set_samba_config("${device}", "user", $user);
			set_samba_config("${device}", "pass", $pass);
			set_samba_config("${device}", "share", $share);
		} else {
			unassigned_log("Share '{$device}' contains a '$' character.  It cannot be mounted.");
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
		if (is_file($file)) {
			$info = pathinfo($file);
			$share = $info['filename'];
			set_iso_config("${file}", "file", $file);
			set_iso_config("${file}", "share", $share);
		} else {
			unassigned_log("Iso File '${file}' not found.");
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

	/*  MISC */
	case 'rm_partition':
		$device = urldecode($_POST['device']);
		$partition = urldecode($_POST['partition']);
		remove_partition($device, $partition );
		break;
	}

	switch ($_GET['action']) {
		case 'change_mountpoint':
			$serial = urldecode($_GET['serial']);
			$partition = urldecode($_GET['partition']);
			$mountpoint = basename(urldecode($_POST['mountpoint']));
			if ($mountpoint != "") {
				$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
				set_config($serial, "mountpoint.${partition}", $mountpoint);
			}
			require_once("update.htm");
			break;

		case 'change_samba_mountpoint':
			$device = urldecode($_GET['device']);
			$mountpoint = basename(urldecode($_POST['mountpoint']));
			if ($mountpoint != "") {
				$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
				set_samba_config($device, "mountpoint", $mountpoint);
			}
			require_once("update.htm");
			break;

		case 'change_iso_mountpoint':
			$device = urldecode($_GET['device']);
			$mountpoint = basename(urldecode($_POST['mountpoint']));
			if ($mountpoint != "") {
				$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
				set_iso_config($device, "mountpoint", $mountpoint);
			}
			require_once("update.htm");
			break;
	}
?>
