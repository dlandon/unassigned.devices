<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2022, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

set_error_handler("unassigned_log_error");
set_exception_handler( "unassigned_log_exception" );
$plugin = "unassigned.devices";
$paths = [	"smb_extra"			=> "/tmp/{$plugin}/smb-settings.conf",
			"smb_usb_shares"	=> "/etc/samba/unassigned-shares",
			"usb_mountpoint"	=> "/mnt/disks",
			"remote_mountpoint"	=> "/mnt/remotes",
			"root_mountpoint"	=> "/mnt/rootshare",
			"dev_state"			=> "/usr/local/emhttp/state/devs.ini",
			"device_log"		=> "/tmp/{$plugin}/logs/",
			"config_file"		=> "/tmp/{$plugin}/config/{$plugin}.cfg",
			"samba_mount"		=> "/tmp/{$plugin}/config/samba_mount.cfg",
			"iso_mount"			=> "/tmp/{$plugin}/config/iso_mount.cfg",
			"scripts"			=> "/tmp/{$plugin}/scripts/",
			"credentials"		=> "/tmp/{$plugin}/credentials",
			"authentication"	=> "/tmp/{$plugin}/authentication",
			"luks_pass"			=> "/tmp/{$plugin}/luks_pass",
			"script_run"		=> "/tmp/{$plugin}/script_run",
			"hotplug_event"		=> "/tmp/{$plugin}/hotplug_event",
			"tmp_file"			=> "/tmp/{$plugin}/".uniqid("move_", true).".tmp",
			"state"				=> "/var/state/{$plugin}/{$plugin}.ini",
			"diag_state"		=> "/var/local/emhttp/{$plugin}.ini",
			"mounted"			=> "/var/state/{$plugin}/{$plugin}.json",
			"hdd_temp"			=> "/var/state/{$plugin}/hdd_temp.json",
			"run_status"		=> "/var/state/{$plugin}/run_status.json",
			"ping_status"		=> "/var/state/{$plugin}/ping_status.json",
			"df_status"			=> "/var/state/{$plugin}/df_status.json",
			"disk_names"		=> "/var/state/{$plugin}/disk_names.json",
			"share_names"		=> "/var/state/{$plugin}/share_names.json",
			"pool_state"		=> "/var/state/{$plugin}/pool_state.json",
			"unmounting"		=> "/var/state/{$plugin}/unmounting_%s.state",
			"mounting"			=> "/var/state/{$plugin}/mounting_%s.state",
			"formatting"		=> "/var/state/{$plugin}/formatting_%s.state"
		];

$docroot	= $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$users		= @parse_ini_file("$docroot/state/users.ini", true);
$disks		= @parse_ini_file("$docroot/state/disks.ini", true);

/* Get the version of Unraid we are running. */
$version = @parse_ini_file("/etc/unraid-version");

/* Set the log level for debugging. */
/* 0 - normal logging, */

/* 1 - udev and disk discovery logging, */
$UDEV_DEBUG	= 1;

/* 8 - command time outs. */
$CMD_DEBUG	= 8;

$DEBUG_LEVEL	= (int) get_config("Config", "debug_level");

/* Read Unraid variables file. Used to determine disks not assigned to the array and other array parameters. */
if (! isset($var)){
	if (! is_file("$docroot/state/var.ini")) {
		shell_exec("/usr/bin/wget -qO /dev/null localhost:$(ss -napt | /bin/grep emhttp | /bin/grep -Po ':\K\d+') >/dev/null");
	}
	$var = @parse_ini_file("$docroot/state/var.ini");
}

/* See if the preclear plugin is installed. */
if ( is_file( "plugins/preclear.disk/assets/lib.php" ) ) {
	require_once( "plugins/preclear.disk/assets/lib.php" );
	$Preclear = new Preclear;
} else if ( is_file( "plugins/".$plugin.".preclear/include/lib.php" ) ) {
	require_once( "plugins/".$plugin.".preclear/include/lib.php" );
	$Preclear = new Preclear;
} else {
	$Preclear = null;
}

/* Misc functions. */
class MiscUD
{
	/* Save content to a json file. */
	public function save_json($file, $content) {
		global $paths;

		/* Write to temp file and then move to destination file. */
		@file_put_contents($paths['tmp_file'], json_encode($content, JSON_PRETTY_PRINT));
		@rename($paths['tmp_file'], $file);
	}

	/* Get content from a json file. */
	public function get_json($file) {
		return file_exists($file) ? @json_decode(file_get_contents($file), true) : array();
	}

	public function disk_device($disk) {
		return (file_exists($disk)) ? $disk : "/dev/{$disk}";
	}

	/* Check for a valid IP address. */
	public function is_ip($str) {
		return filter_var($str, FILTER_VALIDATE_IP);
	}

	/* Check for text in a file. */
	public function exist_in_file($file, $text) {
		return (preg_grep("%{$text}%", @file($file))) ? true : false;
	}

	/* Is the device an nvme disk? */
	public function is_device_nvme($dev) {
		return (strpos($dev, "nvme") !== false) ? true : false;
	}

	/* Remove the partition number from $dev and return the base device. */
	public function base_device($dev) {
		return (strpos($dev, "nvme") !== false) ? preg_replace("#\d+p#i", "", $dev) : preg_replace("#\d+#i", "", $dev);
	}

	/* Spin disk up or down using Unraid api. */
	public function spin_disk($down, $dev) {
		if ($down) {
			exec(escapeshellcmd("/usr/local/sbin/emcmd cmdSpindown=".escapeshellarg($dev)));
		} else {
			exec(escapeshellcmd("/usr/local/sbin/emcmd cmdSpinup=".escapeshellarg($dev)));
		}
	}

	/* Get array of pool devices on a mount point. */
	public function get_pool_devices($mountpoint, $remove = false) {
		global $paths;

		$rc = array();

		/* Get the current pool status. */
		$pool_state	= MiscUD::get_json($paths['pool_state']);

		/* If this mount point is not defined, set it as an empty array. */
		$pool_state[$mountpoint] = is_array($pool_state[$mountpoint]) ? $pool_state[$mountpoint] : array();

		if ($remove) {
			/* Remove this from the pool devices if unmounting. */
			if (isset($pool_state[$mountpoint])) {
				unset($pool_state[$mountpoint]);
				MiscUD::save_json($paths['pool_state'], $pool_state);
			}
		} else if (is_array($pool_state[$mountpoint]) && (! count($pool_state[$mountpoint]))) {
			/* Get the pool parameters if they are not already defined. */
			unassigned_log("Get Disk Pool members on mountpoint '".$mountpoint."'.", $GLOBALS['UDEV_DEBUG']);

			/* Get the brfs pool status from the mountpoint. */
			$s	= shell_exec("/sbin/btrfs fi show ".escapeshellarg($mountpoint)." | /bin/grep 'path' | /bin/awk '{print $8}'");
			$rc	= explode("\n", $s);
			$pool_state[$mountpoint] = array_filter($rc);
			MiscUD::save_json($paths['pool_state'], $pool_state);
		} else {
			/* Get the pool status from the pool_state. */
			$rc = $pool_state[$mountpoint];
		}

		return array_filter($rc);
	}
}

/* Echo variable to GUI for debugging. */
function _echo($m) {
	echo "<pre>".print_r($m,true)."</pre>";
}

/* Save ini and cfg files to tmp file system and then copy cfg file changes to flash. */
function save_ini_file($file, $array, $save_config = true) {
	global $plugin, $paths;

	/* Lock file for concurrent operations unique to each process. */
	$lock_file	= "/tmp/{$plugin}/".uniqid("ini_", true).".lock";

	/* Check for any lock files for previous processes. */
	$i = 0;
	while ((! empty(glob("/tmp/{$plugin}/ini_*.lock"))) && ($i < 100)) {
		sleep(0.01);
		$i++;
	}

	/* Did we time out waiting for unlock release? */
	if ($i == 100) {
		unassigned_log("Timed out waiting for save_ini lock release.", $GLOBALS['UDEV_DEBUG']);
	}

	/* Create the lock. */
	touch($lock_file);

	$res = array();
	foreach($array as $key => $val) {
		if (is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) {
				$res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
			}
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}

	/* Write to temp file and then move to destination file. */
	@file_put_contents($paths['tmp_file'], implode(PHP_EOL, $res));
	@rename($paths['tmp_file'], $file);

	/* Write cfg file changes back to flash. */
	if ($save_config) {
		$file_path = pathinfo($file);
		if ($file_path['extension'] == "cfg") {
			@file_put_contents("/boot/config/plugins/".$plugin."/".basename($file), implode(PHP_EOL, $res));
		}
	}

	/* Release the lock. */
	@unlink($lock_file);
}

/* Log program error. */
function unassigned_log_error($errno, $errstr, $errfile, $errline)
{
	switch($errno){
	case E_ERROR:
		$error = "Error";
		break;
	case E_WARNING:
		$error = "Warning";
		break;
	case E_PARSE:
		$error = "Parse Error";
		break;
	case E_NOTICE:
		$error = "Notice";
		return;
		break;
	case E_CORE_ERROR:
		$error = "Core Error";
		break;
	case E_CORE_WARNING:
		$error = "Core Warning";
		break;
	case E_COMPILE_ERROR:
		$error = "Compile Error";
		break;
	case E_COMPILE_WARNING:
		$error = "Compile Warning";
		break;
	case E_USER_ERROR:
		$error = "User Error";
		break;
	case E_USER_WARNING:
		$error = "User Warning";
		break;
	case E_USER_NOTICE:
		$error = "User Notice";
		break;
	case E_STRICT:
		$error = "Strict Notice";
		break;
	case E_RECOVERABLE_ERROR:
		$error = "Recoverable Error";
		break;
	default:
		$error = "Unknown error ($errno)";
		return;
		break;
	}

	unassigned_log("PHP {$error}: $errstr in {$errfile} on line {$errline}");
}

/* Log program exception. */
function unassigned_log_exception( $e )
{
	unassigned_log("PHP Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
}

/* Unassigned Devices logging. */
function unassigned_log($m, $debug_level = 0) {
	global $plugin;

	if (($debug_level == 0) || ($debug_level == $GLOBALS["DEBUG_LEVEL"])) {
		$m		= print_r($m,true);
		$m		= str_replace("\n", " ", $m);
		$m		= str_replace('"', "'", $m);
		exec("/usr/bin/logger"." ".escapeshellarg($m)." -t ".escapeshellarg($plugin));
	}
}

/* Get a list of directories at $root. */
function listDir($root) {
	$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, 
			RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD);
	$paths = array();
	foreach ($iter as $path => $fileinfo) {
		if (! $fileinfo->isDir()) {
			$paths[] = $path;
		}
	}

	return $paths;
}

/* Remove characters that will cause issues in names. */
function safe_name($string, $convert_spaces = true) {

	$string = stripcslashes($string);

	/* Convert reserved php characters and invalid file name characters to underscore. */
	$string = str_replace( array("'", '"', "?", "#", "&", "!", "<", ">", "|"), "_", $string);

	/* Convert spaces to underscore. */
	if ($convert_spaces) {
		$string = str_replace(" " , "_", $string);
	}
	$string = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

	return trim($string);
}

/* Get the size, used, and free space on a mount point. */
function get_device_stats($mountpoint, $mounted, $active = true) {
	global $paths, $plugin;

	$rc			= "";
	$tc			= $paths['df_status'];

	/* Get the device stats if device is mounted. */
	if ($mounted) {
		$df_status	= MiscUD::get_json($tc);
		/* Run the stats script to update the state file. */
		if (($active) && ((time() - $df_status[$mountpoint]['timestamp']) > 90)) {
			exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats df_status ".escapeshellarg($tc)." ".escapeshellarg($mountpoint)." ".escapeshellarg($GLOBALS['DEBUG_LEVEL'])." &");
		}

		/* Get the updated device stats. */
		$df_status	= MiscUD::get_json($tc);
		if (isset($df_status[$mountpoint])) {
			$rc = $df_status[$mountpoint]['stats'];
		}
	}

	return preg_split('/\s+/', $rc);
}

