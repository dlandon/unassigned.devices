<?php
/* Copyright 2015-2020, Guilherme Jardim
 * Copyright 2022-2025, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$_SERVER['REQUEST_URI'] = "preclear";

/* Load the UD preclear library file if it is not already loaded. */
require_once("plugins/unassigned.devices.preclear/include/lib.php");

##############################################
#############	 VARIABLES		##############
##############################################

$Preclear			= new Preclear;
$script_files		= $Preclear->scriptFiles();

$display = $_POST['display'] ?? [];

if (! is_dir(dirname($state_file)) )
{
	@mkdir(dirname($state_file), 0777, TRUE);
}

##################################################
#############	MISC FUNCTIONS		##############
##################################################

function reload_partition($name)
{
	$device = "/dev/".$name;
	
	/* Reload the partition. */
	exec("hdparm -z ".escapeshellarg($device)." >/dev/null 2>&1 &");

	/* Refresh partition information. */
	exec("/usr/sbin/partprobe ".escapeshellarg($device));

	/* Update disk info. */
	shell_exec("/sbin/udevadm trigger --action=change ".escapeshellarg($device));

}


function listDir($root)
{
	$iter = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, 
	RecursiveDirectoryIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST,
	RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = [];

	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) {
			$paths[] = $path;
		}
	}

	return $paths;
}

