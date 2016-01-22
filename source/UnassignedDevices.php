<?PHP
/* Copyright 2016, Dan Landon.
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
require_once ("webGui/include/Helpers.php");

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
		exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
		return in_array($name, $screens);
	} else {
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
	if ( (! $mounted &&  $partition['fstype'] != 'btrfs') || ($mounted && $partition['fstype'] == 'btrfs') ) {
		$fscheck = "<a title='File System Check.' class='exec' onclick='openWindow_fsck(\"/plugins/${plugin}/include/fsck.php?disk={$partition[device]}&fs={$partition[fstype]}&type=ro\",\"Check filesystem\",600,900);'><i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}</a>";
	} else {
		$fscheck = "<i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}";
	}

	$rm_partition = (get_config("Config", "destructive_mode") == "enabled") ? "<span title='Remove Partition.' class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$disk[device]}\",\"{$partition[part]}\");'><i class='glyphicon glyphicon-remove hdd'></i></span>" : "";
	$mpoint = "<div>{$fscheck}<i class='glyphicon glyphicon-arrow-right'></i>";
	if ($mounted) {
		$mpoint .= "<a title='Browse Share.' href='/Shares/Browse?dir={$partition[mountpoint]}'>{$partition[mountpoint]}</a></div>";
	} else {
		$mpoint .= "<form title='Click to Change Share Name.' method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_mountpoint&serial={$partition[serial]}&partition={$partition[part]}' target='progressFrame' style='display:inline;margin:0;padding:0;'><span class='text exec'><a>{$partition[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$partition[mountpoint]}' hidden /></form> {$rm_partition}</div>";
	}
	$mbutton = make_mount_button($partition);
  
	$out[] = "<tr class='$outdd toggle-parts toggle-".basename($disk['device'])."' style='__SHOW__' >";
	$out[] = "<td></td>";
	$out[] = "<td>{$mpoint}</td>";
	$out[] = "<td class='mount'>{$mbutton}</td>";
	$out[] = "<td>-</td>";
	$out[] = "<td >".$partition['fstype']."</td>";
	$out[] = "<td><span>".my_scale($partition['size'], $unit)." $unit</span></td>";
	$out[] = "<td>".(strlen($partition['target']) ? shell_exec("lsof '${partition[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td>";
	$out[] = render_used_and_free($partition);
	$out[] = "<td>-</td>";
	$out[] = "<td title='Turn on to Share Device with SMB and/or NFS.'><input type='checkbox' class='toggle_share' info='".htmlentities(json_encode($partition))."' ".(($partition['shared']) ? 'checked':'')."></td>";
	$out[] = "<td><a title='Edit Device Script.' href='/Main/EditScript?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_config($partition['serial'],"command.{$partition[part]}")) ? "":"opacity: 0.4;" )."'></a></td>";
	$out[] = "<tr>";
	return $out;
}

function make_mount_button($device) {
	global $paths;
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
	if ($format) {
		$disable = get_config("Config", "destructive_mode") == "enabled" ? "" : "disabled";
		$button = sprintf($button, $context, 'format', $disable, 'glyphicon glyphicon-erase', 'Format');
	} elseif ($is_mounting) {
		$button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Mounting...');
	} elseif ($is_unmounting) {
		$button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Unmounting...');
	} elseif ($mounted) {
		$button = sprintf($button, $context, 'umount', '', 'glyphicon glyphicon-export', 'Unmount');
	} else {
		$button = sprintf($button, $context, 'mount', $disable, 'glyphicon glyphicon-import', 'Mount');
	}
	return $button;
}

switch ($_POST['action']) {
	case 'get_content':
		$disks = get_all_disks_info();
		$preclear = "";
		echo "<table class='usb_disks custom_head'><thead><tr><td>Device</td><td>Identification</td><td></td><td>Temp</td><td>FS</td><td>Size</td><td>Open files</td><td>Used</td><td>Free</td><td>Auto mount</td><td>Share</td><td>Script</td></tr></thead>";
		echo "<tbody>";
		if ( count($disks) ) {
			$odd="odd";
			foreach ($disks as $disk) {
				$mounted       = in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $disk['partitions']));
				$temp          = my_temp($disk['temperature']);
				$disk_name     = basename($disk['device']);
				$p             = (count($disk['partitions']) <= 1) ? render_partition($disk, $disk['partitions'][0]) : FALSE;
				$preclearing   = is_file("/tmp/preclear_stat_{$disk_name}");
				$is_precleared = ($disk['partitions'][0]['fstype'] == "precleared") ? true : false;

				$mbutton = make_mount_button($disk);

				if (! $mounted && file_exists("plugins/preclear.disk/icons/precleardisk.png")) {
					$preclear_link = " <a title='Preclear Disk.' class='exec green' href='/Settings/Preclear?disk={$disk_name}'><img src='/plugins/preclear.disk/icons/precleardisk.png'></a>";
				} else {
					$preclear_link = "";
				}
				if ($p === FALSE) {
					$hdd_serial = "<span class='exec toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i><i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>{$disk[serial]}</span>{$preclear_link}<div id='preclear_{$disk_name}'></div>";
				} elseif($is_precleared) {
					$hdd_serial = "<span class='toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i><span style='margin:4px;'></span>{$disk[serial]}</span>{$preclear_link}<div id='preclear_{$disk_name}'></div>";
				} else {
					$hdd_serial = "<span class='exec toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i><span style='margin:4px;'></span>{$disk[serial]}</span>{$preclear_link}<div id='preclear_{$disk_name}'></div>";
				}

				if ($preclearing) {
					$preclear .= "get_preclear('{$disk_name}');";
				}
				echo "<tr class='{$odd} toggle-disk'>";
				echo "<td title='Run Smart Report on {$disk_name}.'><img src='/webGui/images/".(is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" )."'>";
				if ( $disk['partitions'][0]['fstype'] == "vfat" ) {
					echo " {$disk_name}</td>";
				} else {
					echo "<a href='/Main/Device?name={$disk_name}&file=/tmp/screen_buffer'> {$disk_name}</a></td>";
				}
				echo "<td>{$hdd_serial}</td>";
				echo "<td class='mount'>{$mbutton}</td>";
				echo "<td>{$temp}</td>";
				echo ($p)?$p[5]:"<td>-</td>";
				echo "<td>".my_scale($disk['size'],$unit)." {$unit}</td>";
				echo ($p)?$p[7]:"<td>-</td>";
				echo ($p)?$p[8]:"<td>-</td><td>-</td>";
				echo "<td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='automount' serial='".$disk['partitions'][0]['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td>";
				echo ($p)?$p[10]:"<td>-</td>";
				echo ($p)?$p[11]:"<td>-</td>";
				echo "</tr>";
				if (! $is_precleared) {
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
		$samba_mounts = get_samba_mounts();
		echo "<div id='smb_tab' class='show-complete'>";
		echo "<div id='title'><span class='left'><img src='/plugins/dynamix/icons/smbsettings.png' class='icon'>SMB Mounts</span></div>";
		echo "<table class='samba_mounts custom_head'><thead><tr><td>Device</td><td>Source</td><td>Mount point</td><td></TD><td>Remove</td><td>Size</td><td>Used</td><td>Free</td><td>Auto mount</td><td>Script</td></tr></thead>";    echo "<tbody>";
		if (count($samba_mounts)) {
			$odd="odd";
			foreach ($samba_mounts as $mount) {
				$mounted = is_mounted($mount['device']);
				$is_alive = (trim(exec("ping -c 1 -W 1 {$mount[ip]} >/dev/null 2>&1; echo $?")) == 0 ) ? TRUE : FALSE;
				echo "<tr class='$odd'>";
				printf( "<td><img src='/webGui/images/%s'> smb</td>", ( $is_alive ? "green-on.png":"green-blink.png" ));
				echo "<td><div><i class='glyphicon glyphicon-globe hdd'></i><span style='margin:4px;'></span>{$mount[device]}</div></td>";
				if ($mounted) {
					echo "<td><i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'><a title='Browse SMB Mount.' href='/Shares/Browse?dir={$mount[mountpoint]}'>{$mount[mountpoint]}</a></td>";
				} else {
					echo "<td><form title='Click to change SMB Mount Name.' method='POST' action='/plugins/${plugin}/UnassignedDevices.php?action=change_samba_mountpoint&device={$mount[device]}' target='progressFrame' style='display:inline;margin:0;padding:0;'>
					<i class='glyphicon glyphicon-save hdd'></i><span style='margin:4px;'></span><span class='text exec'><a>{$mount[mountpoint]}</a></span>
					<input class='input' type='text' name='mountpoint' value='{$mount[mountpoint]}' hidden />
					</form></td>";
				}
				echo "<td><span style='width:auto;text-align:right;'>".($mounted ? "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'umount','{$mount[device]}');\"><i class='glyphicon glyphicon-export'></i> Unmount</button>" : "<button type='button' style='padding:2px 7px 2px 7px;' onclick=\"disk_op(this, 'mount','{$mount[device]}');\"><i class='glyphicon glyphicon-import'></i>  Mount</button>")."</span></td>";
				echo $mounted ? "<td><i class='glyphicon glyphicon-remove hdd'></i></td>" : "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_samba_config(\"{$mount[device]}\");' title='Remove SMB mount.'> <i class='glyphicon glyphicon-remove hdd'></i></a></td>";
				echo "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
				echo render_used_and_free($mount);
				echo "<td title='Turn on to Mount Device when Array is Started.'><input type='checkbox' class='samba_automount' device='{$mount[device]}' ".(($mount['automount']) ? 'checked':'')."></td>";
				echo "<td><a title='Edit SMB Mount Script.' href='/Main/EditScript?d=".urlencode($mount['device'])."&l=".urlencode(basename($mount['mountpoint']))."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_samba_config($mount['device'],"command")) ? "":"opacity: 0.4;" )."'></a></td>";
				echo "</tr>";
				$odd = ($odd == "odd") ? "even" : "odd";
			}
		} else {
			echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No SMB mounts configured.</td></tr>";
		}
		echo "</tbody></table><button type='button' onclick='add_samba();'>Add SMB mount</button></div>";

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
			echo "<table class='usb_absent custom_head'><thead><tr><td>Device</td><td>Serial Number</td><td>Auto mount</td><td colspan='7'>Remove config</td></tr></thead><tbody>${ct}</tbody></table></div>";
		}

		echo 
		'<script type="text/javascript">
		'.$preclear.'
		$(".automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});
		$(".automount").change(function(){$.post(URL,{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});

		$(".samba_automount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});
		$(".samba_automount").change(function(){$.post(URL,{action:"samba_automount",device:$(this).attr("device"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});

		$(".toggle_share").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});
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
	case 'mount':
		$device = urldecode($_POST['device']);
		if (file_exists($device) || strpos($device, "//") === 0 ) {
			exec("plugins/${plugin}/scripts/unassigned_mount $device >/dev/null 2>&1 &");
		}
		break;
	case 'umount':
		$device = urldecode($_POST['device']);
		if (file_exists($device) || strpos($device, "//") === 0 ) {
			echo exec("plugins/${plugin}/scripts/unassigned_umount $device >/dev/null 2>&1 &");
		}
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
	case 'list_samba_shares':
		$ip = urldecode($_POST['IP']);
		$user = isset($_POST['USER']) ? urlencode($_POST['USER']) : NULL;
		$pass = isset($_POST['PASS']) ? urlencode($_POST['PASS']) : NULL;
		$login = $user ? ($pass ? "-U '{$user}%{$pass}'" : "-U '{$user}' -N") : "-U%";
		echo shell_exec("smbclient -g -L $ip $login 2>&1|awk -F'|' '/Disk/{print $2}'|sort");
		break;
	case 'list_samba_hosts':
		$hosts = array();
		foreach ( explode(PHP_EOL, shell_exec("/usr/bin/nmblookup {$var[WORKGROUP]} 2>/dev/null") ) as $l ) {
			if (! is_bool( strpos( $l, "<00>") ) ) {
				$ip = explode(" ", $l)[0];
				foreach ( explode(PHP_EOL, shell_exec("/usr/bin/nmblookup -r -A $ip 2>&1") ) as $l ) {
					if (! is_bool( strpos( $l, "<00>") ) ) {
						$hosts[] = trim(explode(" ", $l)[0])."\n";
						break;
					}
				}
			}
		}
		natsort($hosts);
		echo implode(PHP_EOL, array_unique($hosts));
		break;
	case 'add_samba_mount':
		$ip = urldecode($_POST['IP']);
		$user = isset($_POST['USER']) ? urldecode($_POST['USER']) : "";
		$pass = isset($_POST['PASS']) ? urldecode($_POST['PASS']) : "";
		$share = isset($_POST['SHARE']) ? urldecode($_POST['SHARE']) : "";
		set_samba_config("//${ip}/${share}", "ip", $ip);
		set_samba_config("//${ip}/${share}", "user", $user);
		set_samba_config("//${ip}/${share}", "pass", $pass);
		set_samba_config("//${ip}/${share}", "share", $share);
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
	case 'get_preclear':
		$device = urldecode($_POST['device']);
		if (is_file("/tmp/preclear_stat_{$device}")) {
			$preclear = explode("|", file_get_contents("/tmp/preclear_stat_{$device}"));
			$status = (count($preclear) > 3) ? ( file_exists( "/proc/".trim($preclear[3])) ? "<span style='color:#478406;'>{$preclear[2]}</span>" : "<span style='color:#CC0000;'>{$preclear[2]} <a class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_preclear(\"{$device}\");' title='Clear stats.'> <i class='glyphicon glyphicon-remove hdd'></i></a></span>" ) : $preclear[2]." <a class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_preclear(\"{$device}\");' title='Clear stats.'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
			$status = str_replace("^n", "<br>" , $status);
			if (tmux_is_session("preclear_disk_{$device}") && is_file("plugins/preclear.disk/Preclear.php")) $status = "$status<a class='openPreclear exec' onclick='openPreclear(\"{$device}\");' title='Preview.'><i class='glyphicon glyphicon-eye-open'></i></a>";
			echo json_encode(array( 'preclear' => "<i class='glyphicon glyphicon-dashboard hdd'></i><span style='margin:4px;'></span>".$status ));
		} else {
			echo json_encode(array( 'preclear' => " "));
		}
		break;
	case 'rm_preclear':
		$device = urldecode($_POST['device']);
		@unlink("/tmp/preclear_stat_{$device}");
		break;
	case 'send_log':
		return sendLog();
		break;
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
			$mountpoint = urldecode($_POST['mountpoint']);
			set_config($serial, "mountpoint.${partition}", $mountpoint);
			require_once("update.htm");
			break;
		case 'change_samba_mountpoint':
			$device = urldecode($_GET['device']);
			$mountpoint = urldecode($_POST['mountpoint']);
			set_samba_config($device, "mountpoint", $mountpoint);
			require_once("update.htm");
			break;
	}
?>