/* Get the devX designation for this device from the devs.ini. */
function get_disk_dev($dev) {
	global $paths;

	$rc		= basename($dev);
	$sf		= $paths['dev_state'];

	/* Check for devs.ini file and get the devX designation for this device. */
	if (is_file($sf)) {
		$devs = @parse_ini_file($sf, true);
		foreach ($devs as $d) {
			if (($d['device'] == $rc) && $d['name']) {
				$rc = $d['name'];
				break;
			}
		}
	}

	return $rc;
}

/* Get the reads and writes from diskload.ini. */
function get_disk_reads_writes($ud_dev, $dev) {
	global $paths;

	$rc = array(0, 0, 0, 0);
	$sf	= $paths['dev_state'];

	/* Check for devs.ini file to get the current reads and writes. */
	if (is_file($sf)) {
		$devs	= @parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$rc[0] = $devs[$ud_dev]['numReads'];
			$rc[1] = $devs[$ud_dev]['numWrites'];
		}
	}

	/* Get the base device - remove the partition number. */
	$dev	= MiscUD::base_device(basename($dev));

	/* Get the disk_io for this device. */
	$disk_io	= is_file('state/diskload.ini') ? @parse_ini_file('state/diskload.ini') : array();
	$data		= explode(' ', $disk_io[$dev] ?? '0 0 0 0');

	/* Read rate. */
	$rc[2] 		= ($data[0] > 0.0) ? $data[0] : 0.0;

	/* Write rate. */
	$rc[3] 		= ($data[1] > 0.0) ? $data[1] : 0.0;

	return $rc;
}

/* Check to see if the disk is spun up or down. */
function is_disk_running($ud_dev, $dev) {
	global $paths;

	$rc			= false;
	$run_devs	= false;
	$sf			= $paths['dev_state'];
	$tc			= $paths['run_status'];

	/* Check for dev state file to get the current spindown state. */
	if (is_file($sf)) {
		$devs	= @parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$rc			= ($devs[$ud_dev]['spundown'] == '0') ? true : false;
			$device		= $ud_dev;
			$run_devs	= true;
			$timestamp	= time();
		}
	}

	/* If the spindown can't be gotten from the devs state, do hdparm to get it. */
	$run_status	= MiscUD::get_json($tc);
	if (! $run_devs) {
		$device = basename($dev);
		if (isset($run_status[$device]) && ((time() - $run_status[$device]['timestamp']) < 60)) {
			$rc			= ($run_status[$device]['running'] == 'yes') ? true : false;
			$timestamp	= $run_status[$device]['timestamp'];
		} else {
			$state		= trim(timed_exec(10, "/usr/sbin/hdparm -C ".escapeshellarg($dev)." 2>/dev/null | /bin/grep -c standby"));
			$rc			= ($state == 0) ? true : false;
			$timestamp	= time();
		}
	}

	/* Update the spin status. */
	$spin		= isset($run_status[$device]['spin']) ? $run_status[$device]['spin'] : "";
	$spin_time	= isset($run_status[$device]['spin']) ? $run_status[$device]['spin_time'] : 0;
	$run_status[$device] = array('timestamp' => $timestamp, 'running' => $rc ? 'yes' : 'no', 'spin_time' => $spin_time, 'spin' => $spin);
	MiscUD::save_json($tc, $run_status);

	return $rc;
}

/* Check for disk in the process of spinning up or down. */
function is_disk_spin($ud_dev, $running) {
	global $paths;

	$rc			= false;
	$tc			= $paths['run_status'];
	$run_status	= MiscUD::get_json($tc);

	/* Is disk spinning up or down? */
	if (isset($run_status[$ud_dev]['spin'])) {
		/* Stop checking if it takes too long. */
		switch ($run_status[$ud_dev]['spin']) {
			case "up":
				if ((! $running) && ((time() - $run_status[$ud_dev]['spin_time']) < 15)) {
					$rc = true;
				} 
				break;

			case "down":
				if (($running) && ((time() - $run_status[$ud_dev]['spin_time']) < 15)) {
					$rc = true;
				}
				break;

			default:
				break;
		}

		/* See if we need to update the run spin status. */
		if ((! $rc) && ($run_status[$ud_dev]['spin'])) {
			$run_status[$ud_dev]['spin']		= "";
			$run_status[$ud_dev]['spin_time']	= 0;
			MiscUD::save_json($tc, $run_status);
		}
	}

	return $rc;
}

/* Check to see if a remote server is online by chccking the ping status. */
function is_samba_server_online($ip) {
	global $paths;

	$is_alive		= false;
	$server			= $ip;
	$tc				= $paths['ping_status'];

	/* Get the updated ping status. */
	$ping_status	= MiscUD::get_json($tc);
	if (isset($ping_status[$server])) {
		$is_alive = ($ping_status[$server]['online'] == 'yes') ? true : false;
	}

	return $is_alive;
}

/* Check to see if a mount/unmount device or user script is running. */
function is_script_running($cmd, $user = false) {
	global $paths;

	$is_running = false;

	/* Check for a command file. */
	if ($cmd) {
		$script_name	= $cmd;
		$tc				= $paths['script_run'];
		$script_run		= MiscUD::get_json($tc);

		/* Check to see if the script was running. */
		if (isset($script_run[$script_name])) {
			$was_running = ($script_run[$script_name]['running'] == 'yes') ? true : false;
		} else {
			$was_running = false;
		}

		/* Set up for ps to find the right script. */
		if ($user) {
			$path_info	= pathinfo($cmd);
			$cmd		= $path_info['dirname'];
			$source		= "user.scripts";
		} else {
			$source		= "unassigned.devices";
		}

		/* Check if the script is currently running. */
		$is_running = shell_exec("/usr/bin/ps -ef | /bin/grep ".escapeshellarg(basename($cmd))." | /bin/grep -v 'grep' | /bin/grep ".escapeshellarg($source)) != "" ? true : false;
		$script_run[$script_name] = array('running' => $is_running ? 'yes' : 'no','user' => $user ? 'yes' : 'no');

		/* Update the current running state. */
		MiscUD::save_json($tc, $script_run);
		if (($was_running) && (! $is_running)) {
			publish();
		}
	}

	return $is_running;
}

/* Get disk temperature. */
function get_temp($ud_dev, $dev, $running) {
	global $var, $paths;

	$rc		= "*";
	$temp	= "";
	$sf		= $paths['dev_state'];
	$device	= basename($dev);

	/* Get temperature from the devs.ini file. */
	if (is_file($sf)) {
		$devs = @parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$temp	= $devs[$ud_dev]['temp'];
			$rc		= $temp;
		}
	}

	/* If devs.ini does not exist, then query the disk for the temperature. */
	if (($running) && (! $temp)) {
		$tc		= $paths['hdd_temp'];
		$temps	= MiscUD::get_json($tc);
		if (isset($temps[$device]) && ((time() - $temps[$device]['timestamp']) < $var['poll_attributes']) ) {
			$rc = $temps[$device]['temp'];
		} else {
			$temp	= trim(timed_exec(10, "/usr/sbin/smartctl -n standby -A ".escapeshellarg($dev)." | /bin/awk 'BEGIN{t=\"*\"} $1==\"Temperature:\"{t=$2;exit};$1==190||$1==194{t=$10;exit} END{print t}'"));
			$temp	= ($temp < 128) ? $temp : "*";
			$temps[$device] = array('timestamp' => time(), 'temp' => $temp);
			MiscUD::save_json($tc, $temps);
			$rc		= $temp;
		}
	}

	return $rc;
}

/* Get the format command based on file system to be formatted. */
function get_format_cmd($dev, $fs) {
	switch ($fs) {
		case 'xfs':
		case 'xfs-encrypted';
			$rc = "/sbin/mkfs.xfs -f ".escapeshellarg($dev)." 2>&1";
			break;

		case 'ntfs':
			$rc = "/sbin/mkfs.ntfs -Q ".escapeshellarg($dev)." 2>&1";
			break;

		case 'btrfs':
		case 'btrfs-encrypted';
			$rc = "/sbin/mkfs.btrfs -f ".escapeshellarg($dev)." 2>&1";
			break;

		case 'exfat':
			$rc = "/usr/sbin/mkfs.exfat ".escapeshellarg($dev)." 2>&1";
			break;

		case 'fat32':
			$rc = "/sbin/mkfs.fat -s 8 -F 32 ".escapeshellarg($dev)." 2>&1";
			break;

		default:
			$rc = false;
			break;
	}

	return $rc;
}

/* Format a disk. */
function format_disk($dev, $fs, $pass) {
	global $paths;

	unassigned_log("Format device '".$dev."'.");

	$rc	= true;

	/* Make sure it doesn't have any partitions. */
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev && count($d['partitions'])) {
			unassigned_log("Aborting format: disk '".$dev."' has '".count($d['partitions'])."' partition(s).");
			$rc = false;
		}
	}

	if ($rc) {
		/* Get the disk blocks and set either gpt or mbr partition schema based on disk size. */
		$max_mbr_blocks = hexdec("0xFFFFFFFF");
		$disk_blocks	= intval(trim(shell_exec("/sbin/blockdev --getsz ".escapeshellarg($dev)." | /bin/awk '{ print $1 }' 2>/dev/null")));
		$disk_schema	= ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
		$parted_fs		= ($fs == 'exfat') ? "fat32" : $fs;

		unassigned_log("Device '".$dev."' block size: ".$disk_blocks.".");

		/* Clear the partition table. */
		unassigned_log("Clearing partition table of disk '".$dev."'.");
		$o = trim(shell_exec("/usr/bin/dd if=/dev/zero of=".escapeshellarg($dev)." bs=2M count=1 2>&1"));
		if ($o) {
			unassigned_log("Clear partition result:\n".$o);
		}

		/* Reload the partition table. */
		unassigned_log("Reloading disk '".$dev."' partition table.");
		$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
		if ($o) {
			unassigned_log("Reload partition table result:\n".$o);
		}

		/* Get partition designation based on type of device. */
		if (MiscUD::is_device_nvme($dev)) {
			$device	= $dev."p1";
		} else {
			$device	= $dev."1";
		}

		/* Create partition for xfs, or btrfs. Partitions are Unraid compatible. */
		if ($fs == "xfs" || $fs == "xfs-encrypted" || $fs == "btrfs" || $fs == "btrfs-encrypted") {
			$is_ssd = is_disk_ssd($dev);
			if ($disk_schema == "gpt") {
				unassigned_log("Creating Unraid compatible gpt partition on disk '".$dev."'.");
				shell_exec("/sbin/sgdisk -Z ".escapeshellarg($dev));

				/* Alignment is 4Kb for spinners and 1Mb for SSD. */
				$alignment = $is_ssd ? "" : "-a 8";
				$o = shell_exec("/sbin/sgdisk -o ".$alignment." -n 1:32K:0 ".escapeshellarg($dev));
				if ($o) {
					unassigned_log("Create gpt partition table result:\n".$o);
				}
			} else {
				unassigned_log("Creating Unraid compatible mbr partition on disk '".$dev."'.");

				/* Alignment is 4Kb for spinners and 1Mb for SSD. */
				$start_sector = $is_ssd ? "2048" : "64";
				$o = shell_exec("/usr/local/sbin/mkmbr.sh ".escapeshellarg($dev)." ".escapeshellarg($start_sector));
				if ($o) {
					unassigned_log("Create mbr partition table result:\n".$o);
				}
			}

			/* Reload the partition table. */
			unassigned_log("Reloading disk ".escapeshellarg($dev)." partition table.");
			$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
			if ($o) {
				unassigned_log("Reload partition table result:\n".$o);
			}
		} else {
			/* If the file system is fat32, the disk_schema is msdos. */
			$disk_schema = ($fs == "fat32") ? "msdos" : "gpt";

			/* All other file system partitions are gpt, except fat32. */
			unassigned_log("Creating a '{$disk_schema}' partition table on disk '".$dev."'.");
			$o = trim(shell_exec("/usr/sbin/parted ".escapeshellarg($dev)." --script -- mklabel ".escapeshellarg($disk_schema)." 2>&1"));
			if ($o) {
				unassigned_log("Create '{$disk_schema}' partition table result:\n".$o);
			}

			/* Create an optimal disk partition. */
			$o = trim(shell_exec("/usr/sbin/parted -a optimal ".escapeshellarg($dev)." --script -- mkpart primary ".escapeshellarg($parted_fs)." 0% 100% 2>&1"));
			if ($o) {
				unassigned_log("Create primary partition result:\n".$o);
			}
		}

		unassigned_log("Formatting disk '".$dev."' with '".$fs."' filesystem.");

		/* Format the disk. */
		if (strpos($fs, "-encrypted") !== false) {
			/* nvme partition designations are 'p1', not '1'. */
			if (MiscUD::is_device_nvme($dev)) {
				$cmd = "luksFormat {$dev}p1";
			} else {
				$cmd = "luksFormat {$dev}1";
			}

			/* Use a disk password, or Unraid's. */
			if (! $pass) {
				$o				= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
			} else {
				$luks			= basename($dev);
				$luks_pass_file	= "{$paths['luks_pass']}_".$luks;
				@file_put_contents($luks_pass_file, $pass);
				$o				= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
				exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
			}

			if ($o) {
				unassigned_log("luksFormat error: ".$o);
				$rc = false;
			} else {
				$mapper = "format_".basename($dev);
				$cmd	= "luksOpen ".escapeshellarg($device)." ".escapeshellarg($mapper);

				/* Use a disk password, or Unraid's. */
				if (! $pass) {
					$o = exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
				} else {
					$luks			= basename($dev);
					$luks_pass_file	= "{$paths['luks_pass']}_".$luks;
					@file_put_contents($luks_pass_file, $pass);
					$o				= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
					exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
				}

				if ($o && stripos($o, "warning") === false) {
					unassigned_log("luksOpen result: ".$o);
					$rc = false;
				} else {
					exec(get_format_cmd("/dev/mapper/{$mapper}", $fs),escapeshellarg($out), escapeshellarg($return));
					sleep(3);
					shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
				}
			}
		} else {
			/* Format the disk. */
			exec(get_format_cmd($device, $fs), escapeshellarg($out), escapeshellarg($return));
		}

		/* Finish up the format. */
		if ($rc) {
			if ($return)
			{
				unassigned_log("Format disk '".$dev."' with '".$fs."' filesystem failed:\n".implode(PHP_EOL, $out));
				$rc = false;
			} else {
				if ($out) {
					unassigned_log("Format disk '".$dev."' with '".$fs."' filesystem:\n".implode(PHP_EOL, $out));
				}

				/* Let things settle a bit. */
				sleep(3);

				unassigned_log("Reloading disk '".$dev."' partition table.");

				/* Reload partition table. */
				$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
				if ($o) {
					unassigned_log("Reload partition table result:\n".$o);
				}

				/* Clear the $pass variable. */
				unset($pass);

				/* Let things settle a bit. */
				sleep(3);

				/* Refresh partition information. */
				exec("/usr/sbin/partprobe ".escapeshellarg($dev));
			}
		}
	}

	return $rc;
}