$start_time = time();
if (isset($_POST['action'])) {
	switch ($_POST['action']) {
		case 'get_content':		/* THIS CAN EVENTUALLY BE REMOVED.  LEFT FOR COMPATIBLITIY. */
		case 'get_preclear_content':
			preclear_log("Starting get_content: ".(time() - $start_time),'DEBUG');

			/* See if we had a hot plug event. */
			if ((file_exists($GLOBALS['hotplug_event']) && (file_get_contents($GLOBALS['hotplug_event']) == ""))) {
				exec("/etc/rc.d/rc.diskinfo force & >/dev/null");
				file_put_contents($GLOBALS['hotplug_event'], "preclear");
			} else {
				exec("/etc/rc.d/rc.diskinfo & >/dev/null");
			}

			$disks = Misc::get_json($diskinfo);
			foreach ($disks as $disk => $attibutes) {
				$disks[$disk]["PRECLEARING"] = $Preclear->isRunning($attibutes["DEVICE"]);
			}
			$all_status = [];
			$all_disks_o = [];

			$sort = [];

			$counter = 9999;
			if ( count($disks) ) {
				$reports	 = is_dir($GLOBALS['preclear_reports']) ? glob($GLOBALS['preclear_reports']."*.txt") : [];
				foreach ($disks as $disk) {
					$disk_name		= basename($disk['DEVICE']);
					$disk_dev		= $disk['NAME'];
					$disk_display	= $disk['NAME_H'];
					$disk_orb		= "<i class='fa fa-circle orb ".($disk['RUNNING'] ? "green-orb" : "grey-orb")." orb'></i>";
					$disk_icon		= $disk['SSD'] ? "icon-nvme" : "fa fa-hdd-o";
					$serial			= trim($disk['SERIAL'])." (".$disk_name.")";
					$precleared		= $disk['PRECLEAR'] && ! $disk['PRECLEARING'] ? " - <em>Precleared</em>" : "";
					$temp			= $disk['TEMP'] ? my_temp($disk['TEMP']) : "*";
					if ($disk['SERIAL_SHORT']) {
						$disk_reports	= array_filter($reports, function ($report) use ($disk) {
											return preg_match("|".$disk["SERIAL_SHORT"]."|", $report) && ( preg_match("|_report_|", $report) || preg_match("|_rpt_|", $report) );
											});
						$disk_reports	= array_reverse($disk_reports, false);
					} else {
						$disk_reports	= [];
					}

					if (count($disk_reports)) {
						$title  = "<span title='"._('Click to show reports').".' class='exec toggle-reports' hdd='".$disk_name."'>
									<i class='fa fa-plus-square fa-append'></i></span>".$serial.$precleared;

						$report_files = "";
						foreach ($disk_reports as $report) {
							$report_files .= "<div style='margin:4px 0px 4px 0px;'>";
							$report_files .= "<i class='fa fa-list-alt hdd'></i>";
							$report_files .= "<span style='margin:7px;'></span><a href='{$report}' title='"._('Show Preclear Report').".' target='_blank'>".pathinfo($report, PATHINFO_FILENAME)."</a>";
							$report_files .= "<span><a class='exec info' style='color:#CC0000;font-weight:bold;' onclick='rmReport(\"{$report}\", this);'><span>"._("Remove Report")."</span>&nbsp;&nbsp;<i class='fa fa-times hdd'></i></a></div>";
						}
					} else {
						$report_files="";
						$title	= "<span class='toggle-reports' hdd='{$disk_name}'><i class='fa fa-plus-square fa-append grey-orb '></i>".$serial.$precleared."</span>";
					}

					if ($Preclear->isRunning($disk_name)) {
						$status	= $Preclear->Status($disk_name, $disk["SERIAL_SHORT"]);
						$all_status[$disk['SERIAL_SHORT']]['footer'] = "<span>{$disk['SERIAL']} ({$disk['NAME']}) <br> Size: {$disk['SIZE_H']} | Temp: ". my_temp($disk['TEMP']) ."</span><br><span style='float:right;'>$status</span>";
						$all_status[$disk['SERIAL_SHORT']]['status'] = $status;
					} elseif (strpos($disk['NAME'], "dev") !== false) {
						$status	= $Preclear->Link($disk_name, "text");
					} else {
						$status	= "<span>"._('Cannot Preclear')."</span>";
					}

					$disk_log_title	= _('Disk Log Information');
					$output = "<tr device='" . $disk_name . "'><td class='disk-cell'>".$disk_orb."<a href='/Tools/Preclear/Device?name=$disk_dev'>" . $disk_display . "</a></td>";
					if (version_compare($version['version'],"6.9.9", ">")) {
						/* Disk log in 6.10 and later. */
						$output .= "<td><a class='info' href=\"#\" onclick=\"openTerminal('disklog', '{$disk_name}')\"><i class='".$disk_icon." icon'></i><span>"._("Disk Log Information")."</span></a>";
					} else {
						/* Disk log in 6.9. */
						$output .= "<td><a class='info' href=\"#\" onclick=\"openBox('/webGui/scripts/disk_log&amp;arg1={$disk_device}','Disk Log Information',600,900,false);return false\"><i class='".$disk_icon." icon'></i><span>"._("Disk Log Information")."</span></a>";
					}
					$output .= $title."</td><td>".$temp."</td><td><span>".$disk['SIZE_H']."</span></td><td>".$status."</td></tr>";

					if (!empty($report_files)) {
						$output .= "<tr class='report-row' style='display:none;'><td></td><td colspan='4'><div class='toggle-{$disk_name}'>".$report_files."</div></td></tr>";
					}

					$pos = array_key_exists($disk_name, $sort) ? $sort[$disk_name] : $counter;
					$sort[$disk_name]			= $pos;
					$all_disks_o[$disk_name]	= $output;
					$counter++;
				}
			} else {
				$sort['none']			= $counter;
				$all_disks_o['none']	= "<tr><td colspan='5' style='text-align:center;'>"._('There are no disks that can be precleared').".</td></tr>"."<tr><td colspan='5' style='text-align:center;'>"._('A disk must be cleared of all partitions before it can be precleared').".&nbsp;&nbsp;"._('You can use Unassigned Devices to clear the disk').".</td></tr>";
			}

			preclear_log("get_content Finished: ".(time() - $start_time),'DEBUG');
			$sort = array_flip($sort);
			sort($sort, SORT_NUMERIC);
			$queue = (is_file("/var/run/preclear_queue.pid") && posix_kill(file_get_contents("/var/run/preclear_queue.pid"), 0)) ? true : false;
			echo json_encode(array("disks" => $all_disks_o, "info" => json_encode($disks), "status" => $all_status, "sort" => $sort, "queue" => $queue));
			break;

		case 'get_status':
			$disk_name	= htmlspecialchars(urldecode($_POST['device']));
			$serial		= htmlspecialchars(urldecode($_POST['serial']));
			$status		= $Preclear->Status($disk_name, $serial);
			echo json_encode(true);
			break;

		case 'start_preclear':
			$devices = $_POST['device'] ?? [];
			$success = true;

			if (count($devices)) {
				foreach ($devices as $device) {
					$serial		= $Preclear->diskSerial($device);
					$session	= "preclear_disk_{$serial}";
					$op			= (isset($_POST['op']) && $_POST['op'] != "0") ? htmlspecialchars(urldecode($_POST['op'])) : "";
					$file		= (isset($_POST['file'])) ? htmlspecialchars(urldecode($_POST['file'])) : "";
					$scope		= $_POST['scope'];
					$script		= $script_files[$scope];
					$devname	= basename($device);

					/* Verify if the disk is suitable to preclear */
					$Preclear->allDisks[$devname]["MOUNTED"]	= $Preclear->allDisks[$devname]["MOUNTED"] ?? false;
					if ( $Preclear->isRunning($device) || (array_key_exists($devname, $Preclear->allDisks) && $Preclear->allDisks[$devname]["MOUNTED"] )) {
						preclear_log("Disk {$serial} not suitable for preclear.");
						continue;
					}

					@file_put_contents($GLOBALS['preclear_status'].$devname,"{$devname}|NN|Starting...");
					$confirm	= false;
					if ( $op == "resume" && is_file($file)) {
						$cmd = "$script --load-file ".escapeshellarg($file)." {$device}";
					} else if($op == "resume" && ! is_file($file)) {
						break;
					} else if ($scope == "gfjardim") {
						$notify		= (isset($_POST['--notify']) && $_POST['--notify'] > 0) ? " --notify ".htmlspecialchars(urldecode($_POST['--notify'])) : "";
						$frequency 	= (isset($_POST['--frequency']) && $_POST['--frequency'] > 0 && intval($_POST['--notify']) > 0) ? " --frequency ".htmlspecialchars(urldecode($_POST['--frequency'])) : "";
						$cycles		= (isset($_POST['--cycles'])) ? " --cycles ".htmlspecialchars(urldecode($_POST['--cycles'])) : "";
						$pre_read	= (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
						$post_read	= (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
						$test		= (isset($_POST['--test']) && $_POST['--test'] == "on") ? " --test" : "";
						$noprompt	= " --no-prompt";

						$cmd		= "$script {$op}{$notify}{$frequency}{$cycles}{$pre_read}{$post_read}{$noprompt}{$test} $device";
						preclear_log("Preclear script invoked as: $cmd");
					} else {
						$capable	= array_key_exists($scope, $script_files) ? $Preclear->scriptCapabilities($script_files[$scope]) : [];
						$notification = (array_key_exists("notifications", $capable) && $capable["notifications"]);
						if ($notification) {
							$notify	= (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".htmlspecialchars(urldecode($_POST['-o'])) : "";
							$mail	= (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".htmlspecialchars(urldecode($_POST['-M'])) : "";
						} else {
							$notify	= "";
							$mail	= (isset($_POST['-M']) && $_POST['-M'] > 0) ? " -M ".htmlspecialchars(urldecode($_POST['-M'])) : "";
						}
						$passes		= isset($_POST['-c']) ? " -c".htmlspecialchars(urldecode($_POST['-c'])) : "";
						$read_sz	= (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".htmlspecialchars(urldecode($_POST['-r'])) : "";
						$write_sz	= (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".htmlspecialchars(urldecode($_POST['-w'])) : "";
						$pre_read	= (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
						$post_read	= (isset($_POST['-X']) && $_POST['-X'] == "on") ? " -X" : "";
						$fast_read	= (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";
						$confirm	= (! $op || $op == " -z" || $op == " -V") ? true : false;
						$test		= (isset($_POST['-s']) && $_POST['-s'] == "on") ? " -s" : "";
						$sector		= " -A";

						$noprompt = (array_key_exists("noprompt", $capable) && $capable["noprompt"]) ? " -J" : "";

						if ( $post_read && $pre_read ) {
							$post_read	= " -n";
							$pre_read	= "";
						}

						if (! $op ) {
							$cmd	= "$script {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$post_read}{$fast_read}{$noprompt}{$test}{$sector} $device";
						} else if ( $op == "-V" ) {
							$cmd	= "$script {$op}{$fast_read}{$mail}{$notify}{$read_sz}{$write_sz}{$noprompt}{$test} $device";
						} else {
							$cmd	= "$script {$op}{$noprompt} $device";
							@unlink($GLOBALS['preclear_status'].$devname);
						}

						preclear_log("Preclear script invoked as: $cmd");
					}

					/* Enabling queue. */
					$queue_file		= $GLOBALS['preclear_reports']."queue";
					$queue			= is_file($queue_file) ? (is_numeric(file_get_contents($queue_file)) ? file_get_contents($queue_file) : 0 ) : 0;
					$queue_running	= is_file("/var/run/preclear_queue.pid") && posix_kill(file_get_contents("/var/run/preclear_queue.pid"), 0);
					if ($queue > 0) {
						if (! TMUX::hasSession("preclear_queue")) {
							TMUX::NewSession("preclear_queue");
						}
						if (! $queue_running) {
							TMUX::sendCommand("preclear_queue", DOCROOT."/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/scripts/preclear_queue.sh $queue");
						}
					}

					if (! TMUX::hasSession( $session ))
					{
						TMUX::NewSession( $session );
						usleep( 500 * 1000 );
						TMUX::sendCommand($session, $cmd);
					} else {
						$success = false;
					}

					if ( $confirm && ! $noprompt ) {
						foreach( range(0, 10) as $x ) {
							if ( strpos(TMUX::getSession($session), "Answer Yes to continue") ) {
								sleep(1);
								TMUX::sendCommand($session, "Yes");
								break;
							} else {
								sleep(1);
							}
						}
					}
				}
			} else {
				$success = false;
			}

			echo json_encode($success);
			break;

		case 'stop_preclear':
			$serials = is_array($_POST['serial']) ? $_POST['serial'] : [$_POST['serial']];
			foreach ($serials as $serial) {
				if ($serial == "DEVICE") {
					continue;
				}
				$device = basename($Preclear->serialDisk($serial));
				$session = "preclear_disk_{$serial}";

				TMUX::sendKeys($session, "C-c");

				$docker = shell_exec("/usr/bin/docker ps | grep binhex-preclear");
				if ($docker) {
					shell_exec("/usr/bin/docker stop ".escapeshellarg($session));
				}

				$file = $GLOBALS['preclear_status'].$device;
				if (is_file($file)) {
					$stat = explode("|", file_get_contents($file));
					$pid	= count($stat) == 4 ? trim($stat[3]) : "";
					foreach (range(0, 30) as $num) {
						if (! file_exists( "/proc/$pid/exe")) {
							break;
						}
						usleep( 500 * 1000 );
					}

					/* Make sure all children are killed. */
					shell_exec("kill $(ps -s ".escapeshellarg($pid)." -o pid=) &>/dev/null");
				}

				TMUX::killSession($session);
				usleep(200 * 1000);
				if (is_file($GLOBALS['preclear_status'].$device)) {
					@unlink($GLOBALS['preclear_status'].$device);
				}

				if (is_file($GLOBALS['tmp_preclear'].$device."/pid")) {
					@unlink($GLOBALS['tmp_preclear'].$device."/pid");
				}

				if (is_file($GLOBALS['tmp_preclear'].$device."/pause")) {
					$unlink($GLOBALS['tmp_preclear'].$device."/pause");
				}

				if ($serial != "DEVICE") {
					preclear_log("Preclear stopped on device: ".$serial);
				}

				reload_partition($serial);
			}

			echo json_encode(true);
			break;

		case 'stop_all_preclear':
			exec("/usr/bin/tmux ls 2>/dev/null | grep 'preclear_disk_' | cut -d: -f1", $sessions);
			foreach ($sessions as $session) {
				$serial = str_replace("preclear_disk_", "", $session);
				$device = basename($Preclear->serialDisk($serial));
				$file = $GLOBALS['preclear_status'].$device;

				TMUX::sendKeys($session, "C-c");

				$docker = shell_exec("/usr/bin/docker ps | grep binhex-preclear");
				if ($docker) {
					shell_exec("/usr/bin/docker stop ".escapeshellarg($session));
				}

				if (is_file($file)) {
					$stat = explode("|", file_get_contents($file));
					$pid	= count($stat) == 4 ? trim($stat[3]) : "";
					foreach (range(0, 30) as $num) {
						if (! file_exists( "/proc/$pid/exe")) {
							break;
						}
						usleep( 500 * 1000 );
					}

					/* make sure all children are killed. */
					shell_exec("kill $(ps -s ".escapeshellarg($pid)." -o pid=) &>/dev/null");
				}

				TMUX::killSession($session);
				@unlink($GLOBALS['preclear_status'].$device);

				reload_partition($serial);
			}
			preclear_log("Preclear stopped on all devices");

			echo json_encode(true);
			break;

		case 'clear_preclear':
			$serial = htmlspecialchars(urldecode($_POST['serial']));
			$device = basename($Preclear->serialDisk($serial));
			TMUX::killSession("preclear_disk_{$serial}");
			@unlink($GLOBALS['preclear_status'].$device);
			preclear_log("Preclear cleared");
			echo "<script>parent.location=parent.location;</script>";
			break;

		case 'get_preclear':
			$serial	= htmlspecialchars(urldecode($_POST['serial']));
			$session = "preclear_disk_{$serial}";
			if ( ! TMUX::hasSession($session)) {
				$output = "<script>window.close();</script>";
			} else {
				$output	= "";
			}
			$content = preg_replace("#root@[^:]*:.*#", "", TMUX::getSession($session));
			$output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
			if ( strpos($content, "Answer Yes to continue") || strpos($content, "Type Yes to proceed") ) {
				$output .= "<br><center><button onclick='hit_yes(\"{$serial}\")'>Answer Yes</button></center>";
			}
			echo json_encode(array("content" => $output));
			break;

		case 'hit_yes':
			$serial	= htmlspecialchars(urldecode($_POST['serial']));
			$session = "preclear_disk_{$serial}";
			TMUX::sendCommand($session, "Yes");
			break;

		case 'remove_report':
			$file = htmlspecialchars(urldecode($_POST['file']));
			if (! is_bool( strpos($file, $GLOBALS['preclear_reports']))) {
				@unlink($file);
				echo "true";
			}
			preclear_log("Preclear report '".$file."' removed");

			echo json_encode(true);
			break;

		case 'download':
			$dir	= "/preclear";
			$file = htmlspecialchars(urldecode($_POST["file"]));
			@mkdir($dir);
			exec("cat $log_file 2>/dev/null | todos >".escapeshellarg("$dir/preclear_disk_log.txt"));
			exec("cat /var/log/diskinfo.log 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_log.txt"));
			exec("cat /var/local/emhttp/plugins/diskinfo/diskinfo.json 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_json.txt"));
			exec("zip -qmr ".escapeshellarg($file)." ".escapeshellarg($dir));
			echo "/$file";
			break;

		case 'get_resumable':
			$serial	= htmlspecialchars(urldecode($_POST['serial']));
			if (is_file($GLOBALS['tmp_preclear'].$serial.".resume")) {
				echo json_encode(["resume" => $GLOBALS['tmp_preclear'].$serial.".resume"]);
			} else if (is_file($GLOBALS['preclear_reports'].$serial.".resume")) {
				echo json_encode(["resume" => $GLOBALS['preclear_reports'].$serial.".resume"]);
			} else {
				echo json_encode(["resume" => false]);
			}
			break;

		case 'resume_preclear':
			$disk =htmlspecialchars(urldecode( $_POST['disk']));
			$file = $GLOBALS['tmp_preclear'].$disk."/pause";
			if (file_exists($file)) {
				@unlink($file);
			}
			preclear_log("Preclear resumed on ".$disk);
			echo json_encode(true);
			break;

		case 'set_queue':
			$queue_session = "preclear_queue";
			$queue = htmlspecialchars(urldecode($_POST["queue"]));
			$session = TMUX::hasSession($queue_session);
			$pid_file = "/var/run/preclear_queue.pid";
			$pid = is_file($pid_file) ? file_get_contents($pid_file) : 0;

			file_put_contents($GLOBALS['preclear_reports']."queue", $queue);

			if ($queue > 0) {
				if ($session && $pid > 0) {
					if (! posix_kill($pid, 0)) {
						@unlink($pid_file);
						foreach (range(0, 10) as $i) {
							if (! posix_kill($pid, 0)) {
								break;
							} else {
								sleep(1);
							}
						}
					} else {
						posix_kill($pid, 1);
					}
				} else {
					TMUX::NewSession( $queue_session );
					TMUX::sendCommand( $queue_session, DOCROOT."/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/scripts/preclear_queue.sh $queue");
				}
			} else {
				@unlink($pid_file);
				foreach (glob($GLOBALS['tmp_preclear']."*/queued") as $file) {
					@unlink($file);
					TMUX::killSession( $queue_session );
				}
			}
			sleep(1);
			echo json_encode(true);
			break;

		case 'get_queue':
			echo is_file($GLOBALS['preclear_reports']."queue") ? trim(file_get_contents($GLOBALS['preclear_reports']."queue")) : 0;
			break;

		case 'resume_all':
			$paused = glob($GLOBALS['tmp_preclear']."*/pause");
			if ($paused) {
				foreach ($paused as $file) {
					@unlink($file);
				}
			}
			preclear_log("Preclear resumed on all devices.");

			echo json_encode(true);
			break;;

		case 'pause_all':
			$sessions = glob($GLOBALS['tmp_preclear']."*/");
			if ($sessions) {
				foreach ($sessions as $session) {
					file_put_contents("{$session}pause", "");
				}
			}
			preclear_log("Preclear paused on all devices");

			echo json_encode(true);
			break;;

		case 'clear_all_preclear':
			shell_exec(DOCROOT."/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/scripts/clear_preclear.sh");
			echo json_encode(true);
			break;;

		default:
			if (isset( $_POST['action'])) {
				echo json_encode(false);
			}
			break;;
	}
}

if (isset($_GET['action'])) {
	switch ($_GET['action']) {
		case 'show_preclear':
			$serial = htmlspecialchars(urldecode($_GET['serial']));
			?>
			<html>
				<body>
				<table style="width: 100%;float: center;" >
					<tbody>
					<tr>
						<td style="width: auto;">&nbsp;</td>
						<td style="width: 968px;"><div id="data_content"></div></td>
						<td style="width: auto;">&nbsp;</td>
					</tr>
					<tr>
						<td></td>
						<td><div style="text-align: center;"><button class="btn" data-clipboard-target="#data_content">Copy to clipboard</button></div></td>
						<td></td>
					</tr>
					</tbody>
				</table>
				<?if (is_file("webGui/scripts/dynamix.js")):?>
				<script src=<?autov('/webGui/scripts/dynamix.js')?>></script>
				<?else:?>
				<script src=<?autov('/webGui/javascript/dynamix.js')?>></script>
				<?endif;?>
				<script src=<?php autov("/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/assets/clipboard.min.js")?>></script>
				<script>
					var timers = {};
					let serial = "<?=$serial;?>";
					const PreclearURL = "/plugins/<?=UNASSIGNED_PRECLEAR_PLUGIN;?>/include/Preclear.php";

					function get_preclear()
					{
					clearTimeout(timers.preclear);
					$.post(PreclearURL,{action:"get_preclear",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"},function(data) {
						if (data.content)
						{
						$("#data_content").html(data.content);
						}
					},"json").always(function() {
						timers.preclear=setTimeout('get_preclear()',1000);
					}).fail(function (jqXHR, textStatus, error)
					{
						if (jqXHR.status == 200)
						{
						window.location=window.location.pathname+window.location.hash;
						}
					});
					}
					function hit_yes(serial)
					{
					$.post(PreclearURL,{action:"hit_yes",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"});
					}
					$(function() {
					document.title='Preclear for disk <?=$serial;?> ';
					get_preclear();
					new Clipboard('.btn');
					});
				</script>
				</body>
			</html>
			<?
			break;

		case 'get_log':
			$session = htmlspecialchars(urldecode($_GET['session']));
			$file = file("/var/log/".UNASSIGNED_PRECLEAR_PLUGIN.".log", FILE_IGNORE_NEW_LINES);
			$output = preg_grep("/{$session}/i",$file);
			$tmpfile = "/tmp/preclear/{$session}.txt";

			file_put_contents($tmpfile, implode("\r\n", $output));

			header('Content-Description: File Transfer');
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename='.basename($tmpfile));
			header('Content-Transfer-Encoding: binary');
			header('Expires: 0');
			header('Cache-Control: must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . filesize($tmpfile));
			readfile($tmpfile);

			@unlink($tmpfile);
			break;
	}
}
?>