/* Remove a disk partition. */
function remove_partition($dev, $part) {

	$rc = true;

	/* Be sure there are no mounted partitions. */
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			foreach ($d['partitions'] as $p) {
				if ($p['part'] == $part && $p['target']) {
					unassigned_log("Aborting removal: partition '".$part."' is mounted.");
					$rc = false;
				}
			}
		}
	}

	if ($rc) {
		unassigned_log("Removing partition '".$part."' from disk '".$dev."'.");

		/* Remove the partition. */
		$out = shell_exec("/usr/sbin/parted ".escapeshellarg($dev)." --script -- rm ".escapeshellarg($part)." 2>&1");
		if ($out) {
			unassigned_log("Remove partition failed: '{$out}'.");
			$rc = false;
		} else {
			/* Refresh partition information. */
			exec("/usr/sbin/partprobe ".escapeshellarg($dev));
		}
	}

	return $rc;
}

/* Remove all disk partitions. */
function remove_all_partitions($dev) {
	global $paths;

	$rc = true;

	/* Be sure there are no mounted partitions. */
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			foreach ($d['partitions'] as $p) {
				if ($p['target']) {
					unassigned_log("Aborting clear: partition '".$p['part']."' is mounted.");
					$rc = false;
				}
			}
		}
	}

	if ($rc) {
		$device	= MiscUD::base_device($dev);

		unassigned_log("Removing all partitions from disk '".$device."'.");

		/* Remove all partitions - this clears the disk. */
		shell_exec("/sbin/wipefs -a ".escapeshellarg($device)." 2>&1");
		sleep(0.5);
		shell_exec("/sbin/sgdisk -Z ".escapeshellarg($device)." 2>&1");

		unassigned_log("Remove all Disk partitions initiated a Hotplug event.", $GLOBALS['UDEV_DEBUG']);

		/* Set flag to tell Unraid to update devs.ini file of unassigned devices. */
		sleep(1);
		@file_put_contents($paths['hotplug_event'], "");
	}

	return $rc;
}

/* Procedure to determine the time a command takes to run.  Mostly for debug purposes. */
function benchmark() {
	$params		= func_get_args();
	$function	= $params[0];
	array_shift($params);
	$time		= -microtime(true); 
	$out		= call_user_func_array($function, $params);
	$time	   += microtime(true); 
	$type		= ($time > 10) ? 0 : 1;
	unassigned_log("benchmark: $function(".implode(",", $params).") took ".sprintf('%f', $time)."s.", $type);

	return $out;
}

/* Run a command and time out if it takes too long. */
function timed_exec($timeout = 10, $cmd) {
	$time		= -microtime(true); 
	$out		= shell_exec("/usr/bin/timeout ".escapeshellarg($timeout)." ".$cmd);
	$time		+= microtime(true);
	if ($time > $timeout) {
		unassigned_log("Error: shell_exec(".$cmd.") took longer than ".sprintf('%d', $timeout)."s!");
		$out	= "command timed out";
	} else {
		unassigned_log("Timed Exec: shell_exec(".$cmd.") took ".sprintf('%f', $time)."s!", $GLOBALS['CMD_DEBUG']);
	}

	return $out;
}

/* Find the file system type of a luks device. */
function luks_fs_type($dev) {


	$rc = "luks";
	if ($dev) {
		$return	= shell_exec("/bin/cat /proc/mounts | /bin/grep -w ".escapeshellarg($dev)." | /bin/awk '{print $3}'");
		if ($return) {
			$return	= explode("\n", $return);
			$rc		= $return[0];
		}
	}

	return $rc;
}

#########################################################
############		CONFIG FUNCTIONS		#############
#########################################################

/* Get device configuration parameter. */
function get_config($serial, $variable) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	return (isset($config[$serial][$variable])) ? html_entity_decode($config[$serial][$variable], ENT_COMPAT) : false;
}

/* Set device configuration parameter. */
function set_config($serial, $variable, $value) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	$config[$serial][$variable] = htmlentities($value, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$serial][$variable])) ? $config[$serial][$variable] : false;
}

/* Is device set to auto mount? */
function is_automount($serial, $usb = false) {
	$auto			= get_config($serial, "automount");
	$auto_usb		= get_config("Config", "automount_usb");
	$pass_through	= get_config($serial, "pass_through");
	return ( (($pass_through != "yes") && (($auto == "yes") || ($usb && $auto_usb == "yes"))) ) ? true : false;
}

/* Is device set to mount read only? */
function is_read_only($serial) {
	$read_only		= get_config($serial, "read_only");
	$pass_through	= get_config($serial, "pass_through");
	return ( $pass_through != "yes" && $read_only == "yes" ) ? true : false;
}

/* Is device set to pass through. */
function is_pass_through($serial) {
	return (get_config($serial, "pass_through") == "yes") ? true : false;
}

/* Is device set to pass through. */
function is_disable_mount($serial) {
	return (get_config($serial, "disable_mount") == "yes") ? true : false;
}

/* Toggle auto mount on/off. */
function toggle_automount($serial, $status) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	$config[$serial]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$serial]["automount"] == "yes") ? 'true' : 'false';
}

/* Toggle read only on/off. */
function toggle_read_only($serial, $status) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	$config[$serial]["read_only"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$serial]["read_only"] == "yes") ? 'true' : 'false';
}

/* Toggle pass through on/off. */
function toggle_pass_through($serial, $status) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	$config[$serial]["pass_through"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$serial]["pass_through"] == "yes") ? 'true' : 'false';
}

/* Toggle hide mount on/off. */
function toggle_disable_mount($serial, $status) {
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	$config[$serial]["disable_mount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$serial]["disable_mount"] == "yes") ? 'true' : 'false';
}

/* Execute the device script. */
function execute_script($info, $action, $testing = false) { 
	global $paths;

	$rc = false;

	/* Set environment variables. */
	putenv("ACTION={$action}");
	foreach ($info as $key => $value) {
		/* Only set the environment variables used by the scripts. */
		switch ($key) {
			case 'device':
			case 'serial':
			case 'label':
			case 'fstype':
			case 'mountpoint':
			case 'owner':
			case 'prog_name':
			case 'logfile':
			case 'luks':
				putenv(strtoupper($key)."="."{$value}");
			break;
		}
	}

	/* Execute the common script if it is defined. */
	if ($common_cmd = escapeshellcmd(get_config("Config", "common_cmd"))) {
		$common_script = $paths['scripts'].basename($common_cmd);
		copy($common_cmd, $common_script);
		@chmod($common_script, 0755);
		unassigned_log("Running common script: '".basename($common_script)."'");
		exec($common_script, escapeshellarg($out), escapeshellarg($return));
		if ($return) {
			unassigned_log("Error: common script failed: '{$return}'");
		}
	}

	/* If there is a command, execute the script. */
	$cmd	= escapeshellcmd($info['command']);
	$bg		= (($info['command_bg'] != "false") && ($action == "ADD")) ? "&" : "";
	if ($cmd) {
		$command_script = $paths['scripts'].basename($cmd);
		copy($cmd, $command_script);
		@chmod($command_script, 0755);
		unassigned_log("Running device script: '".basename($cmd)."' with action '{$action}'.");

		$script_running = is_script_running($cmd);
		if ((! $script_running) || (($script_running) && ($action != "ADD"))) {
			if (! $testing) {
				if (($action == "REMOVE") || ($action == "ERROR_MOUNT") || ($action == "ERROR_UNMOUNT")) {
					sleep(1);
				}
				$clear_log	= ($action == "ADD") ? " > " : " >> ";
				$cmd		= $command_script.$clear_log.$info['logfile']." 2>&1 $bg";

				/* Run the script. */
				exec($cmd, escapeshellarg($out), escapeshellarg($return));
				if ($return) {
					unassigned_log("Error: device script failed: '{$return}'");
				}
			} else {
				$rc			= $command_script;
			}
		} else {
			unassigned_log("Device script '".basename($cmd)."' aleady running!");
		}
	}

	return $rc;
}

/* Remove a historical disk configuration. */
function remove_config_disk($serial) {

	/* Get the all disk configurations. */
	$config_file	= $GLOBALS["paths"]["config_file"];
	$config			= @parse_ini_file($config_file, true);
	if ( isset($config[$serial]) ) {
		unassigned_log("Removing configuration '{$serial}'.");
	}
	/* Remove up to five partition script files. */
	for ($i = 1; $i <= 5; $i++) {
		$command	= "command.".$i;
		$cmd		= $config[$serial][$command];
		if ( isset($cmd) && is_file($cmd) ) {
			@unlink($cmd);
			unassigned_log("Removing script file '{$cmd}'.");
		}
	}

	/* Remove this configuration. */
	unset($config[$serial]);

	/* Resave all disk configurations. */
	save_ini_file($config_file, $config);
	return (! isset($config[$serial])) ? true : false;
}

/* Is disk device an SSD? */
function is_disk_ssd($dev) {

	$rc		= false;

	/* Get the base device - remove the partition number. */
	$device	= MiscUD::base_device(basename($dev));
	if (! MiscUD::is_device_nvme($device)) {
		$file = "/sys/block/".basename($device)."/queue/rotational";
		$rc = (exec("/bin/cat {$file} 2>/dev/null") == 0) ? true : false;
	} else {
		$rc = true;
	}

	return $rc;
}

#########################################################
############		MOUNT FUNCTIONS			#############
#########################################################

/* Is a device mounted? */
function is_mounted($dev) {

	$rc = false;
	if ($dev) {
		$data	= timed_exec(1, "/usr/bin/cat /proc/mounts | awk '{print $1 \",\" $2}'");
		$data	= str_replace("\\040", " ", $data);
		$data	= str_replace("\n", ",", $data);

		$rc		= (strpos($data, $dev.",") !== false) ? true : false;
	}

	return $rc;
}

/* Is a device mounted read only? */
function is_mounted_read_only($dev) {

	$rc = false;
	if ($dev) {
		$data	= timed_exec(1, "/usr/bin/cat /proc/mounts | awk '{print $2 \",\" toupper(substr($4,0,2))}'");
		$data	= str_replace("\\040", " ", $data);
		$rc		= (strpos($data, $dev.",RO") !== false) ? true : false;
	}

	return $rc;
}

/* Get the mount parameters based on the file system. */
function get_mount_params($fs, $dev, $ro = false) {
	global $paths;

	$rc				= "";
	$config_file	= $paths['config_file'];
	$config			= @parse_ini_file($config_file, true);
	$discard 		= (($config['Config']['discard'] == "yes") && is_disk_ssd($dev)) ? ",discard" : "";
	$rw				= $ro ? "ro" : "rw";
	switch ($fs) {
		case 'hfsplus':
			$rc = "force,{$rw},users,umask=000";
			break;

		case 'xfs':
		case 'btrfs':
		case 'crypto_LUKS':
			$rc = "{$rw},noatime,nodiratime{$discard}";
			break;

		case 'exfat':
			$rc = "{$rw},noatime,nodiratime,nodev,nosuid,umask=000";
			break;

		case 'vfat':
			$rc = "{$rw},noatime,nodiratime,nodev,nosuid,iocharset=utf8,umask=000";
			break;

		case 'ntfs':
			$rc = "{$rw},noatime,nodiratime,nodev,nosuid,nls=utf8,umask=000";
			break;

		case 'ext4':
			$rc = "{$rw},noatime,nodiratime,nodev,nosuid{$discard}";
			break;

		case 'cifs':
			$credentials_file = "{$paths['credentials']}_".basename($dev);
			$rc = "rw,noserverino,nounix,iocharset=utf8,file_mode=0777,dir_mode=0777,uid=99,gid=100%s,credentials=".escapeshellarg($credentials_file);
			break;

		case 'nfs':
			$rc = "rw,noacl";
			break;

		case 'root':
			$rc = "rw --bind";
			break;

		default:
			$rc = "{$rw},noatime,nodiratime";
			break;
	}

	return $rc;
}

/* Mount a device. */
function do_mount($info) {
	global $var, $paths;

	$rc = false;
	/* Mount a CIFS or NFS remote mount. */
	if ($info['fstype'] == "cifs" || $info['fstype'] == "nfs") {
		$rc = do_mount_samba($info);

	/* Mount root share. */
	} else if ($info['fstype'] == "root") {
		$rc = do_mount_root($info);

	/* Mount an ISO file. */
	} else if ($info['fstype'] == "loop") {
		$rc = do_mount_iso($info);

	/* Mount a luks encrypted disk device. */
	} else if ($info['fstype'] == "crypto_LUKS") {
		if (! is_mounted($info['device']) && ! is_mounted($info['mountpoint'])) {
			$luks		= basename($info['device']);
			$discard	= is_disk_ssd($info['luks']) ? "--allow-discards" : "";
			$cmd		= "luksOpen $discard ".escapeshellarg($info['luks'])." ".escapeshellarg($luks);
			$pass		= decrypt_data(get_config($info['serial'], "pass"));
			if (! $pass) {
				if (file_exists($var['luksKeyfile'])) {
					unassigned_log("Using luksKeyfile to open the 'crypto_LUKS' device.");
					$o		= shell_exec("/sbin/cryptsetup ".escapeshellcmd($cmd)." -d ".escapeshellarg($var['luksKeyfile'])." 2>&1");
				} else {
					unassigned_log("Using Unraid api to open the 'crypto_LUKS' device.");
					$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
				}
			} else {
				$luks_pass_file = "{$paths['luks_pass']}_".$luks;
				@file_put_contents($luks_pass_file, $pass);
				unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/sbin/cryptsetup ".escapeshellcmd($cmd)." -d ".escapeshellarg($luks_pass_file)." 2>&1");
				exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
				unset($pass);
			}
			if ($o && stripos($o, "warning") === false) {
				unassigned_log("luksOpen result: {$o}");
				shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg(basename($info['device'])));
			} else {
				$rc = do_mount_local($info);
			}
		} else {
			unassigned_log("Partition '".basename($info['device'])."' is already mounted.");
		}

	/* Mount an unencrypted disk. */
	} else {
		$rc = do_mount_local($info);
	}

	return $rc;
}

/* Mount a disk device. */
function do_mount_local($info) {
	global $paths;

	$rc		= false;
	$dev	= $info['device'];
	$dir	= $info['mountpoint'];
	$fs		= $info['fstype'];
	$ro		= ($info['read_only'] == 'yes') ? true : false;
	if (! is_mounted($dev) && ! is_mounted($dir)) {
		if ($fs) {
			if ($fs != "crypto_LUKS") {
				if ($fs == "apfs") {
					/* See if there is a disk password. */
					$password = decrypt_data(get_config($info['serial'], "pass"));
					$recovery = "";
					if ($password) {
						$recovery = ",pass='".$password."'";
					}
					$vol = get_config($info['serial'], "volume.{$info['part']}");
					$vol = ($vol != 0) ? ",vol=".$vol : "";
					$cmd = "/usr/bin/apfs-fuse -o uid=99,gid=100,allow_other{$vol}{$recovery} ".escapeshellarg($dev)." ".escapeshellarg($dir);
				} else {
					$params	= get_mount_params($fs, $dev, $ro);
					$cmd = "/sbin/mount -t ".escapeshellarg($fs)." -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
				}
			} else {
				$device = $info['luks'];
				$params	= get_mount_params($fs, $device, $ro);
				$cmd = "/sbin/mount -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
			}
			$str = str_replace($recovery, ", pass='*****'", $cmd);
			unassigned_log("Mount drive command: {$str}");

			/* apfs file system requires UD+ to be installed. */
			if (($fs == "apfs") && (! is_file("/usr/bin/apfs-fuse"))) {
				$o = "Install Unassigned Devices Plus to mount an apfs file system";
			} else {
				/* Create mount point and set permissions. */
				@mkdir($dir, 0777, true);

				/* Do the mount command. */
				$o = shell_exec(escapeshellcmd($cmd)." 2>&1");
			}

			/* Do some cleanup if we mounted an apfs disk, */
			if ($fs == "apfs") {
				/* Remove all password variables. */
				unset($password);
				unset($recovery);
				unset($cmd);
			}

			/* Check to see if the device really mounted. */
			for ($i=0; $i < 5; $i++) {
				if (is_mounted($dir)) {
					if (! is_mounted_read_only($dir)) {
						exec("/bin/chmod 0777 {$dir} 2>/dev/null");
						exec("/bin/chown 99 {$dir} 2>/dev/null");
						exec("/bin/chgrp 100 {$dir} 2>/dev/null");
					}

					unassigned_log("Successfully mounted '".basename($dev)."' on '{$dir}'.");

					$rc = true;
					break;
				} else {
					sleep(0.5);
				}
			}

			/* If the device did not mount, close the luks disk and show error. */
			if (! $rc) {
				if ($fs == "crypto_LUKS" ) {
					shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg(basename($info['device'])));
				}
				unassigned_log("Mount of '".basename($dev)."' failed: '{$o}'");
				@rmdir($dir);
			} else {
				if ($info['fstype'] == "btrfs") {
					/* Update the btrfs state file for single scan for pool devices. */
					$pool_state			= MiscUD::get_json($paths['pool_state']);
					$pool_state[$dir]	= array();
					MiscUD::save_json($paths['pool_state'], $pool_state);
				}

				/* Ntfs is mounted but is most likely mounted r/o. Display the mount command warning. */
				if ($o && ($fs == "ntfs")) {
					unassigned_log("Mount warning: {$o}.");
				}
			}
		} else {
			unassigned_log("No filesystem detected on '".basename($dev)."'.");
		}
	} else {
		unassigned_log("Partition '".basename($dev)."' is already mounted.");
	}

	return $rc;
}

/* Mount root share. */
function do_mount_root($info) {
	global $paths, $var;

	$rc		= false;

	if ($var['shareDisk'] != "yes") {
		/* Be sure the server online status is current. */
		$is_alive = is_samba_server_online($info['ip']);

		/* If the remote server is not online, run the ping update and see if ping status needs to be refreshed. */
		if (! $is_alive) {
			/* Update the remote server ping status. */
			exec("/usr/local/emhttp/plugins/unassigned.devices/scripts/get_ud_stats ping");

			/* See if the server is online now. */
			$is_alive = is_samba_server_online($info['ip']);
		}
	
		if ($is_alive) {
			$dir		= $info['mountpoint'];
			$fs			= $info['fstype'];
			$dev		= str_replace("//".$info['ip'], "", $info['device']);
			if (! is_mounted($dir)) {
				/* Create the mount point and set permissions. */
				@mkdir($dir, 0777, true);

				$params	= get_mount_params($fs, $dev);
				$cmd	= "/sbin/mount -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

				unassigned_log("Mount ROOT command: {$cmd}");

				/* Mount the remote share. */
				$o		= timed_exec(10, $cmd." 2>&1");
				if ($o) {
					unassigned_log("Root mount failed: '{$o}'.");
				}

				/* Did the share successfully mount? */
				if (is_mounted($dir)) {
					@chmod($dir, 0777);
					@chown($dir, 99);
					@chgrp($dir, 100);

					unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

					$rc = true;
				} else {
					@rmdir($dir);
				}
			} else {
				unassigned_log("Root Share '{$dev}' is already mounted.");
			}
		} else {
			unassigned_log("Root Server '{$info['ip']}' is offline and share '{$info['device']}' cannot be mounted."); 
		}
	} else {
		unassigned_log("Error: Root Server share '{$info['device']}' cannot be mounted with Disk Sharing enabled."); 
	}

	return $rc;
}

/* Unmount a device. */
function do_unmount($dev, $dir, $force = false, $smb = false, $nfs = false) {
	global $paths;

	$rc = false;
	if ( is_mounted($dev) && is_mounted($dir) ) {
		if (! $force) {
			unassigned_log("Synching file system on '{$dir}'.");
			exec("/bin/sync -f ".escapeshellarg($dir));
		}

		/* Remove saved pool devices if this is a pooled device. */
		MiscUD::get_pool_devices($dir, true);

		$cmd = "/sbin/umount".($smb ? " -t cifs" : "").($force ? " -fl" : ($nfs ? " -l" : ""))." ".escapeshellarg($dev)." 2>&1";
		unassigned_log("Unmount cmd: {$cmd}");

		$timeout = ($smb || $nfs) ? ($force ? 30 : 10) : 90;
		$o = timed_exec($timeout, $cmd);

		/* Check to see if the device really unmounted. */
		for ($i=0; $i < 5; $i++) {
			if ((! is_mounted($dev)) && (! is_mounted($dir))) {
				if (is_dir($dir)) {
					@rmdir($dir);
					$link = $paths['usb_mountpoint']."/".basename($dir);
					if (is_link($link)) {
						@unlink($link);
					}
				}

				unassigned_log("Successfully unmounted '".basename($dev)."'");
				$rc = true;
				break;
			} else {
				sleep(0.5);
			}
		}
		if (! $rc) {
			unassigned_log("Unmount of '".basename($dev)."' failed: '{$o}'"); 
		}
	} else {
		unassigned_log("Cannot unmount '".basename($dev)."'. UD did not mount the device or it was not properly unmounted.");
	}

	return $rc;
}

#########################################################
############		SHARE FUNCTIONS			#############
#########################################################

/* Is the samba share on? */
function config_shared($serial, $part, $usb = false) {
	$share		= get_config($serial, "share.{$part}");
	$auto_usb	= get_config("Config", "automount_usb");
	return (($share == "yes") || ($usb && $auto_usb == "yes")) ? true : false; 
}

/* Toggle samba share on/off. */
function toggle_share($serial, $part, $status) {
	$new 		= ($status == "true") ? "yes" : "no";
	set_config($serial, "share.{$part}", $new);
	return ($new == 'yes') ? true : false;
}

/* Add mountpoint to samba shares. */
function add_smb_share($dir, $recycle_bin = true) {
	global $paths, $var, $users;

	/* Add mountpoint to samba shares. */
	if ( ($var['shareSMBEnabled'] != "no") ) {
		/* Remove special characters from share name. */
		$share_name = str_replace( array("(", ")"), "", basename($dir));
		$config = @parse_ini_file($paths['config_file'], true);
		$config = $config["Config"];

		$vfs_objects = "";
		$enable_fruit = get_config("Config", "mac_os");
		if (($recycle_bin) || ($enable_fruit == 'yes')) {
			$vfs_objects .= "\n\tvfs objects = ";
			if ($enable_fruit == 'yes') {
				$vfs_objects .= "catia fruit streams_xattr";
			}
		}
		$vfs_objects .= "";

		if (($config["smb_security"] == "yes") || ($config["smb_security"] == "hidden")) {
			$read_users = $write_users = $valid_users = array();
			foreach ($users as $key => $user) {
				if ($user['name'] != "root" ) {
					$valid_users[] = $user['name'];
				}
			}

			$invalid_users = array_filter($valid_users, function($v) use($config, &$read_users, &$write_users) { 
				if ($config["smb_{$v}"] == "read-only") {$read_users[] = $v;}
				else if ($config["smb_{$v}"] == "read-write") {$write_users[] = $v;}
				else {return $v;}
			});
			$valid_users = array_diff($valid_users, $invalid_users);
			if ($config["smb_security"] == "hidden") {
				$hidden = "\n\tbrowseable = no";
			} else {
				$hidden = "\n\tbrowseable = yes";
			}
			$force_user = ( get_config("Config", "force_user") != "no" ) ? "\n\tforce User = nobody" : "";
			if (($config["case_names"]) || ($config["case_names"] == "auto")) {
				$case_names = "\n\tcase sensitive = auto\n\tpreserve case = yes\n\tshort preserve case = yes";
			} else if ($config["case_names"] == "yes") {
				$case_names = "\n\tcase sensitive = yes\n\tpreserve case = yes\n\tshort preserve case = yes";
			} else if ($config["case_names"] == "force") {
				$case_names = "\n\tcase sensitive = yes\n\tpreserve case = no\n\tshort preserve case = no";
			} else {
				$case_names = "";
			}
			if (count($valid_users)) {
				$valid_users	= "\n\tvalid users = ".implode(', ', $valid_users);
				$write_users	= count($write_users) ? "\n\twrite list = ".implode(', ', $write_users) : "";
				$read_users		= count($read_users) ? "\n\tread list = ".implode(', ', $read_users) : "";
				$share_cont		= "[{$share_name}]\n\tpath = {$dir}{$hidden}{$force_user}{$valid_users}{$write_users}{$read_users}{$vfs_objects}{$case_names}";
			} else {
				$share_cont 	= "[{$share_name}]\n\tpath = {$dir}{$hidden}\n\tinvalid users = @users";
				unassigned_log("Error: No valid smb users defined. Share '{$dir}' cannot be accessed.");
			}
		} else {
			$share_cont = "[{$share_name}]\n\tpath = {$dir}\n\tread only = No{$force_user}\n\tguest ok = Yes{$vfs_objects}";
		}

		if (! is_dir($paths['smb_usb_shares'])) {
			@mkdir($paths['smb_usb_shares'], 0755, true);
		}
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");

		unassigned_log("Adding SMB share '{$share_name}'.");
		@file_put_contents($share_conf, $share_cont);
		if (! MiscUD::exist_in_file($paths['smb_extra'], $share_conf)) {
			$c		= (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			$c[]	= "";
			$c[]	= "include = $share_conf";

			/* Do some cleanup. */
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) {
				if (! is_file(parse_ini_string($inc)['include'])) {
					unset($smb_extra_includes[$key]);
				}
			} 
			$c		= array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c		= preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			@file_put_contents($paths['smb_extra'], $c);

			/* If the recycle bin plugin is installed, add the recycle bin to the share. */
			if ($recycle_bin) {
				/* Add the recycle bin parameters if plugin is installed */
				$recycle_script = "plugins/recycle.bin/scripts/configure_recycle_bin";
				if (is_file($recycle_script)) {
					$recycle_bin_cfg = @parse_ini_file( "/boot/config/plugins/recycle.bin/recycle.bin.cfg" );
					if ($recycle_bin_cfg['INCLUDE_UD'] == "yes") {
						unassigned_log("Enabling the Recycle Bin on share '{$share_name}'.");
						shell_exec(escapeshellcmd("$recycle_script $share_conf"));
					}
				}
			} else {
				@file_put_contents($share_conf, "\n", FILE_APPEND);
			}
		}

		timed_exec(5, "$(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
	}

	return true;
}

/* Remove mountpoint from samba shares. */
function rm_smb_share($dir) {
	global $paths, $var;

	/* Remove special characters from share name */
	$share_name = str_replace( array("(", ")"), "", basename($dir));
	$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
	if (is_file($share_conf)) {
		unassigned_log("Removing SMB share '{$share_name}'");
		@unlink($share_conf);
	}
	if (MiscUD::exist_in_file($paths['smb_extra'], $share_conf)) {
		$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();

		/* Do some cleanup. */
		$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
		foreach($smb_extra_includes as $key => $inc) {
			if (! is_file(parse_ini_string($inc)['include'])) {
				unset($smb_extra_includes[$key]);
			}
		} 
		$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
		$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
		@file_put_contents($paths['smb_extra'], $c);
		timed_exec(5, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) close-share ".escapeshellarg($share_name)." 2>&1");
		timed_exec(5, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
	}

	return true;
}

/* Add a mountpoint to NFS shares. */
function add_nfs_share($dir) {
	global $var;

	/* If NFS is enabled and export setting is 'yes' then add NFS share. */
	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = false;
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if (! MiscUD::exist_in_file($file, "\"{$dir}\"")) {
				$c			= (is_file($file)) ? @file($file, FILE_IGNORE_NEW_LINES) : array();
				$fsid		= 200 + count(preg_grep("@^\"@", $c));
				$nfs_sec	= get_config("Config", "nfs_security");
				$sec		= "";
				if ( $nfs_sec == "private" ) {
					$sec	= get_config("Config", "nfs_rule");
				} else {
					$sec	= "*(sec=sys,rw,insecure,anongid=100,anonuid=99,all_squash)";
				}
				$c[]		= "\"{$dir}\" -async,no_subtree_check,fsid={$fsid} {$sec}";
				$c[]		= "";
				@file_put_contents($file, implode(PHP_EOL, $c));
				$reload		= true;
			}
		}
		if ($reload) {
			unassigned_log("Adding NFS share '{$dir}'.");
			shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
		}
	}

	return true;
}

/* Remove a mountpoint from NFS shares. */
function rm_nfs_share($dir) {
	global $var;

	/* Remove this disk from the exports file. */
	$reload = false;
	foreach (array("/etc/exports","/etc/exports-") as $file) {
		if ( MiscUD::exist_in_file($file, "\"{$dir}\"") && strlen($dir)) {
			$c		= (is_file($file)) ? @file($file, FILE_IGNORE_NEW_LINES) : array();
			$c		= preg_grep("@\"{$dir}\"@i", $c, PREG_GREP_INVERT);
			$c[]	= "";
			@file_put_contents($file, implode(PHP_EOL, $c));
			$reload	= true;
		}
	}
	if ($reload) {
		unassigned_log("Removing NFS share '{$dir}'.");
		shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}

	return true;
}

/* Remove all samba and NFS shares for mounted devices. */
function remove_shares() {
	/* Disk mounts */
	foreach (get_unassigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			$info = get_partition_info($p);
			if ( $info['mounted'] ) {
				$device = $disk['device'];
				if ($info['shared']) {
					rm_smb_share($info['target']);
					rm_nfs_share($info['target']);
				}
			}
		}
	}

	/* SMB Mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			rm_smb_share($info['mountpoint']);
		}
	}

	/* ISO File Mounts */
	foreach (get_iso_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
		}
	}
}

/* Reload samba and NFS shares. */
function reload_shares() {
	/* Disk mounts */
	foreach (get_unassigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			$info = get_partition_info($p);
			if ( $info['mounted'] ) {
				$device = $disk['device'];
				if ($info['shared']) {
					add_smb_share($info['mountpoint']);
					add_nfs_share($info['mountpoint']);
				}
			}
		}
	}

	/* SMB Mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			add_smb_share($info['mountpoint'], $info['fstype'] == "root" ? true : false);
		}
	}

	/* ISO File Mounts */
	foreach (get_iso_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			add_smb_share($info['mountpoint'], false);
			add_nfs_share($info['mountpoint']);
		}
	}
}

#########################################################
############		SAMBA FUNCTIONS			#############
#########################################################

/* Get samba mount configuration parameter. */
function get_samba_config($source, $variable) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config 		= @parse_ini_file($config_file, true, INI_SCANNER_RAW);
	return (isset($config[$source][$variable])) ? $config[$source][$variable] : false;
}

/* Set samba mount configuration parameter. */
function set_samba_config($source, $variable, $value) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$source][$variable] = $value;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$variable])) ? $config[$source][$variable] : false;
}

/* Encrypt passwords. */
function encrypt_data($data) {
	$key	= get_config("Config", "key");
	if ((! $key) || strlen($key) != 32) {
		$key = substr(base64_encode(openssl_random_pseudo_bytes(32)), 0, 32);
		set_config("Config", "key", $key);
	}
	$iv		= get_config("Config", "iv");
	if ((! $iv) || strlen($iv) != 16) {
		$iv = substr(base64_encode(openssl_random_pseudo_bytes(16)), 0, 16);
		set_config("Config", "iv", $iv);
	}

	$value	= openssl_encrypt($data, 'aes256', $key, $options=0, $iv);
	$value	= str_replace("\n", "", $value);

	return $value;
}

/* Decrypt password. */
function decrypt_data($data) {

	$key	= get_config("Config", "key");
	$iv		= get_config("Config", "iv");
	$value	= openssl_decrypt($data, 'aes256', $key, $options=0, $iv);

	/* Make sure the password is UTF-8 encoded. */
	if (! preg_match("//u", $value)) {
		unassigned_log("Warning: Password is not UTF-8 encoded");
		$value = "";
	}

	return $value;
}

/* Is the samba mount set for auto mount? */
function is_samba_automount($serial) {
	$auto	= get_samba_config($serial, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? true : false ) : false);
}

/* Is the samba mount set to share? */
function is_samba_share($serial) {
	$smb_share	= get_samba_config($serial, "smb_share");
	return ( ($smb_share) ? ( ($smb_share == "yes") ? true : false ) : true);
}

/* Is device set to pass through. */
function is_samba_disable_mount($serial) {
	$disable_mount	= get_samba_config($serial, "disable_mount");
	return ($disable_mount == "yes") ? true : false;
}

/* Get all defined samba and NFS remote shares. */
function get_samba_mounts() {
	global $paths;

	$o = array();
	$config_file	= $paths['samba_mount'];
	$samba_mounts	= @parse_ini_file($config_file, true);
	if (is_array($samba_mounts)) {
		ksort($samba_mounts, SORT_NATURAL);
		foreach ($samba_mounts as $device => $mount) {
			$mount['device']	= $device;
			$mount['name']		= $device;

			/* Set the mount protocol. */
			if ($mount['protocol'] == "NFS") {
				$mount['fstype'] = "nfs";
				$path = basename($mount['path']);
			} else if ($mount['protocol'] == "ROOT") {
				$mount['fstype'] = "root";
				$root_type = basename($mount['device']) == "user" ? "user-pool" : "user";
				$path = $mount['mountpoint'] ? $mount['mountpoint'] : $root_type.".".$mount['path'];
			} else {
				$mount['fstype'] = "cifs";
				$path = $mount['path'];
			}

			if ($mount['fstype'] != "root") {
				$mount['mounted']		= is_mounted(($mount['fstype'] == "cifs") ? "//".$mount['ip']."/".$path : $mount['device']);
			} else {
				$mount['mounted']		= is_mounted($paths['root_mountpoint']."/".$path);
			}
			$mount['is_alive']		= is_samba_server_online($mount['ip']);
			$mount['automount']		= is_samba_automount($mount['name']);
			$mount['smb_share']		= is_samba_share($mount['name']);
			if (! $mount['mountpoint']) {
				$mount['mountpoint'] = "{$paths['usb_mountpoint']}/{$mount['ip']}_{$path}";
				if (! $mount['mounted'] || ! is_mounted($mount['mountpoint']) || is_link($mount['mountpoint'])) {
					if ($mount['fstype'] != "root") {
						$mount['mountpoint'] = "{$paths['remote_mountpoint']}/{$mount['ip']}_{$path}";
					} else {
						$mount['mountpoint'] = "{$paths['root_mountpoint']}/{$path}";
					}
				}
			} else {
				$path = basename($mount['mountpoint']);
				$mount['mountpoint'] = "{$paths['usb_mountpoint']}/{$path}";
				if (! $mount['mounted'] || ! is_mounted($mount['mountpoint']) || is_link($mount['mountpoint'])) {
					if ($mount['fstype'] != "root") {
						$mount['mountpoint'] = "{$paths['remote_mountpoint']}/{$path}";
					} else {
						$mount['mountpoint'] = "{$paths['root_mountpoint']}/{$path}";
					}
				}
			}

			/* Get the disk size, used, and free stats. */
			$stats					= get_device_stats($mount['mountpoint'], $mount['mounted'], $mount['is_alive']);
			$mount['size']			= intval($stats[0])*1024;
			$mount['used']			= intval($stats[1])*1024;
			$mount['avail']			= intval($stats[2])*1024;

			/* Target is set to the mount point when the device is mounted. */
			$mount['target']		= $mount['mounted'] ? $mount['mountpoint'] : "";

			$mount['command']		= get_samba_config($mount['device'],"command");
			$mount['prog_name']		= basename($mount['command'], ".sh");
			$mount['user_command']	= get_samba_config($mount['device'],"user_command");
			$mount['logfile']		= ($mount['prog_name']) ? $paths['device_log'].$mount['prog_name'].".log" : "";
			$o[] = $mount;
		}
	} else {
		unassigned_log("Error: unable to get the samba mounts.");
	}

	return $o;
}

/* Mount a remote samba or NFS share. */
function do_mount_samba($info) {
	global $paths, $var, $version;

	$rc				= false;
	$config_file	= $paths['config_file'];
	$config			= @parse_ini_file($config_file, true);

	/* Be sure the server online status is current. */
	$is_alive = is_samba_server_online($info['ip']);

	/* If the remote server is not online, run the ping update and see if ping status needs to be refreshed. */
	if (! $is_alive) {
		/* Update the remote server ping status. */
		exec("/usr/local/emhttp/plugins/unassigned.devices/scripts/get_ud_stats ping");

		/* See if the server is online now. */
		$is_alive = is_samba_server_online($info['ip']);
	}
	
	if ($is_alive) {
		$dir		= $info['mountpoint'];
		$fs			= $info['fstype'];
		$dev		= ($fs == "cifs") ? "//".$info['ip']."/".$info['path'] : $info['device'];
		if (! is_mounted($dev) && ! is_mounted($dir)) {
			/* Create the mount point and set permissions. */
			@mkdir($dir, 0777, true);

			if ($fs == "nfs") {
				if ($var['shareNFSEnabled'] == "yes") {
					$params	= get_mount_params($fs, $dev);
					if (version_compare($version['version'],"6.9.9", ">")) {
						$nfs	= (get_config("Config", "nfs_version") == "4") ? "nfs4" : "nfs";
					} else {
						$nfs	= "nfs";
					}
					$cmd	= "/sbin/mount -t ".escapeshellarg($nfs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount NFS command: {$cmd}");

					/* Mount the remote share. */
					$o		= timed_exec(10, $cmd." 2>&1");
					if ($o) {
						unassigned_log("NFS mount failed: '{$o}'.");
					}
				} else {
					unassigned_log("NFS must be enabled in 'Settings->NFS' to mount NFS remote shares.");
				}
			} else if ($var['shareSMBEnabled'] != "no") {
				/* Create the credentials file. */
				$credentials_file = "{$paths['credentials']}_".basename($dev);
				@file_put_contents("$credentials_file", "username=".($info['user'] ? $info['user'] : 'guest')."\n");
				@file_put_contents("$credentials_file", "password=".decrypt_data($info['pass'])."\n", FILE_APPEND);
				@file_put_contents("$credentials_file", "domain=".$info['domain']."\n", FILE_APPEND);

				/* If the smb version is not required, just mount the remote share with no version. */
				$smb_version = (get_config("Config", "smb_version") == "yes") ? true : false;
				if (! $smb_version) {
					$ver	= "";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '{$dev}' using SMB default protocol.");
					unassigned_log("Mount SMB command: {$cmd}");

					/* Mount the remote share. */
					$o		= timed_exec(10, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 3.1.1. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					if (! $smb_version) {
						unassigned_log("SMB default protocol mount failed: '{$o}'.");
					}
					$ver	= ",vers=3.1.1";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t $fs -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '{$dev}' using SMB 3.1.1 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");

					/* Mount the remote share. */
					$o		= timed_exec(10, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 3.0. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					unassigned_log("SMB 3.1.1 mount failed: '{$o}'.");
					/* If the mount failed, try to mount with samba vers=3.0. */
					$ver	= ",vers=3.0";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t $fs -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '{$dev}' using SMB 3.0 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");

					/* Mount the remote share. */
					$o		= timed_exec(10, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 2.0. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					unassigned_log("SMB 3.0 mount failed: '{$o}'.");
					/* If the mount failed, try to mount with samba vers=2.0. */
					$ver	= ",vers=2.0";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '{$dev}' using SMB 2.0 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");

					/* Mount the remote share. */
					$o		= timed_exec(10, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 1.0 if netbios is enabled. */
				if ((! is_mounted($dev)) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					unassigned_log("SMB 2.0 mount failed: '{$o}'.");
					if ($var['USE_NETBIOS'] == "yes") {
						/* If the mount failed, try to mount with samba vers=1.0. */
						$ver	= ",sec=ntlm,vers=1.0";
						$params	= sprintf(get_mount_params($fs, $dev), $ver);
						$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

						unassigned_log("Mount SMB share '{$dev}' using SMB 1.0 protocol.");
						unassigned_log("Mount SMB command: {$cmd}");

						/* Mount the remote share. */
						$o		= timed_exec(10, $cmd." 2>&1");
						if ($o) {
							unassigned_log("SMB 1.0 mount failed: '{$o}'.");
						}
					}
				}
				exec("/bin/shred -u ".escapeshellarg($credentials_file));
				unset($pass);
			} else {
				unassigned_log("SMB must be enabled in 'Settings->SMB' to mount SMB remote shares.");
			}

			/* Did the share successfully mount? */
			if (is_mounted($dev) && is_mounted($dir)) {
				@chmod($dir, 0777);
				@chown($dir, 99);
				@chgrp($dir, 100);
				$link = $paths['usb_mountpoint']."/";
				if ((get_config("Config", "symlinks") == "yes" ) && (dirname($dir) == $paths['remote_mountpoint'])) {
					$dir .= "/".
					exec("/bin/ln -s ".escapeshellarg($dir)." ".escapeshellarg($link));
				}
				unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

				$rc = true;
			} else {
				@rmdir($dir);
			}
		} else {
			unassigned_log("Share '{$dev}' is already mounted.");
		}
	} else {
		unassigned_log("Remote Server '{$info['ip']}' is offline and share '{$info['device']}' cannot be mounted."); 
	}

	return $rc;
}

/* Toggle samba auto mount on/off. */
function toggle_samba_automount($source, $status) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? true : false;
}

/* Toggle samba share on/off. */
function toggle_samba_share($source, $status) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$source]["smb_share"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["smb_share"] == "yes") ? true : false;
}

/* Toggle hide mount on/off. */
function toggle_samba_disable_mount($device, $status) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$device]["disable_mount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$serial]["disable_mount"] == "yes") ? 'true' : 'false';
}

/* Remove the samba remote mount configuration. */
function remove_config_samba($source) {
	$config_file	= $GLOBALS["paths"]["samba_mount"];
	$config			= @parse_ini_file($config_file, true);
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '{$source}'.");
	}
	$command		= $config[$source]['command'];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '{$command}'.");
	}
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (! isset($config[$source])) ? true : false;
}

#########################################################
############		ISO FILE FUNCTIONS		#############
#########################################################

/* Get the iso file configuration parameter. */
function get_iso_config($source, $variable) {
	$config_file	= $GLOBALS["paths"]["iso_mount"];
	$config			= @parse_ini_file($config_file, true, INI_SCANNER_RAW);
	return (isset($config[$source][$variable])) ? $config[$source][$variable] : false;
}

/* Get an iso file configuration parameter. */
function set_iso_config($source, $variable, $value) {
	$config_file	= $GLOBALS["paths"]["iso_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$source][$variable] = $value;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$variable])) ? $config[$source][$variable] : false;
}

/* Is the iso file set to auto mount? */
function is_iso_automount($serial) {
	$auto			= get_iso_config($serial, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? true : false ) : false);
}

/* Get all ISO moints. */
function get_iso_mounts() {
	global $paths;

	/* Create an array of iso file mounts and set paramaters. */
	$rc				= array();
	$config_file	= $paths['iso_mount'];
	$iso_mounts		= @parse_ini_file($config_file, true);
	if (is_array($iso_mounts)) {
		ksort($iso_mounts, SORT_NATURAL);
		foreach ($iso_mounts as $device => $mount) {
			$mount['device']		= $device;
			$mount['fstype']		= "loop";
			$mount['automount'] = is_iso_automount($mount['device']);
			if (! $mount["mountpoint"]) {
				$mount["mountpoint"] = preg_replace("%\s+%", "_", "{$paths['usb_mountpoint']}/{$mount['share']}");
			}
			$mount['mounted']		= is_mounted($mount['mountpoint']);

			/* Target is set to the mount point when the device is mounted. */
			$mount['target']		= $mount['mounted'] ? $mount['mountpoint'] : "";

			$is_alive				= is_file($mount['file']);
			$stats					= get_device_stats($mount['mountpoint'], $mount['mounted']);
			$mount['size']			= intval($stats[0])*1024;
			$mount['used']			= intval($stats[1])*1024;
			$mount['avail']			= intval($stats[2])*1024;
			$mount['command']		= get_iso_config($mount['device'],"command");
			$mount['prog_name']		= basename($mount['command'], ".sh");
			$mount['user_command']	= get_iso_config($mount['device'],"user_command");
			$mount['logfile']		= ($mount['prog_name']) ? $paths['device_log'].$mount['prog_name'].".log" : "";
			$rc[] = $mount;
		}
	} else {
		unassigned_log("Error: unable to get the ISO mounts.");
	}

	return $rc;
}

/* Mount ISO file. */
function do_mount_iso($info) {
	global $paths;

	$rc = false;
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	if (is_file($info['file'])) {
		if (! is_mounted($dir)) {
			@mkdir($dir, 0777, true);
			$cmd = "/sbin/mount -ro loop ".escapeshellarg($dev)." ".escapeshellarg($dir);
			unassigned_log("Mount iso command: mount -ro loop '{$dev}' '{$dir}'");
			$o = timed_exec(15, $cmd." 2>&1");
			if (is_mounted($dir)) {
				unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

				$rc = true;
			} else {
				@rmdir($dir);
				unassigned_log("Mount of '{$dev}' failed: '{$o}'");
			}
		} else {
			unassigned_log("ISO file '{$dev}' is already mounted.");
		}
	} else {
		unassigned_log("Error: ISO file '{$info[file]}' is missing and cannot be mounted.");
	}

	return $rc;
}

/* Toggle iso file automount on/off. */
function toggle_iso_automount($source, $status) {
	$config_file	= $GLOBALS["paths"]["iso_mount"];
	$config			= @parse_ini_file($config_file, true);
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? true : false;
}

/* Remove ISO configuration. */
function remove_config_iso($source) {
	$config_file	= $GLOBALS["paths"]["iso_mount"];
	$config			= @parse_ini_file($config_file, true);
	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '{$source}'.");
	}
	$command = $config[$source]['command'];
	if ( isset($command) && is_file($command) ) {
		@unlink($command);
		unassigned_log("Removing script '{$command}'.");
	}
	unset($config[$source]);
	save_ini_file($config_file, $config);
	return (! isset($config[$source])) ? true : false;
}


#########################################################
############		DISK FUNCTIONS			#############
#########################################################

/* Get an array of all unassigned disks. */
function get_unassigned_disks() {
	global $disks;

	$ud_disks = $paths = $unraid_disks = array();

	/* Get all devices by id and eliminate any duplicates. */
	foreach (array_unique(listDir("/dev/disk/by-id/")) as $p) {
		$r = realpath($p);
		/* Only /dev/sd*, /dev/hd*, and /dev/nvme* devices. */
		if ((! is_bool(strpos($r, "/dev/sd"))) || (! is_bool(strpos($r, "/dev/hd"))) || (! is_bool(strpos($r, "/dev/nvme")))) {
			$paths[$r] = $p;
		}
	}
	ksort($paths, SORT_NATURAL);

	/* Get all unraid disk devices (array disks, cache, and pool devices). */
	foreach ($disks as $d) {
		if ($d['device']) {
			$unraid_disks[] = "/dev/".$d['device'];
		}
	}

	/* Create the array of unassigned devices. */
	foreach ($paths as $path => $d) {
		if ($d && (preg_match("#^(.(?!part))*$#", $d))) {
			if (! in_array($path, $unraid_disks, true)) {
				if (! in_array($path, array_map(function($ar){return $ar['device'];}, $ud_disks), true)) {
					$m = array_values(preg_grep("|$d.*-part\d+|", $paths));
					natsort($m);
					$ud_disks[$d] = array("device" => $path, "partitions" => $m);
				}
			}
		}
	}

	return $ud_disks;
}

/* Get all the disk information for each disk device. */
function get_all_disks_info() {

	$ud_disks = get_unassigned_disks();
	if (is_array($ud_disks)) {
		foreach ($ud_disks as $key => $disk) {
			$disk['size']	= intval(trim(timed_exec(5, "/bin/lsblk -nb -o size ".escapeshellarg(realpath($key))." 2>/dev/null")));
			$disk			= array_merge($disk, get_disk_info($key));
			foreach ($disk['partitions'] as $k => $p) {
				if ($p) {
					$disk['partitions'][$k] = get_partition_info($p);
					$disk['array_disk'] = $disk['array_disk'] || $disk['partitions'][$k]['array_disk'];
				}
			}
	        unset($ud_disks[$key]);
	        $disk['path'] = $key;
			$unassigned_dev = $disk['unassigned_dev'] ? $disk['unassigned_dev'] : $disk['ud_dev'];
			$unassigned_dev	= $unassigned_dev ? $unassigned_dev : basename($disk['device']);

			/* If there is already a devX that is the same, use the disk device' */
			if (isset($ud_disks[$unassigned_dev]) && (strpos($unassigned_dev, "dev") !== false)) {
				
				$unassigned_dev = basename($disk['device']);

				/* Set the ud_dev to the current value in the devs.ini file. */
				$disk['ud_dev'] = get_disk_dev($disk['device']);
			}
			$ud_disks[$unassigned_dev] = $disk;
		}
	} else {
		unassigned_log("Error: unable to get unassigned disks.");
		$ud_disks = array();
	}
	ksort($ud_disks, SORT_NATURAL);

	return $ud_disks;
}

/* Get the udev disk information. */
function get_udev_info($dev, $udev = null) {
	global $plugin, $paths;

	$rc		= array();

	/* Lock file for concurrent operations unique to each process. */
	$lock_file	= "/tmp/{$plugin}/".uniqid("udev_", true).".lock";

	/* Check for any lock files for previous processes. */
	$i = 0;
	while ((! empty(glob("/tmp/{$plugin}/udev_*.lock"))) && ($i < 500)) {
		sleep(0.01);
		$i++;
	}

	/* Did we time out waiting for lock release? */
	if ($i == 500) {
		unassigned_log("Timed out waiting for udev lock release.", $GLOBALS['UDEV_DEBUG']);
	}

	/* Create the lock file. */
	@touch($lock_file);

	$state	= is_file($paths['state']) ? @parse_ini_file($paths['state'], true, INI_SCANNER_RAW) : array();
	$device	= safe_name($dev);
	if ($udev) {
		unassigned_log("Udev: Update udev info for ".$dev.".", $GLOBALS['UDEV_DEBUG']);

		$state[$device]= $udev;
		save_ini_file($paths['state'], $state);

		/* Write to temp file and then move to destination file. */
		@copy($paths['state'], $paths['tmp_file']);
		@rename($paths['tmp_file'], $paths['diag_state']);

		$rc	= $udev;
	} else if (array_key_exists($device, $state)) {
		$rc	= $state[$device];
	} else {
		unassigned_log("Udev: Refresh udev info for ".$dev.".", $GLOBALS['UDEV_DEBUG']);

		$dev_state = @parse_ini_string(timed_exec(5, "/sbin/udevadm info --query=property --path $(/sbin/udevadm info -q path -n ".escapeshellarg($device)." 2>/dev/null) 2>/dev/null"), INI_SCANNER_RAW);
		if (is_array($dev_state)) {
			$state[$device] = $dev_state;
			save_ini_file($paths['state'], $state);

			/* Write to temp file and then move to destination file. */
			@copy($paths['state'], $paths['tmp_file']);
			@rename($paths['tmp_file'], $paths['diag_state']);

			$rc	= $state[$device];
		} else {
			$rc = array();
		}
	}
	
	/* Release lock. */
	@unlink($lock_file);

	return $rc;
}

/* Get information on specific disk device. */
function get_disk_info($dev) {
	global $paths, $version;

	$disk						= array();
	$attrs						= (isset($_ENV['DEVTYPE'])) ? get_udev_info($dev, $_ENV) : get_udev_info($dev, null);
	$disk['serial_short']		= isset($attrs['ID_SCSI_SERIAL']) ? $attrs['ID_SCSI_SERIAL'] : $attrs['ID_SERIAL_SHORT'];
	$disk['serial']				= trim($attrs['ID_MODEL']."_".$disk['serial_short']);
	$disk['device']				= realpath($dev);
	$disk['id_bus']				= $attrs['ID_BUS'];
	$disk['ud_dev']				= get_disk_dev($disk['device']);
	$disk['unassigned_dev']		= get_config($disk['serial'], "unassigned_dev");
	$disk['ssd']				= is_disk_ssd($disk['device']);
	$rw							= get_disk_reads_writes($disk['ud_dev'], $disk['device']);
	$disk['reads']				= $rw[0];
	$disk['writes']				= $rw[1];
	$disk['read_rate']			= $rw[2];
	$disk['write_rate']			= $rw[3];
	$disk['running']			= is_disk_running($disk['ud_dev'], $disk['device']);
	$disk['temperature']		= get_temp($disk['ud_dev'], $disk['device'], $disk['running']);
	$disk['command']			= get_config($disk['serial'], "command.1");
	$disk['user_command']		= get_config($disk['serial'], "user_command.1");
	$disk['show_partitions']	= (get_config($disk['serial'], "show_partitions") == "no") ? false : true;
	$disk['array_disk']			= false;

	/* If Unraid is 6.9 or greater, Unraid manages hot plugs. */
	if (version_compare($version['version'],"6.8.9", ">")) {
		/* If this disk does not have a devX designation, it has dropped out of the array. */
		$sf		= $paths['dev_state'];
		if ((is_file($sf)) && ($disk['id_bus'] != "usb") && (basename($disk['device']) == $disk['ud_dev'])) {
			$disk['array_disk'] = true;
		}
	}

	return $disk;
}

/* Get partition information. */
function get_partition_info($dev) {
	global $paths;

	$disk	= array();
	$attrs	= (isset($_ENV['DEVTYPE'])) ? get_udev_info($dev, $_ENV) : get_udev_info($dev, null);
	if ($attrs['DEVTYPE'] == "partition") {
		$disk['serial_short']	= isset($attrs['ID_SCSI_SERIAL']) ? $attrs['ID_SCSI_SERIAL'] : $attrs['ID_SERIAL_SHORT'];
		$disk['serial']			= $attrs['ID_MODEL']."_".$disk['serial_short'];
		$disk['device']			= realpath($dev);
		$disk['uuid']			= $attrs['ID_FS_UUID'];

		/* Get partition number */
		preg_match_all("#(.*?)(\d+$)#", $disk['device'], $matches);
		$disk['part']			= $matches[2][0];
		$disk['disk']			= MiscUD::base_device($matches[1][0]);

		/* Get the physical disk label or generate one based on the vendor id and model or serial number. */
		if (isset($attrs['ID_FS_LABEL'])){
			$disk['label']		= safe_name($attrs['ID_FS_LABEL']);
			$disk['disk_label']	= $disk['label'];
		} else {
			if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
				$disk['label']	= sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
			} else {
				$disk['label']	= safe_name($attrs['ID_SERIAL_SHORT']);
			}
			$all_disks			= array_unique(array_map(function($ar){return realpath($ar);}, listDir("/dev/disk/by-id")));
			$disk['label']		= (count(preg_grep("%".$matches[1][0]."%i", $all_disks)) > 2) ? $disk['label']."-part".$matches[2][0] : $disk['label'];
			$disk['disk_label']	= "";
		}

		/* Any partition with an 'UNRAID' label is an array disk. */
		if ($disk['label'] == "UNRAID") {
			$disk['array_disk'] = true;
		}

		/* Get the file system type. */
		$disk['fstype']			= safe_name($attrs['ID_FS_TYPE']);

		/* Get the mount point from the configuration and if not set create a default mount point. */
		$disk['mountpoint']		= get_config($disk['serial'], "mountpoint.{$disk['part']}");
		if (! $disk['mountpoint']) { 
			$disk['mountpoint']	= preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mountpoint'], $disk['label']));
		}

		/* crypto_LUKS file system. */
		if ($disk['fstype'] == "crypto_LUKS") {
			$disk['luks']		= safe_name($disk['device']);
			$disk['device']		= "/dev/mapper/".safe_name(basename($disk['mountpoint']));
		} else {
			$disk['luks']		= "";
		}

		/* Set up all disk parameters and status. */
		$disk['mounted']		= is_mounted($disk['mountpoint']);
		$disk['not_unmounted']	= ($disk['mounted'] && ! is_mounted($disk['device'])) ? true : false;

		if ($disk['mounted'] && $disk['fstype'] == "btrfs") {
			/* Get the members of a pool if this is a pooled disk. */
			$pool_devs			= MiscUD::get_pool_devices($disk['mountpoint']);

			/* First pooled device is the primary member. */
			unset($pool_devs[0]);

			/* This is a secondary pooled member if not the primary member. */
			$disk['pool']		= in_array($disk['device'], $pool_devs);
		} else {
			$disk['pool']		= false;
		}

		$disk['pass_through']	= (! $disk['mounted']) ? is_pass_through($disk['serial']) : false;
		$disk['disable_mount']	= is_disable_mount($disk['serial']);

		/* Target is set to the mount point when the device is mounted. */
		$disk['target']			= str_replace("\\040", " ", trim(shell_exec("/bin/cat /proc/mounts 2>&1 | /bin/grep ".escapeshellarg($disk['device'])." | /bin/awk '{print $2}'")));

		$stats					= get_device_stats($disk['mountpoint'], $disk['mounted']);
		$disk['size']			= intval($stats[0])*1024;
		$disk['used']			= intval($stats[1])*1024;
		$disk['avail']			= intval($stats[2])*1024;
		$disk['owner']			= (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
		$disk['automount']		= is_automount($disk['serial'], ($attrs['ID_BUS'] == "usb") ? true : false);
		$disk['read_only']		= is_read_only($disk['serial']);
		$disk['shared']			= config_shared($disk['serial'], $disk['part'], ($attrs['ID_BUS'] == "usb") ? true : false);
		$disk['command']		= get_config($disk['serial'], "command.{$disk['part']}");
		$disk['user_command']	= get_config($disk['serial'], "user_command.{$disk['part']}");
		$disk['command_bg']		= get_config($disk['serial'], "command_bg.{$disk['part']}");
		$disk['prog_name']		= basename($disk['command'], ".sh");
		$disk['logfile']		= ($disk['prog_name']) ? $paths['device_log'].$disk['prog_name'].".log" : "";
		return $disk;
	}
}

/* Get the check file system command based on disk file system. */
function get_fsck_commands($fs, $dev, $type = "ro") {
	switch ($fs) {
		case 'vfat':
			$cmd = array('ro'=>'/sbin/fsck -n %s', 'rw'=>'/sbin/fsck -a %s');
			break;

		case 'ntfs':
			$cmd = array('ro'=>'/bin/ntfsfix -n %s', 'rw'=>'/bin/ntfsfix -b -d %s');
			break;

		case 'hfsplus';
			$cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s', 'rw'=>'/usr/sbin/fsck.hfsplus -y %s');
			break;

		case 'xfs':
			$cmd = array('ro'=>'/sbin/xfs_repair -n %s', 'rw'=>'/sbin/xfs_repair %s');
			break;

		case 'exfat':
			$cmd = array('ro'=>'/usr/sbin/fsck.exfat %s', 'rw'=>'/usr/sbin/fsck.exfat %s');
			break;

		case 'btrfs':
			$cmd = array('ro'=>'/sbin/btrfs scrub start -B -R -d -r %s', 'rw'=>'/sbin/btrfs scrub start -B -R -d %s');
			break;

		case 'ext4':
			$cmd = array('ro'=>'/sbin/fsck.ext4 -vn %s', 'rw'=>'/sbin/fsck.ext4 -v -f -p %s');
			break;

		case 'reiserfs':
			$cmd = array('ro'=>'/sbin/reiserfsck --check %s', 'rw'=>'/sbin/reiserfsck --fix-fixable %s');
			break;

		case 'crypto_LUKS':
			$cmd = array('ro'=>'/sbin/fsck -Vy %s', 'rw'=>'/sbin/fsck %s');
			break;

		default:
			$cmd = array('ro'=>false, 'rw'=>false);
			break;
	}

	return $cmd[$type] ? sprintf($cmd[$type], $dev) : "";
}

/* Check for a duplicate share name when changing the mount point and mounting disks. */
function check_for_duplicate_share($dev, $mountpoint) {
	global $var, $paths;

	$rc = true;

	/* Parse the shares state file. */
	$smb_file		= "/usr/local/emhttp/state/shares.ini";
	$smb_config		= @parse_ini_file($smb_file, true);

	/* Get all share names from the state file. */
	$smb_shares		= array_keys($smb_config);
	$smb_shares		= array_flip($smb_shares);
	$smb_shares		= array_change_key_case($smb_shares, CASE_UPPER);
	$smb_shares		= array_flip($smb_shares);

	/* Parse the disks state file. */
	$disks_file 	= "/usr/local/emhttp/state/disks.ini";
	$disks_config	= @parse_ini_file($disks_file, true);

	/* Get all disk names from the disks state file. */
	$disk_names		= array_keys($disks_config);
	$disk_names		= array_flip($disk_names);
	$disk_names		= array_change_key_case($disk_names, CASE_UPPER);
	$disk_names		= array_flip($disk_names);

	/* Get the Unraid reserved names. */
	$reserved_names = explode(",", $var['reservedNames']);

	/* Add the reserved names to the disk names. */
	foreach ($reserved_names as $name) {
		$name = strtoupper($name);
		if (! in_array($name, $disk_names, true)) {
			$disk_names[] = $name;
		}
	}

	/* Start with an empty array of ud_shares. */
	$ud_shares		= array();

	/* Get an array of all ud shares. */
	$share_names	= MiscUD::get_json($paths['share_names']);
	foreach ($share_names as $device => $name) {
		$name = strtoupper($name);
		if (strpos($device, basename($dev)) === false) {
			$ud_shares[] = $name;
		}
	}

	/* Merge samba shares, reserved names, and ud shares. */
	$shares = array_merge($smb_shares, $ud_shares, $disk_names);

	/* See if the share name is already being used. */
	if (is_array($shares) && in_array(strtoupper($mountpoint), $shares, true)) {
		unassigned_log("Error: Device '".$dev."' mount point '".$mountpoint."' - name is reserved, used in the array or by an unassigned device.");
		$rc = false;
	}

	return $rc;
}

/* Change disk mount point and update the physical disk label. */
function change_mountpoint($serial, $partition, $dev, $fstype, $mountpoint) {
	global $paths, $var;

	$rc = true;

	if ($mountpoint) {
		$rc = check_for_duplicate_share($dev, $mountpoint);
		if ($rc) {
			$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
			set_config($serial, "mountpoint.{$partition}", $mountpoint);
			$mountpoint = safe_name(basename($mountpoint));
			switch ($fstype) {
				case 'xfs';
					timed_exec(20, "/usr/sbin/xfs_admin -L ".escapeshellarg($mountpoint)." ".escapeshellarg($dev)." 2>/dev/null");
					break;

				case 'btrfs';
					timed_exec(20, "/sbin/btrfs filesystem label ".escapeshellarg($dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					break;

				case 'ntfs';
					$mountpoint = substr($mountpoint, 0, 31);
					timed_exec(20, "/sbin/ntfslabel ".escapeshellarg($dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					break;

				case 'vfat';
					$mountpoint = substr(strtoupper($mountpoint), 0, 10);
					timed_exec(20, "/sbin/fatlabel ".escapeshellarg($dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					break;

				case 'exfat';
					$mountpoint = substr(strtoupper($mountpoint), 0, 15);
					timed_exec(20, "/usr/sbin/exfatlabel ".escapeshellarg($dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					break;

				case 'crypto_LUKS';
					/* Set the luks header label. */
					timed_exec(20, "/sbin/cryptsetup config ".escapeshellarg($dev)." --label ".escapeshellarg($mountpoint)." 2>/dev/null");

					/* Set the partition label. */
					$mapper	= basename($mountpoint);
					$cmd	= "luksOpen ".escapeshellarg($dev)." ".escapeshellarg($mapper);
					$pass	= decrypt_data(get_config($serial, "pass"));
					if (! $pass) {
						if (file_exists($var['luksKeyfile'])) {
							unassigned_log("Using luksKeyfile to open the 'crypto_LUKS' device.");
							$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($var['luksKeyfile'])." 2>&1");
						} else {
							unassigned_log("Using Unraid api to open the 'crypto_LUKS' device.");
							$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
						}
					} else {
						$luks_pass_file = "{$paths['luks_pass']}_".basename($dev);
						@file_put_contents($luks_pass_file, $pass);
						unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
						$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
						exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
						unset($pass);
					}
					if ($o) {
						unassigned_log("Change disk label luksOpen error: ".$o);
						$rc = false;
					} else {
						/* Try xfs label change. */
						$mapper_dev = "/dev/mapper/$mapper";
						timed_exec(20, "/usr/sbin/xfs_admin -L ".escapeshellarg($mountpoint)." ".escapeshellarg($mapper_dev)." 2>/dev/null");

						/* Try btrfs label change. */
						timed_exec(20, "/sbin/btrfs filesystem label ".escapeshellarg($mapper_dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
						shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
					}
					break;

					default;
						unassigned_log("Warning: Cannot change the disk label on device '".basename($dev)."'.");
					break;
			}
		}
	} else {
		/* Update the mountpoint. */
		set_config($serial, "mountpoint.".$partition, $mountpoint);
	}

	return $rc;
}

/* Change samba mount point. */
function change_samba_mountpoint($dev, $mountpoint) {
	global $paths;

	$rc = true;
	if ($mountpoint) {
unassigned_log("*** mountpoint ".$mountpoint);
		$rc = check_for_duplicate_share($dev, $mountpoint);
		if ($rc) {
			$mountpoint = $mountpoint;
			set_samba_config($dev, "mountpoint", $mountpoint);
		}
	} else {
		unassigned_log("Cannot change mount point! Mount point is blank.");
		$rc = false;
	}

	return $rc;
}

/* Change iso file mount point. */
function change_iso_mountpoint($dev, $mountpoint) {
	global $paths;

	$rc = true;
	if ($mountpoint) {
		$rc = check_for_duplicate_share($dev, $mountpoint);
		if ($rc) {
			$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
			set_iso_config($dev, "mountpoint", $mountpoint);
		} else {
		}
	} else {
		unassigned_log("Cannot change mount point! Mount point is blank.");
		$rc = false;
	}

	return $rc;
}

/* Change the disk UUID. */
function change_UUID($dev) {
	global $plugin;

	$rc	= "";

	$fs_type = "";
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			$fs_type	= $d['partitions'][0]['fstype'];
			$serial		= $d['partitions'][0]['serial'];
			$luks		= $d['partitions'][0]['luks'];
			break;
		}
	}

	$device	= $dev;

	/* nvme disk partitions are 'p1', not '1'. */
	$device	.=(MiscUD::is_device_nvme($dev)) ? "p1" : "1";

	/* Deal with crypto_LUKS disks. */
	if ($fs_type == "crypto_LUKS") {
		timed_exec(20, escapeshellcmd("plugins/{$plugin}/scripts/luks_uuid.sh ".escapeshellarg($device)));
		$mapper	= basename($luks)."_UUID";
		$cmd	= "luksOpen ".escapeshellarg($luks)." ".escapeshellarg($mapper);
		$pass	= decrypt_data(get_config($serial, "pass"));
		if (! $pass) {
			if (file_exists($var['luksKeyfile'])) {
				unassigned_log("Using luksKeyfile to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($var['luksKeyfile'])." 2>&1");
			} else {
				unassigned_log("Using Unraid api to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
			}
		} else {
			$luks_pass_file = "{$paths['luks_pass']}_".basename($luks);
			@file_put_contents($luks_pass_file, $pass);
			unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
			$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
			exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
			unset($pass);
		}

		/* Check for luks open error. */
		if ($o) {
			unassigned_log("luksOpen error: {$o}");
		} else {
			/* Get the crypto file system check so we can determine the luks file system. */
			$mapper_dev = "/dev/mapper/".$mapper;
			$command = get_fsck_commands($fs_type, $mapper_dev)." 2>&1";
			$o = shell_exec(escapeshellcmd($command));
			if (stripos($o, "XFS") !== false) {
				/* Change the xfs UUID. */
				$rc = timed_exec(10, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($mapper_dev));
			} else if (stripos($o, "BTRFS") !== false) {
				$rc = timed_exec(10, "/sbin/btrfstune -uf ".escapeshellarg($mapper_dev));
			} else {
				$rc = "Cannot change UUID.";
			}

			/* Close the luks device. */
			shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
		}
	} else if ($fs_type == "xfs") {
		/* Change the xfs UUID. */
		$rc		= timed_exec(20, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($device));
	} else if ($fs_type == "btrfs") {
		/* Change the btrfs UUID. */
		$rc		= timed_exec(20, "/sbin/btrfstune -uf ".escapeshellarg($device));
	}

	/* Show the result of the UUID change operation. */
	if ($rc) {
		unassigned_log("Changed partition UUID on '{$device}' with result: {$rc}");
	}
}

/* If the disk is not a SSD, set the spin down timer if allowed by settings. */
function setSleepTime($dev) {
	global $paths;

	$sf	= $paths['dev_state'];

	/* If devs.ini does not exist, do the spindown using the disk timer. */
	if ((! is_file($sf)) && get_config("Config", "spin_down") == 'yes') {
		if (! is_disk_ssd($dev)) {
			unassigned_log("Set spin down timer for device '{$dev}'.");
			timed_exec(5, "/usr/sbin/hdparm -S180 $dev 2>&1");
		} else {
			unassigned_log("Don't spin down device '{$dev}'.");
			timed_exec(5, "/usr/sbin/hdparm -S0 $dev 2>&1");
		}
	}
}

/* Setup a socket for nchan publish events. */
function curl_socket($socket, $url, $postdata = NULL) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $socket);
	if ($postdata !== NULL) {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
	}
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_exec($ch);
	curl_close($ch);
}

/* Trigger an nchan event. */
function publish($message = "rescan") {
	$endpoint = $_COOKIE['ud_reload'];
	curl_socket("/var/run/nginx.socket", "http://localhost/pub/$endpoint?buffer_length=1", $message);
}
?>
