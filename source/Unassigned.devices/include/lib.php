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

$docroot	= $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$plugin		= "unassigned.devices";

$paths = [	"smb_unassigned"	=> "/etc/samba/smb-unassigned.conf",
			"smb_usb_shares"	=> "/etc/samba/unassigned-shares",
			"usb_mountpoint"	=> "/mnt/disks",
			"remote_mountpoint"	=> "/mnt/remotes",
			"root_mountpoint"	=> "/mnt/rootshare",
			"dev_state"			=> $docroot."/state/devs.ini",
			"shares_state"		=> $docroot."/state/shares.ini",
			"disks_state"		=> $docroot."/state/disks.ini",
			"disk_load"			=> $docroot."/state/diskload.ini",
			"device_log"		=> "/tmp/".$plugin."/logs/",
			"config_file"		=> "/tmp/".$plugin."/config/".$plugin.".cfg",
			"samba_mount"		=> "/tmp/".$plugin."/config/samba_mount.cfg",
			"iso_mount"			=> "/tmp/".$plugin."/config/iso_mount.cfg",
			"scripts"			=> "/tmp/".$plugin."/scripts/",
			"credentials"		=> "/tmp/".$plugin."/credentials",
			"authentication"	=> "/tmp/".$plugin."/authentication",
			"luks_pass"			=> "/tmp/".$plugin."/luks_pass",
			"hotplug_event"		=> "/tmp/".$plugin."/hotplug_event",
			"tmp_file"			=> "/tmp/".$plugin."/".uniqid("move_", true).".tmp",
			"state"				=> "/var/state/".$plugin."/".$plugin.".ini",
			"diag_state"		=> "/var/local/emhttp/".$plugin.".ini",
			"mounted"			=> "/var/state/".$plugin."/".$plugin.".json",
			"run_status"		=> "/var/state/".$plugin."/run_status.json",
			"ping_status"		=> "/var/state/".$plugin."/ping_status.json",
			"df_status"			=> "/var/state/".$plugin."/df_status.json",
			"disk_names"		=> "/var/state/".$plugin."/disk_names.json",
			"share_names"		=> "/var/state/".$plugin."/share_names.json",
			"pool_state"		=> "/var/state/".$plugin."/pool_state.json",
			"device_hosts"		=> "/var/state/".$plugin."/device_hosts.json",
			"unmounting"		=> "/var/state/".$plugin."/unmounting_%s.state",
			"mounting"			=> "/var/state/".$plugin."/mounting_%s.state",
			"formatting"		=> "/var/state/".$plugin."/formatting_%s.state"
		];

/* SMB and NFS ports. */
define('SMB_PORT', '445');
define('NFS_PORT', '2049');

/* Get the Unraid users. */
$users_ini			= @parse_ini_file($docroot."/state/users.ini", true);
$users				= ($users_ini !== false) ? $users_ini : [];

/* Get all Unraid disk devices (array disks, cache, and pool devices). */
$array_disks_ini	= @parse_ini_file($docroot."/state/disks.ini", true);
$array_disks		= ($array_disks_ini !== false) ? $array_disks_ini : [];
$unraid_disks		= [];
foreach ($array_disks as $d) {
	if ($d['device']) {
		$unraid_disks[] = "/dev/".$d['device'];
	}
}

/* Get the version of Unraid we are running. */
$version = @parse_ini_file("/etc/unraid-version");

/* Set the log level for debugging. */
/* 0 - normal logging, */

/* 1 - udev and disk discovery logging, */
$UDEV_DEBUG		= 1;

/* 2 - refresh and update logging, */
$UPDATE_DEBUG	= 2;

/* 8 - command time outs. */
$CMD_DEBUG		= 8;

/* Read in the UD configuration file. */
$config_ini		= @parse_ini_file($paths['config_file'], true, INI_SCANNER_RAW);
$ud_config		= ($config_ini !== false) ? $config_ini : [];

/* Read in the Samba configuration file. */
$config_ini		= @parse_ini_file($paths['samba_mount'], true, INI_SCANNER_RAW);
$samba_config	= ($config_ini !== false) ? $config_ini : [];

/* Read in the ISO configuration file. */
$config_ini		= @parse_ini_file($paths['iso_mount'], true, INI_SCANNER_RAW);
$iso_config		= ($config_ini !== false) ? $config_ini : [];

$DEBUG_LEVEL	= (int) get_config("Config", "debug_level");

/* See if the UD settings are set for either SMB or NFS sharing. */
$shares_enabled	= ((get_config("Config", "smb_security") != "no") || (get_config("Config", "nfs_export") == "yes"));

/* Read Unraid variables file. Used to determine disks not assigned to the array and other array parameters. */
if (! isset($var)){
	if (! is_file($docroot."/state/var.ini")) {
		exec("/usr/bin/wget -qO /dev/null localhost:$(ss -napt | /bin/grep emhttp | /bin/grep -Po ':\K\d+') >/dev/null");
	}
	$var = @parse_ini_file($docroot."/state/var.ini");
}

/* Capitalize the local_tld.  Default local TLD is 'LOCAL'. */
$default_tld	= "LOCAL";
$local_tld		= (isset($var['LOCAL_TLD']) && ($var['LOCAL_TLD'])) ? strtoupper(trim($var['LOCAL_TLD'])) : $default_tld;

/* Array of devices, mount points, and read only status taken from the /proc/mounts file. */
$mounts			= null;

/* Array of devices and file system type taken from lsblk. */
$lsblk_file_types	= null;

/* See if the preclear plugin is installed. */
if ( is_file( $docroot."/plugins/preclear.disk/assets/lib.php" ) ) {
	require_once( $docroot."/plugins/preclear.disk/assets/lib.php" );
	$Preclear = new Preclear;
} else if ( is_file( $docroot."/plugins/".$plugin.".preclear/include/lib.php" ) ) {
	require_once( $docroot."/plugins/".$plugin.".preclear/include/lib.php" );
	$Preclear = new Preclear;
} else {
	$Preclear = null;
}

/* Misc functions. */
class MiscUD
{
	/* Save content to a json file. */
	public static function save_json($file, $content) {
		global $paths;

		/* Write to temp file and then move to destination file. */
		$tmp_file	= $paths['tmp_file'];
		@file_put_contents($tmp_file, json_encode($content, JSON_PRETTY_PRINT));
		@rename($tmp_file, $file);
	}

	/* Get content from a json file. */
	public static function get_json($file) {
		return file_exists($file) ? @json_decode(file_get_contents($file), true) : [];
	}

	/* Check for a valid IP address. */
	public static function is_ip($str) {
		return filter_var($str, FILTER_VALIDATE_IP);
	}

	/* Check for text in a file. */
	public static function exist_in_file($file, $text) {

		$rc	= false;
		$fileContent = file_get_contents($file);

		if ($fileContent !== false) {
			if (strpos($fileContent, $text) !== false) {
				$rc	= true;
			}
		}

		return $rc;
	}

	/* Is the device an nvme disk? */
	public static function is_device_nvme($dev) {
		return (strpos($dev, "nvme") !== false);
	}

	/* Remove the partition number from $dev and return the base device. */
	public static function base_device($dev) {
		return (strpos($dev, "nvme") !== false) ? preg_replace("#\d+p#i", "", $dev) : preg_replace("#\d+#i", "", $dev);
	}

	/* Shorten a string to $truncate size. */
	public static function compress_string($str) {
		$rc			= $str;

		$truncate	= 32;
		$trim		= 20;
		if (strlen($str) > $truncate) {
			$rc = substr($str, 0, $trim)."...".substr($str, strlen($str)-($truncate-$trim));
		}

		return $rc;
	}
	
	/* Spin disk up or down using Unraid api. */
	public static function spin_disk($down, $dev) {
		if ($down) {
			exec("/usr/local/sbin/emcmd cmdSpindown=".escapeshellarg($dev));
		} else {
			exec("/usr/local/sbin/emcmd cmdSpinup=".escapeshellarg($dev));
		}
	}

	/* Get array of btrfs pool devices on a mount point. */
	public static function get_pool_devices($mountpoint, $remove = false) {
		global $paths;

		$rc = [];

		/* Get the current pool status. */
		$pool_state	= MiscUD::get_json($paths['pool_state']);

		/* If this mount point is not defined, set it as an empty array. */
		if (in_array($mountpoint, $pool_state)) {
			$pool_state[$mountpoint]	= is_array($pool_state[$mountpoint]) ? $pool_state[$mountpoint] : [];
		}

		if (isset($pool_state[$mountpoint])) {
			if ($remove) {
				/* Remove this from the pool devices if unmounting. */
				unset($pool_state[$mountpoint]);
				MiscUD::save_json($paths['pool_state'], $pool_state);
			} else if (is_array($pool_state[$mountpoint]) && (! count($pool_state[$mountpoint]))) {
				/* Get the pool parameters if they are not already defined. */
				unassigned_log("Debug: Get Disk Pool members on mountpoint '".$mountpoint."'.", $GLOBALS['UDEV_DEBUG']);

				/* Get the brfs pool status from the mountpoint. */
				$s	= shell_exec("/sbin/btrfs fi show ".escapeshellarg($mountpoint)." | /bin/grep 'path' | /bin/awk '{print $8}'");
				$rc	= explode("\n", $s);
				$pool_state[$mountpoint] = array_filter($rc);
				MiscUD::save_json($paths['pool_state'], $pool_state);
			} else {
				/* Get the pool status from the pool_state. */
				$rc = $pool_state[$mountpoint];
			}
		}

		return array_filter($rc);
	}

	/* Save the hostX from the DEVPATH so we can re-attach a disk device. */
	public static function save_device_host($serial, $devpath) {
		global $paths;

		/* Find the hostX in the DEVPATH and parse it from the DEVPATH. */
		$begin	= strpos($devpath, "host");
		$end	= strpos($devpath, "/", $begin);
		$host	= substr($devpath, $begin, $end-$begin);

		if (($host) && ($serial)) {
			/* Get the current hostX status. */
			$device_hosts	= MiscUD::get_json($paths['device_hosts']);

			if ((! isset($device_hosts[$serial])) || ($device_hosts[$serial] != $host)) {
				/* Get a lock so file changes can be made. */
				$lock_file		= get_file_lock("hosts");

				/* Get the current hostX status. */
				$device_hosts	= MiscUD::get_json($paths['device_hosts']);

				/* Add new entry or replace existing entry. */
				$device_hosts[$serial]	= $host;

				/* Save the new hosts array. */
				MiscUD::save_json($paths['device_hosts'], $device_hosts);

				/* Release the file lock. */
				release_file_lock($lock_file);
			}
		}
	}

	/* Get the device hostX. */
	public static function get_device_host($serial, $delete = false) {
		global $paths;

		$rc	= "";

		/* Get the current hostX status. */
		$device_hosts	= MiscUD::get_json($paths['device_hosts']);

		if (isset($device_hosts[$serial])) {
			if (is_file("/sys/class/scsi_host/".$device_hosts[$serial]."/scan")) {
				/* Return the hostX. */
				$rc	= $device_hosts[$serial];
			}

			if ((! $rc) || ($delete)) {
				/* Get a lock so file changes can be made. */
				$lock_file		= get_file_lock("hosts");

				/* Get the current hostX status. */
				$device_hosts	= MiscUD::get_json($paths['device_hosts']);

				/* Delete this host entry.  If the device is actually connected, the host entry will be restored when it is recognized. */
				unset($device_hosts[$serial]);

				/* Save the new hosts array. */
				MiscUD::save_json($paths['device_hosts'], $device_hosts);

				/* Release the file lock. */
				release_file_lock($lock_file);
			}
		}

		return $rc;
	}

	/* Get disk mounting status. */
	public static function get_mounting_status($device) {
		global $paths;

		$mounting		= array_values(preg_grep("@/mounting_".safe_name($device, true, true)."@i", listFile(dirname($paths['mounting']))))[0] ?? '';
		$is_mounting	= (isset($mounting) && (time() - filemtime($mounting) < 300));
		return $is_mounting;
	}

	/* Get the unmounting status. */
	public static function get_unmounting_status($device) {
		global $paths;

		$unmounting		= array_values(preg_grep("@/unmounting_".safe_name($device, true, true)."@i", listFile(dirname($paths['unmounting']))))[0] ?? '';
		$is_unmounting	= (isset($unmounting) && (time() - filemtime($unmounting)) < 300);
		return $is_unmounting;
	}

	/* Get the formatting status. */
	public static function get_formatting_status($device) {
		global $paths;

		$formatting		= array_values(preg_grep("@/formatting_".basename($device)."@i", listFile(dirname($paths['formatting']))))[0] ?? '';
		$is_formatting	= (isset($formatting) && (time() - filemtime($formatting) < 300));
		return $is_formatting;
	}

	/* Get the zpool name if this is a zfs disk file system. */
	public static function zfs_pool_name($dev, $mount_point = "") {
		global $mounts;

		/* Load zfs modules if they're not loaded. */
		if (! is_file("/usr/sbin/zpool")) {
			exec("/sbin/modprobe zfs");
		}

		/* Only check for pool name if zfs is running. */
		if (is_file("/usr/sbin/zpool")) {
			if ($dev) {
				$rc	= shell_exec("/usr/sbin/zpool import -d ".escapeshellarg($dev)." 2>/dev/null | grep 'pool:'") ?? "";
				$rc	= trim(str_replace("pool:", "", $rc));
			} else {
				$rc	= "";
			}

			/* The disk must be mounted if we cannot import the zpool. The pool name is the device in the mount status. */
			if ((! $rc) && ($mount_point) && (isset($mounts[$mount_point]))) {
				$rc		= $mounts[$mount_point]['device'];
			}
		} else {
			$rc	= "";
		}

		return ($rc);
	}
}

/* Echo variable to GUI for debugging. */
function _echo($m) {
	echo "<pre>".print_r($m,true)."</pre>";
}

/* Get a file lock so changes can be made to a cfg or ini file. */
function get_file_lock($type = "cfg") {
	global $plugin;

	/* Lock file for concurrent operations unique to each process. */
	$lock_file	= "/tmp/".$plugin."/".uniqid($type."_", true).".lock";


	/* Check for any lock files for previous processes. */
	$i = 0;
	while ((! empty(glob("/tmp/".$plugin."/".$type."_*.lock"))) && ($i < 200)) {
		usleep(10 * 1000);
		$i++;
	}

	/* Did we time out waiting for an unlock release? */
	if ($i == 200) {
		unassigned_log("Debug: Timed out waiting for file lock.", $GLOBALS['UPDATE_DEBUG']);
	}

	/* Create the lock. */
	@touch($lock_file);

	return $lock_file;
}

/* Release the file lock. */
function release_file_lock($lock_file) {

	/* Release the lock. */
	if ($lock_file) {
		@unlink($lock_file);
	}
}

/* Save ini and cfg files to tmp file system and then copy cfg file changes to flash. */
function save_ini_file($file, $config, $save_config = true) {
	global $plugin, $paths, $ud_config;

	$res = [];
	foreach($config as $key => $val) {
		if (is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) {
				$res[] = $skey."=".(is_numeric($sval) ? $sval : '"'.$sval.'"');
			}
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}

	/* Write to temp file and then move to destination file. */
	$tmp_file	= $paths['tmp_file'];
	@file_put_contents($tmp_file, implode(PHP_EOL, $res));
	@rename($tmp_file, $file);

	/* Write cfg file changes back to flash. */
	if ($save_config) {
		$file_path = pathinfo($file);
		if ($file_path['extension'] == "cfg") {
			@file_put_contents("/boot/config/plugins/".$plugin."/".basename($file), implode(PHP_EOL, $res));
		}
	}
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
function listFile($root) {
	/* Check if $root is a valid directory. */
	if (is_dir($root)) {
		$filePaths = glob($root . '/*', GLOB_MARK);
		$filesOnly = [];

		foreach ($filePaths as $path) {
			if (! is_dir($path)) {
				$filesOnly[] = $path;
			}
		}
	} else {
		$filesOnly = [];
	}

	return $filesOnly;
}

/* Remove characters that will cause issues with php in names. */
function safe_name($name, $convert_spaces = true, $convert_extra = false) {

	/* UTF8 characters only and not escaped. Decode html entities. */
	$string				= html_entity_decode($name, ENT_QUOTES, 'UTF-8');
	$string				= preg_replace('/[\p{C}]+/u', '', stripcslashes($string));

	/* Convert reserved php characters and invalid file name characters to underscore. */
	$escapeSequences	= array("'", '"', "?", "#", "&", "!", "<", ">", "|", "+", "@");
	$replacementChars	= "_";

	/* Convert troublesome Chinese multi-byte characters to underscore. */
	$escapeSequences[]	= "空";

	/* Convert parentheses to underscore. */
	if ($convert_extra) {
		$extraSequences		= array("(", ")");
		$escapeSequences	= array_merge($escapeSequences, $extraSequences);
	}

	/* Convert spaces to underscore. */
	if ($convert_spaces) {
		$escapeSequences[]	= " ";
	}

	/* Convert all unsafe characters to underline character. */
	$string = str_replace($escapeSequences, $replacementChars, $string);

	return trim($string);
}

/* Get the size, used, and free space on a mount point. */
function get_device_stats($mountpoint, $mounted, $active = true) {
	global $docroot, $paths, $plugin;

	$rc			= "";
	$tc			= $paths['df_status'];

	/* Get the device stats if device is mounted. */
	if ($mounted) {
		$df_status	= MiscUD::get_json($tc);
		/* Run the stats script to update the state file. */
		$df_status[$mountpoint]['timestamp']	= $df_status[$mountpoint]['timestamp'] ?? 0;

		/* Update the size, used, and free status every 90 seconds on each device. */
		if (($active) && ((time() - $df_status[$mountpoint]['timestamp']) > 90)) {
			exec($docroot."/plugins/".$plugin."/scripts/get_ud_stats df_status ".escapeshellarg($tc)." ".escapeshellarg($mountpoint)." ".escapeshellarg($GLOBALS['DEBUG_LEVEL'])." &");
		}

		/* Get the device stats. */
		$df_status	= MiscUD::get_json($tc);
		if (isset($df_status[$mountpoint])) {
			$rc = $df_status[$mountpoint]['stats'];
		}
	}

	/* Get the stats from the json file.  If the command timed out, convert to empty string. */
	$stats		= preg_split('/\s+/', str_replace("command timed out", "", $rc));
	$stats[0]	= intval($stats[0] ?? 0);
	$stats[1]	= intval($stats[1] ?? 0);
	$stats[2]	= intval($stats[2] ?? 0);

	return $stats;
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

/* Get the disk id for this device from the devs.ini. */
function get_disk_id($dev, $udev_id) {
	global $paths;

	$rc		= $udev_id;
	$device	= MiscUD::base_device(basename($dev));

	$sf		= $paths['dev_state'];

	/* Check for devs.ini file and get the id for this device. */
	if (is_file($sf)) {
		$devs = @parse_ini_file($sf, true);
		foreach ($devs as $d) {
			if (($d['device'] == $device) && $d['id']) {
				$rc = $d['id'];
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
			$rc[0] = intval($devs[$ud_dev]['numReads']);
			$rc[1] = intval($devs[$ud_dev]['numWrites']);
		}
	}

	/* Get the base device - remove the partition number. */
	$dev	= MiscUD::base_device(basename($dev));

	/* Get the disk_io for this device. */
	$disk_io	= (is_file($paths['disk_load'])) ? @parse_ini_file($paths['disk_load']) : [];
	$data		= explode(' ', $disk_io[$dev] ?? '0 0 0 0');

	/* Read rate. */
	$rc[2] 		= ($data[0] > 0) ? intval($data[0]) : 0;

	/* Write rate. */
	$rc[3] 		= ($data[1] > 0) ? intval($data[1]) : 0;

	return $rc;
}

/* Check to see if the disk is spun up or down. */
function is_disk_spinning($ud_dev) {
	global $paths;

	$rc			= false;
	$run_devs	= false;
	$sf			= $paths['dev_state'];
	$tc			= $paths['run_status'];

	/* Check for dev state file to get the current spindown state. */
	if (is_file($sf)) {
		$devs	= @parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$rc			= ($devs[$ud_dev]['spundown'] == "0");
			$device		= $ud_dev;
			$timestamp	= time();
		}
	}

	/* Get the current run status. */
	$run_status	= MiscUD::get_json($tc);

	if (isset($device)) {
		/* Update the spin status. */
		$spin		= $run_status[$device]['spin'] ?? "";
		$spin_time	= $run_status[$device]['spin_time'] ?? 0;
		$run_status[$device] = array('timestamp' => $timestamp, 'running' => $rc ? 'yes' : 'no', 'spin_time' => $spin_time, 'spin' => $spin);
		MiscUD::save_json($tc, $run_status);
	}

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
function is_samba_server_online($ip, $protocol) {
	global $paths, $default_tld;

	$is_alive		= false;

	/* Strip off any local tld reference and capitalize the server name. */
	$server			= str_replace(".".$default_tld, "", strtoupper($ip));

	/* Get the updated ping status. */
	$tc				= $paths['ping_status'];
	$ping_status	= MiscUD::get_json($tc);
	$name			= $server.".".$protocol;
	if (isset($ping_status[$name])) {
		$is_alive = ($ping_status[$name]['online'] == "yes");
	}

	return $is_alive;
}

/* Check to see if a mount/unmount device script or user script is running. */
function is_script_running($cmd, $user = false) {
	global $paths;

	$is_running = false;

	/* Check for a command file. */
	if ($cmd) {
		/* Set up for ps to find the right script. */
		if ($user) {
			$path_info	= pathinfo($cmd);
			$cmd		= $path_info['dirname'];
			$source		= "user.scripts";
		} else {
			$source		= "unassigned.devices";
		}

		/* Check if the script is currently running. */
		$is_running = shell_exec("/usr/bin/ps -ef | /bin/grep ".escapeshellarg(basename($cmd))." | /bin/grep -v 'grep' | /bin/grep ".escapeshellarg($source)) != "";
	}

	return $is_running;
}

/* Get disk temperature. */
function get_temp($ud_dev, $dev, $running) {
	global $paths;

	$rc		= "*";
	$sf		= $paths['dev_state'];
	$device	= basename($dev);

	/* Get temperature from the devs.ini file. */
	if (is_file($sf)) {
		$devs	= @parse_ini_file($sf, true);
		$rc		= $devs[$ud_dev]['temp'] ?? "*";
	}

	return $rc;
}

/* Get the format command based on file system to be formatted. */
function get_format_cmd($dev, $fs, $pool_name) {
	switch ($fs) {
		case 'xfs':
		case 'xfs-encrypted';
			$rc = "/sbin/mkfs.xfs -f ".escapeshellarg($dev)." 2>&1";
			break;

		case 'btrfs':
		case 'btrfs-encrypted';
			$rc = "/sbin/mkfs.btrfs -f ".escapeshellarg($dev)." 2>&1";
			break;

		case 'zfs':
		case 'zfs-encrypted';
			$rc = "/usr/sbin/zpool create -o compatibility=legacy -o ashift=12 -f ".escapeshellarg($pool_name)." ".escapeshellarg($dev)." 2>&1";
			break;

		case 'ntfs':
			$rc = "/sbin/mkfs.ntfs -Q ".escapeshellarg($dev)." 2>&1";
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
function format_disk($dev, $fs, $pass, $pool_name) {
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
		$disk_blocks	= intval(trim(shell_exec("/sbin/blockdev --getsz ".escapeshellarg($dev)." 2>/dev/null | /bin/awk '{ print $1 }'")));
		$disk_schema	= ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
		$parted_fs		= ($fs == "exfat") ? "fat32" : $fs;

		unassigned_log("Device '".$dev."' block size: ".$disk_blocks.".");

		/* Clear the partition table. */
		unassigned_log("Clearing partition table of disk '".$dev."'.");
		$o = trim(shell_exec("/usr/bin/dd if=/dev/zero of=".escapeshellarg($dev)." bs=2M count=1 2>&1"));
		if ($o) {
			unassigned_log("Clear partition result:\n".$o);
		}

		/* Let things settle a bit. */
		sleep(2);

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
		if ($fs == "xfs" || $fs == "xfs-encrypted" || $fs == "btrfs" || $fs == "btrfs-encrypted"|| $fs == "zfs" || $fs == "zfs-encrypted") {
			if ($fs == "zfs" || $fs == "zfs-encrypted") {
				/* Load zfs modules. */
				exec("/sbin/modprobe zfs");
			}
			$is_ssd = is_disk_ssd($dev);
			if ($disk_schema == "gpt") {
				unassigned_log("Creating Unraid compatible gpt partition on disk '".$dev."'.");
				exec("/sbin/sgdisk -Z ".escapeshellarg($dev));

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

			/* Let things settle a bit. */
			sleep(2);

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
			unassigned_log("Creating a '".$disk_schema."' partition table on disk '".$dev."'.");
			$o = shell_exec("/usr/sbin/parted ".escapeshellarg($dev)." --script -- mklabel ".escapeshellarg($disk_schema)." 2>&1");
			if (isset($o)) {
				unassigned_log("Create '".$disk_schema."' partition table result:\n".$o);
			}

			/* Create an optimal disk partition. */
			$o = shell_exec("/usr/sbin/parted -a optimal ".escapeshellarg($dev)." --script -- mkpart primary ".escapeshellarg($parted_fs)." 0% 100% 2>&1");
			if (isset($o)) {
				unassigned_log("Create primary partition result:\n".$o);
			}
		}

		unassigned_log("Formatting disk '".$dev."' with '".$fs."' filesystem.");

		/* Format the disk. */
		if (strpos($fs, "-encrypted") !== false) {
			/* nvme partition designations are 'p1', not '1'. */
			if (MiscUD::is_device_nvme($dev)) {
				$cmd = "luksFormat ".$dev."p1";
			} else {
				$cmd = "luksFormat ".$dev."1";
			}

			/* Use a disk password, or Unraid's. */
			if (! $pass) {
				$o				= shell_exec("/usr/local/sbin/emcmd cmdCryptsetup=".escapeshellarg($cmd)." 2>&1");
			} else {
				$luks			= basename($dev);
				$luks_pass_file	= $paths['luks_pass']."_".$luks;
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
					$o = shell_exec("/usr/local/sbin/emcmd cmdCryptsetup=".escapeshellarg($cmd)." 2>&1");

					/* Check for the mapper file existing. If it's not there, unraid did not open the luks disk. */
					if (! file_exists("/dev/mapper/".$mapper)) {
						$o	= "Error: Passphrase or Key File not found.";
					}
				} else {
					$luks			= basename($dev);
					$luks_pass_file	= $paths['luks_pass']."_".$luks;
					@file_put_contents($luks_pass_file, $pass);
					$o				= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
					exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
				}

				if ($o && stripos($o, "warning") === false) {
					unassigned_log("luksOpen result: ".$o);
					$rc = false;
				} else {
					$out	= null;
					$return	= null;
					$cmd	= get_format_cmd("/dev/mapper/".$mapper, $fs, $pool_name);
					unassigned_log("Format drive command: ".$cmd);

					/* Format the disk. */
					exec($cmd, $out, $return);
					sleep(1);

					/* Set compatibility setting off so we can check for needing upgrade. */
					exec("/usr/sbin/zpool set compatibility=off ".escapeshellarg($pool_name));
					sleep(1);

					/* Export the pool. */
					exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
					sleep(1);
				}

				/* Close the luks device. */
				exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper)." 2>/dev/null");
			}
		} else {
			/* Format the disk. */
			$out	= null;
			$return	= null;
			$cmd	= get_format_cmd($device, $fs, $pool_name);
			unassigned_log("Format drive command: ".$cmd);
			exec($cmd, $out, $return);
			sleep(1);
			if (($fs == "zfs") && ($pool_name)) {
				/* Set compatibility setting off so we can check for needing upgrade. */
				exec("/usr/sbin/zpool set compatibility=off ".escapeshellarg($pool_name));
				sleep(1);

				/* Export the pool. */
				exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
				sleep(1);
			}
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

				/* Reload the partition table. */
				$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
				if ($o) {
					unassigned_log("Reload partition table result:\n".$o);
				}

				/* Clear the $pass variable. */
				unset($pass);

				/* Clear any existing zfs pool information onthe disk. */
				if (($fs != "zfs") && ($fs != "zfs-encrypted")) {
					sleep(1);

					/* See if there is a zpool signature on the disk. */
					$old_pool_name	= MiscUD::zfs_pool_name($dev);
					if ($old_pool_name) {
						/* Remove zpool label info. */
						exec("/usr/sbin/zpool labelclear -f ".escapeshellarg($dev));

						sleep(1);

						unassigned_log("Format failed, zpool signature found on device '".$dev."'!  Clear the disk and try again.");
						$rc		= false;
					}

					/* Get partition designation based on type of device. */
					if (MiscUD::is_device_nvme($dev)) {
						$device	= $dev."p1";
					} else {
						$device	= $dev."1";
					}

					$old_pool_name	= MiscUD::zfs_pool_name($device);
					if ($old_pool_name) {
						/* Remove zpool label info. */
						exec("/usr/sbin/zpool labelclear -f ".escapeshellarg($device));

						sleep(1);

						unassigned_log("Format failed, zpool signature found on device partition '".$device."'!  Clear the disk and try again.");
						$rc		= false;
					}
				}

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
				if (($p['part'] == $part) && ($p['target'])) {
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
			unassigned_log("Remove partition failed: '".$out."'.");
			$rc = false;
		} else {
			/* Reload the partition. */
			exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." >/dev/null 2>&1 &");

			/* Refresh partition information. */
			exec("/usr/sbin/partprobe ".escapeshellarg($dev));
		}
	}

	return $rc;
}

/* Remove all disk partitions. */
function remove_all_partitions($dev) {
	$rc = true;

	/* Be sure there are no mounted partitions. */
	$disks	= get_all_disks_info();
	foreach ($disks as $d) {
		if ($d['device'] == $dev) {
			$serial	= $d['serial'];
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

		$pool_name	= MiscUD::zfs_pool_name($dev);
		if ($pool_name) {
			/* Remove zpool label info. */
			exec("/usr/sbin/zpool labelclear -f ".escapeshellarg($dev));
			sleep(1);

			/* Export the zpool. */
			exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
			sleep(1);
		}

		/* Remove all partitions - this clears the disk. */
		foreach ($disks as $d) {
			if ($d['device'] == $dev) {
				foreach ($d['partitions'] as $p) {
					/* Get partition designation based on type of device. */
					if (MiscUD::is_device_nvme($device)) {
						$zfs_device	= $dev."p".$p['part'];
					} else {
						$zfs_device	= $dev.$p['part'];
					}

					$pool_name	= MiscUD::zfs_pool_name($zfs_device);
					if ($pool_name) {
						/* Remove zpool label info. */
						exec("/usr/sbin/zpool labelclear -f ".escapeshellarg($zfs_device));
						sleep(1);

						/* Export the zpool. */
						exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
						sleep(1);
					}

					/* We have to clear every partition. */
					exec("/sbin/wipefs --all --force ".escapeshellarg($zfs_device)." 2>&1");
					sleep(1);
				}
			}
		}

		exec("/sbin/wipefs --all --force ".escapeshellarg($device)." 2>&1");

		/* Let things settle a bit. */
		sleep(2);

		unassigned_log("Debug: Remove all Disk partitions.", $GLOBALS['UDEV_DEBUG']);

		/* Refresh partition information. */
		exec("/usr/sbin/partprobe ".escapeshellarg($dev));
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
	$time		+= microtime(true); 
	$type		= ($time > 10) ? 0 : 1;
	unassigned_log("benchmark: $function(".implode(",", $params).") took ".sprintf('%f', $time)."s.", $type);

	return $out;
}

/* Run a command and time out if it takes too long. */
function timed_exec($timeout, $cmd) {
	$time		= -microtime(true); 
	$out		= trim(shell_exec("/usr/bin/timeout ".escapeshellarg($timeout)." ".$cmd) ?? "");
	$time		+= microtime(true);
	if ($time > $timeout) {
		unassigned_log("Warning: shell_exec(".$cmd.") took longer than ".sprintf('%d', $timeout)."s!");
		$out	= "command timed out";
	} else {
		unassigned_log("Timed Exec: shell_exec(".$cmd.") took ".sprintf('%f', $time)."s!", $GLOBALS['CMD_DEBUG']);
	}

	return $out;
}

/* Find the file system type of a partition. */
function part_fs_type($dev) {
	global $lsblk_file_types;

	/* Get the file system types from lsblk and cache for later use. */
	if (! isset($lsblk_file_types[$dev])) {
		$lsblkOutput = timed_exec(0.5, "/bin/lsblk -o NAME,FSTYPE -n -l -p -e 7,11 2>/dev/null | /usr/bin/grep -v 'crypto_LUKS'");

		$lines = explode(PHP_EOL, trim($lsblkOutput));
		$new_file_types = [];

		/* Get the devices and file types into a global array. */
		foreach ($lines as $line) {
			$parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
			if (count($parts) == 2) {
				$device						= $parts[0];
				$fileType					= $parts[1];
				$new_file_types[$device]	= $fileType;
			}
		}

		/* Update the lsblk array. */
		$lsblk_file_types	= $new_file_types;
	}

	/* Check if the device exists in the array. */
	$file_type = $lsblk_file_types[$dev] ?? "";

	/* Set $rc to the file system type or "luks" if not found. */
	$luks		= (strpos($dev, "/dev/mapper/") !== false);
	$rc			= ($file_type === "zfs_member") ? "zfs" : ($file_type ? $file_type : ($luks ? "luks" : ""));

	return $rc;
}

/* Find the file system of a zvol device. */
function zvol_fs_type($dev) {

	/* Get the file system type from blkid for a zfs volume. */
	$rc	= trim(timed_exec(0.5, "/sbin/blkid -s TYPE -o value ".escapeshellarg($dev)." 2>/dev/null") ?? "");

	return $rc;
}

#########################################################
############		CONFIG FUNCTIONS		#############
#########################################################

/* Get device configuration parameter. */
function get_config($serial, $variable) {
	global $ud_config;

	return $ud_config[$serial][$variable] ?? "";
}

/* Set device configuration parameter. */
function set_config($serial, $variable, $value) {
	global $paths, $ud_config;

	/* Verify we have a serial number. */
	if ($serial) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("cfg");

		/* Make file changes. */
		$config_file	= $paths['config_file'];

		$ud_config[$serial][$variable] = $value;
		save_ini_file($config_file, $ud_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= $ud_config[$serial][$variable] ?? "";
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Is device set to auto mount? */
function is_automount($serial, $usb = false) {
	$auto			= get_config($serial, "automount");
	$auto_usb		= get_config("Config", "automount_usb");
	$pass_through	= get_config($serial, "pass_through");

	return ( (($pass_through != "yes") && (($auto == "yes") || ($usb && $auto_usb == "yes"))) );
}

/* Is device set to mount read only? */
function is_read_only($serial, $default = false, $part = "") {
	$read_only		= "read_only".($part ? ".$part" : "");
	$read_only		= get_config($serial, $read_only);
	$pass_through	= get_config($serial, "pass_through");

	return ( $pass_through != "yes" && $read_only == "yes" ) ? true : (($read_only == "no") ? false : $default);
}

/* Is device set to pass through. */
function is_pass_through($serial, $part = "") {
	$pass_through	= "pass_through".($part ? ".$part" : "");
	$passed_through	= get_config($serial, $pass_through);

	return ($passed_through == "yes") ? true : (($passed_through == "no") ? false : false);
}

/* Is disable mount button set. */
function is_disable_mount($serial, $part = "") {
	$disable_mount	= "disable_mount".($part ? ".$part" : "");

	return (get_config($serial, $disable_mount) == "yes");
}

/* Toggle auto mount on/off. */
function toggle_automount($serial, $status) {
	global $paths, $ud_config;

	/* Verify we have a serial number. */
	if ($serial) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("cfg");

		/* Make file changes. */
		$config_file	= $paths['config_file'];

		$ud_config[$serial]["automount"] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $ud_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($ud_config[$serial]["automount"] == "yes") ? "true" : "false";
	} else {
		$rc = false;
	}

	return $rc;
}

/* Toggle read only on/off. */
function toggle_read_only($serial, $status, $part = "") {
	global $paths, $ud_config;

	/* Verify we have a serial number. */
	if ($serial) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("cfg");

		/* Make file changes. */
		$config_file	= $paths['config_file'];

		$read_only		= "read_only".($part ? ".$part" : "");
		$ud_config[$serial][$read_only] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $ud_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc = ($ud_config[$serial][$read_only] == "yes") ? "true" : "false";
	} else {
		$rc = false;
	}

	return $rc;
}

/* Toggle pass through on/off. */
function toggle_pass_through($serial, $status, $part = "") {
	global $paths, $ud_config;

	/* Verify we have a serial number. */
	if ($serial) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("cfg");

		/* Make file changes. */
		$config_file	= $paths['config_file'];

		$pass_through	= "pass_through".($part ? ".$part" : "");
		$ud_config[$serial][$pass_through] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $ud_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc = ($ud_config[$serial][$pass_through] == "yes") ? "true" : "false";
	} else {
		$rc = false;
	}

	return $rc;
}

/* Toggle hide mount button on/off. */
function toggle_disable_mount($serial, $status, $part = "") {
	global $paths, $ud_config;

	/* Verify we have a serial number. */
	if ($serial) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("cfg");

		/* Make file changes. */
		$config_file	= $paths['config_file'];

		$disable_mount	= "disable_mount".($part ? ".$part" : "");
		$ud_config[$serial][$disable_mount] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $ud_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc = ($ud_config[$serial][$disable_mount] == "yes") ? "true" : "false";
	} else {
		$rc = false;
	}

	return $rc;
}

/* Execute the device script. */
function execute_script($info, $action, $testing = false) { 
	global $paths;

	$rc = false;

	/* Set environment variables. */
	putenv("ACTION=".$action);
	foreach ($info as $key => $value) {
		/* Only set the environment variables used by the device script. */
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
				putenv(strtoupper($key)."=".$value);
			break;
		}
	}

	/* Set the device devX designation. */
	$device	= $info['fstype'] != "crypto_LUKS" ? $info['device'] : $info['luks'];
	$ud_dev = get_disk_dev(MiscUD::base_device(basename($device)));
	putenv("UD_DEVICE=".$ud_dev);

	/* Run the command in the background? */
	$bg				= (($info['command_bg'] != "false") && ($action == "ADD")) ? "&" : "";

	/* Execute the common script if it is defined. */
	if (($action == "ADD") && ($common_cmd = trim(escapeshellcmd(get_config("Config", "common_cmd"))))) {

		if (is_file($common_cmd)) {
			$common_script	= $paths['scripts'].basename($common_cmd);
			copy($common_cmd, $common_script);
			@chmod($common_script, 0755);
			unassigned_log("Running common script: '".basename($common_script)."'");

			/* Apply escapeshellarg() to the command. */
			$cmd = escapeshellarg($common_script)." > /dev/null 2>&1 ".$bg;

			/* Run the script. */
			$out	= null;
			$return	= null;
			exec($cmd, $out, $return);
			if ($return) {
				unassigned_log("Error: common script failed: '".$return."'");
			}
		} else {
			unassigned_log("Common Script file '".$common_cmd."' is not a valid file!");
		}
	}

	/* If there is a command, execute the script. */
	$cmd			= $info['command'];
	$enable_script	= ($info['enable_script'] != "false") ? true : false;
	if (file_exists($cmd)) {
		$command_script = $paths['scripts'].basename($cmd);
		if ($enable_script) {
			if (is_file($cmd)) {
				/* Is the device script currently running? */
				$script_running = is_script_running($cmd);

				/* If script is not running, copy to /tmp, change permissions, execute the script. */
				if ((! $script_running) || (($script_running) && ($action != "ADD"))) {
					unassigned_log("Running device script: '".basename($cmd)."' with action '".$action."'.");

					copy($cmd, $command_script);
					@chmod($command_script, 0755);

					if (! $testing) {
						if (($action == "REMOVE") || ($action == "ERROR_MOUNT") || ($action == "ERROR_UNMOUNT")) {
							sleep(1);
						}
						$clear_log	= ($action == "ADD") ? " > " : " >> ";

						/* Apply escapeshellarg() to the command and logfile of the command. */
						$cmd		= escapeshellarg($command_script).$clear_log.escapeshellarg($info['logfile'])." 2>&1 ".$bg;

						/* Run the script. */
						$out		= null;
						$return		= null;
						exec($cmd, $out, $return);
						if ($return) {
							unassigned_log("Error: device script failed: '".$return."'");
						}
					} else {
						$rc			= $command_script;
					}
				} else {
					unassigned_log("Device script '".basename($cmd)."' is already running!");
				}
			} else {
				unassigned_log("Script file '".$command_script."' is not a valid file!");
			}
		} else if ($action == "ADD") {
			unassigned_log("Device script '".basename($cmd)."' is not enabled!");
		}
	}

	return $rc;
}

/* Remove a historical disk configuration. */
function remove_config_disk($serial) {
	global $paths, $ud_config;

	/* Get the all disk configurations. */
	/* Get a lock so file changes can be made. */
	$lock_file		= get_file_lock("cfg");

	/* Make file changes. */
	$config_file	= $paths['config_file'];

	if ( isset($ud_config[$serial]) ) {
		unassigned_log("Removing configuration '".$serial."'.");
	}

	/* Remove this configuration. */
	unset($ud_config[$serial]);

	/* Resave all disk configurations. */
	save_ini_file($config_file, $ud_config);

	/* Release the file lock. */
	release_file_lock($lock_file);

	return (! isset($ud_config[$serial]));
}

/* Is disk device an SSD? */
function is_disk_ssd($dev) {

	$rc		= false;

	/* Get the base device - remove the partition number. */
	$device	= MiscUD::base_device(basename($dev));
	if (! MiscUD::is_device_nvme($device)) {
		$file = "/sys/block/".basename($device)."/queue/rotational";
		$rc = (exec("/bin/cat ".escapeshellarg($file)." 2>/dev/null") == 0);
	} else {
		$rc = true;
	}

	return $rc;
}

/* Is the device designation a 'devX' device? */
function is_dev_device($dev) {

	return (strtoupper(substr($dev, 0, 3)) == "DEV");
}

/* Is the device designation a 'sdX' device? */
function is_sd_device($dev) {

	return (strtoupper(substr($dev, 0, 2)) == "SD");
}

#########################################################
############		MOUNT FUNCTIONS			#############
#########################################################
/* Is a device mounted? */
function is_mounted($dev, $dir = "", $update = true) {
	global $mounts;

	/* Create a global array of device, mount point, and read only status from /-proc/mounts. */
	$rc_dev		= ($dir) ? (! $dev) : false;
	$rc_dir		= ($dev) ? (! $dir) : false;

	/* See if we need to load the /proc/mounts file to memory. */
	if ((! isset($mounts)) || ($update)) {
		$mount				= timed_exec(1, "/bin/awk -F'[, ]' '{print $1 \",\" $2 \",\" $4}' /proc/mounts 2>/dev/null");
		$escapeSequences	= array("\\040");
		$replacementChars	= array(" ");
		$mount				= str_replace($escapeSequences, $replacementChars, $mount);

		/* Create a two element array of the values. */
		$lines	= explode("\n", $mount);
		$new_mounts		= [];

		/* Break down each line into the key (mount point) and values (device and read only). */
		foreach ($lines as $line) {
			$parts = explode(',', $line, 3);
			if (count($parts) === 3) {
				$device							= trim($parts[0]);
				$mount_point					= trim($parts[1]);
				$read_only						= trim($parts[2]);

				$new_mounts[$mount_point]['device']		= $device;
				$new_mounts[$mount_point]['read_only']	= ($read_only == "ro");
			}
		}

		/* Update the global mounts array. */
		$mounts		= $new_mounts;
	}

	/* Check for mounted status of device. */
	if ($dev) {
		foreach ($mounts as $k => $v) {
			if ($v['device'] === $dev) {
				$rc_dev	= true;
				break;
			}
		}
	}

	/* Check for mounted status of the mount point. */
	if ($dir) {
		$rc_dir		= array_key_exists($dir, $mounts);
	}

	return ($rc_dev && $rc_dir);
}

/* Is a device mounted read only? */
function is_mounted_read_only($dir) {
	global $mounts;

	$rc		= $mounts[$dir]['read_only'] ?? false;

	return ($rc);
}

/* Get the mount parameters based on the file system. */
function get_mount_params($fs, $dev, $ro = false) {
	global $paths;

	$rc				= "";
	if (($fs != "cifs") && ($fs != "nfs") && ($fs != "root")) {
		$discard 		= ((get_config("Config", "discard") == "yes") && (is_disk_ssd($dev))) ? ",discard" : "";
	}
	$rw					= $ro ? "ro" : "rw";
	switch ($fs) {
		case 'hfsplus':
			$rc = "force,{$rw},users,umask=000";
			break;

		case 'btrfs':
			$rc = "{$rw},relatime,space_cache=v2{$discard}";
			break;

		case 'xfs':
			$rc = "{$rw},relatime{$discard}";
			break;

		case 'zfs':
			$rc = "{$rw},relatime";
			break;

		case 'exfat':
			$rc = "{$rw},relatime,nodev,nosuid,umask=000";
			break;

		case 'vfat':
			$rc = "{$rw},relatime,nodev,nosuid,iocharset=utf8,umask=000";
			break;

		case 'ntfs':
			$rc = "{$rw},relatime,nodev,nosuid,nls=utf8,umask=000";
			break;

		case 'ext4':
			$rc = "{$rw},relatime,nodev,nosuid{$discard}";
			break;

		case 'cifs':
			$credentials_file = "{$paths['credentials']}_".basename($dev);
			$rc = "{$rw},relatime,noserverino,nounix,iocharset=utf8,file_mode=0777,dir_mode=0777,uid=99,gid=100%s,credentials=".escapeshellarg($credentials_file);
			break;

		case 'nfs':
			$rc = "{$rw},soft,relatime,retrans=4,timeo=300";
			break;

		case 'root':
			$rc = "{$rw},bind,relatime";
			break;

		default:
			$rc = "{$rw},relatime";
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
		$fstype		= part_fs_type($info['device']);
		$mounted	= $info['mounted'];
		if (! $mounted) {
			$luks		= basename($info['device']);
			$discard	= is_disk_ssd($info['luks']) ? "--allow-discards" : "";
			$cmd		= "luksOpen $discard ".escapeshellarg($info['luks'])." ".escapeshellarg($luks);
			$pass		= decrypt_data(get_config($info['serial'], "pass"));
			if (! $pass) {
				$o		= shell_exec("/usr/local/sbin/emcmd cmdCryptsetup=".escapeshellarg($cmd)." 2>&1");

				/* Check for the mapper file existing. If it's not there, unraid did not open the luks disk. */
				if (! file_exists($info['device'])) {
					$o	= "Error: Passphrase or Key File not found.";
				}
			} else {
				$luks_pass_file = $paths['luks_pass']."_".$luks;
				@file_put_contents($luks_pass_file, $pass);
				unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/sbin/cryptsetup ".escapeshellcmd($cmd)." -d ".escapeshellarg($luks_pass_file)." 2>&1");
				exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
				unset($pass);
			}


			if ($o && stripos($o, "warning") === false) {
				unassigned_log("luksOpen result: ".$o);
				exec("/sbin/cryptsetup luksClose ".escapeshellarg($info['device'])." 2>/dev/null");
			} else {
				/* Mount an encrypted disk. */
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

	$rc				= false;
	$dev			= $info['device'];
	$dir			= $info['mountpoint'];
	$fs				= $info['fstype'];
	$ro				= $info['read_only'];
	$file_system	= $fs;
	$pool_name		= ($info['pool_name']) ? $info['pool_name'] : MiscUD::zfs_pool_name($dev);
	$mounted		= $info['mounted'];

	if (! $mounted) {
		if ($fs) {
			$recovery = "";
			if ($fs != "crypto_LUKS") {
				if ($fs == "apfs") {
					/* See if there is a disk password. */
					$password	= decrypt_data(get_config($info['serial'], "pass"));
					if ($password) {
						$recovery = ",pass='".$password."'";
					}
					$vol		= ",vol=".(get_config($info['serial'], "volume.".$info['part']) ?: "0");
					$cmd		= "/usr/bin/apfs-fuse -o uid=99,gid=100,allow_other{$vol}{$recovery} ".escapeshellarg($dev)." ".escapeshellarg($dir);
				} else if ($fs == "zfs") {
					/* Mount a zfs pool device. */
					if ($pool_name) {
						exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
						exec("/usr/sbin/zpool import -N ".escapeshellarg($pool_name)." 2>/dev/null");
						exec("/usr/sbin/zfs set mountpoint=".escapeshellarg($dir)." ".escapeshellarg($pool_name)." 2>/dev/null");

						$params		= get_mount_params($fs, $dev, $ro);
						$cmd		= "/usr/sbin/zfs mount -o $params ".escapeshellarg($pool_name);
					} else {
						unassigned_log("Warning: Cannot determine Pool Name of '".$dev."'");
						return false;
					}
				} else if ($fs == "zvol") {
					$z_fstype	= part_fs_type($dev);
					$z_fstype	= ($z_fstype) ?: zvol_fs_type($dev);
					$params		= get_mount_params($z_fstype, $dev, $ro);
					$cmd		= "/sbin/mount -t ".escapeshellarg($z_fstype)." -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
				} else {
					$params		= get_mount_params($fs, $dev, $ro);
					$cmd		= "/sbin/mount -t ".escapeshellarg($fs)." -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
				}
			} else {
				/* Physical device being mounted. */
				$device = $info['luks'];

				/* Find the file system type on the luks device to use the proper mount options. */
				$mapper			= $info['device'];

				/* Now that the luks device is opened, we can get the file system. */
				$file_system	= part_fs_type($mapper);

				if ($file_system != "zfs") {
					$params	= get_mount_params($file_system, $device, $ro);
					if ($file_system) {
						$cmd = "/sbin/mount -t ".escapeshellarg($file_system)." -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
					} else {
						$cmd = "/sbin/mount -o $params ".escapeshellarg($dev)." ".escapeshellarg($dir);
					}
				} else {
					/* Mount a zfs pool device. */
					/* After the luks device is opened, we can get the pool name. */
					$pool_name	= MiscUD::zfs_pool_name($dev);
					if ($pool_name) {
						exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
						exec("/usr/sbin/zpool import -N ".escapeshellarg($pool_name)." 2>/dev/null");
						exec("/usr/sbin/zfs set mountpoint=".escapeshellarg($dir)." ".escapeshellarg($pool_name)." 2>/dev/null");
					}
					$params		= get_mount_params($file_system, $device, $ro);
					$cmd		= "/usr/sbin/zfs mount -o $params ".escapeshellarg($pool_name);
				}
			}
			$cmd = str_replace($recovery, ", pass='*****'", ($cmd ?? ""));

			unassigned_log("Mount cmd: ".$cmd);

			/* apfs file system requires UD+ to be installed. */
			if (($fs == "apfs") && (! is_file("/usr/bin/apfs-fuse"))) {
				$o = "Install Unassigned Devices Plus to mount an apfs file system";
			} else if (($file_system == "zfs") && (! is_file("/usr/sbin/zfs"))) {
				$o = "Unraid 6.12 or later is needed to mount a zfs file system";
			} else {
				/* Create mount point and set permissions. */
				if (! is_dir($dir)) {
					@mkdir($dir, 0777, true);
				}

				/* If the pool name cannot be found, we cannot mount the pool. */
				if (($file_system == "zfs") && (! $pool_name)) {
					$o = "Warning: Cannot determine Pool Name of '".$dev."'";
				} else {
					/* Do the mount command. */
					$o = shell_exec(escapeshellcmd($cmd)." 2>&1");
				}
			}

			/* Do some cleanup if we mounted an apfs disk, */
			if ($fs == "apfs") {
				/* Remove all password variables. */
				unset($password);
				unset($recovery);
				unset($cmd);
			}

			if (($file_system == "zfs") && (! $pool_name)) {
				$o = "Warning: Cannot determine Pool Name of '".$dev."'";
			} else {
				/* Let the mount settle. */
				usleep(250 * 1000);

				/* Check to see if the device really mounted. */
				if (($file_system == "zfs") && ($pool_name)) {
					/* For zfs devices, the pool name is the device. */
					$mount_dev		= $pool_name;
				} else if ($file_system == "btrfs") {
					/* If the btrfs secondary pool device is being mounted, don't check the device is mounted. */
					$mount_dev		= "";
				} else {
					$mount_dev		= $dev;
				}
				for ($i=0; $i < 5; $i++) {
					/* The device and mount point need to be mounted. */
					$mounted	= is_mounted($mount_dev, $dir);
					if ($mounted) {
						if (! is_mounted_read_only($dir)) {
							exec("/bin/chmod 0777 ".escapeshellarg($dir)." 2>/dev/null");
							exec("/bin/chown 99 ".escapeshellarg($dir)." 2>/dev/null");
							exec("/bin/chgrp 100 ".escapeshellarg($dir)." 2>/dev/null");
						}
						unassigned_log("Successfully mounted '".$dev."' on '".$dir."'.");

						$rc = true;

						/* Set zfs readonly flag based on device read only setting. */
						if ($file_system == "zfs") {
							exec("/usr/sbin/zfs set readonly=".($ro ? 'on' : 'off')." ".escapeshellarg($pool_name)." 2>/dev/null");
						}

						break;
					} else {
						usleep(100 * 1000);
					}
				}
			}

			/* If the device did not mount, close the luks disk if the FS is luks, and show an error. */
			if (! $rc) {
				if ($fs == "crypto_LUKS" ) {
					exec("/sbin/cryptsetup luksClose ".escapeshellarg($info['device'])." 2>/dev/null");
				}
				unassigned_log("Mount of '".basename($dev)."' failed: '".$o."'");

				/* Remove the mount point. */
				exec("/bin/rmdir ".escapeshellarg($dir)." 2>/dev/null");

				if ($pool_name) {
					/* Export the pool so it will mount later. */
					exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
				}
			} else {
				if ($info['fstype'] == "btrfs") {
					/* Update the btrfs state file for single scan for pool devices. */
					$pool_state			= MiscUD::get_json($paths['pool_state']);
					$pool_state[$dir]	= [];
					MiscUD::save_json($paths['pool_state'], $pool_state);
				}

				/* Ntfs is mounted but is most likely mounted r/o. Display the mount command warning. */
				if ($o && ($fs == "ntfs")) {
					unassigned_log("Mount warning: ".$o);
				}

				/* If file system is zfs, mount any datasets. */
				if ($file_system == "zfs") {
					$data	= shell_exec("/usr/sbin/zfs list -H -o name,mountpoint | /usr/bin/grep ".escapeshellarg($pool_name)." | /bin/awk -F'\t' '$2 != \"-\" && $2 != \"legacy\" {print $1 \",\" $2}'");

					$rows	= explode("\n", $data);

					/* Check for any potential datasets to mount. */
					if (count($rows) > 2) {
						unassigned_log("Mounting zfs datasets...");

						/* Check each dataset to see if can be mounted. */
						foreach ($rows as $dataset) {
							$columns		= explode(',', $dataset);

							/* The dataset (folder) must exist before it can be mounted. */
							if ((count($columns) == 2) && ($columns[0] != $pool_name)) {
								/* Mount the dataset if it did not automount. */
								if (! is_mounted($columns[0], $columns[1])) {
									/* Mount the dataset. */
									$params	= get_mount_params($file_system, $pool_name, $ro);
									$cmd = "/usr/sbin/zfs mount -o ".$params." ".escapeshellarg($columns[0]);

									unassigned_log("Mount dataset cmd: ".$cmd);

									/* Do the mount command. */
									$o		= shell_exec(escapeshellcmd($cmd)." 2>&1");
									$rc		= false;
									if (! $o) {
										/* Let the mount settle. */
										usleep(250 * 1000);

										/* Check to see if the dataset really mounted. */
										for ($i=0; $i < 5; $i++) {
											/* The device and mount point need to be mounted. */
											if (is_mounted($columns[0], $columns[1])) {
												unassigned_log("Successfully mounted zfs dataset '".$columns[0]."' on '".$columns[1]."'.");

												$rc = true;

												break;
											} else {
												usleep(100 * 1000);
											}
										}
									}

									/* Was there an error? */
									if (! $rc) {
										unassigned_log("Mount of zfs dataset '".$columns[0]."' failed: '".$o."'");
									}
								} else {
									unassigned_log("Dataset '".$columns[0]."' already mounted");
								}
							}
						}
					}
				}
			}
		} else {
			unassigned_log("No file system detected on '".basename($dev)."'.");
		}
	} else {
		unassigned_log("Partition '".basename($dev)."' is already mounted.");
	}

	return $rc;
}

/* Mount root share. */
function do_mount_root($info) {
	global $docroot, $paths, $var;

	$rc		= false;

	/* A rootshare device is treated similar to a CIFS mount. */
	if ($var['shareDisk'] != "yes") {
		/* Be sure the server online status is current. */
		$is_alive = $info['alive'];

		/* If the root server is not online, run the ping update and see if ping status needs to be refreshed. */
		if (! $is_alive) {
			/* Update the root share server ping status. */
			exec($docroot."/plugins/unassigned.devices/scripts/get_ud_stats ping");

			/* See if the root share server is online now. */
			$is_alive = is_samba_server_online($info['ip'], $info['protocol']);
		}
	
		/* If server shows as being on-line, we can mount the rootshare. */
		if ($is_alive) {
			$dir		= $info['mountpoint'];
			$fs			= $info['fstype'];
			$ro			= $info['read_only'];
			$dev		= str_replace("//".$info['ip'], "", $info['path']);
			if (! $info['mounted']) {
				/* Create the mount point and set permissions. */
				@mkdir($dir, 0777, true);
				@chown($dir, 99);
				@chgrp($dir, 100);

				$params	= get_mount_params($fs, $dev, $ro);
				$cmd	= "/sbin/mount -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

				unassigned_log("Mount ROOT command: ".$cmd);

				/* Mount the root share. */
				$o		= timed_exec(10, $cmd." 2>&1");
				if ($o) {
					unassigned_log("Root mount failed: '".$o."'.");
				}

				/* Did the root share successfully mount? */
				if (is_mounted("", $dir)) {
					unassigned_log("Successfully mounted '".$dev."' on '".$dir."'.");

					$rc = true;
				} else {
					@rmdir($dir);
				}
			} else {
				unassigned_log("Root Share '".$dev."' is already mounted.");
			}
		} else {
			unassigned_log("Root Server '".$info['ip']."' is offline and remote share '".$info['path']."' cannot be mounted."); 
		}
	} else {
		unassigned_log("Error: Root Server share '".$info['device']."' cannot be mounted with Disk Sharing enabled."); 
	}

	return $rc;
}

/* Unmount a device. */
function do_unmount($info, $force = false) {
	global $paths;

	/* Default return value - failure. */
	$rc			= false;

	/* Initialize some variables. */
	$smb				= false;
	$nfs				= false;
	$zfs				= false;
	$unmount_type		= "";
	$unmount_mode		= ($force ? "-f " : "-l ");

	switch ($info['fstype']) {
		case ("cifs"):
			$smb			= true;
			$dev			= $info['mount_dev'];
			$timeout		= ($force ? 10 : 30);
			$unmount_type	= "-t cifs ";
			break;

		case ("nfs"):
			$nfs			= true;
			$dev			= $info['mount_dev'];
			$timeout		= ($force ? 10 : 30);
			$unmount_type	= "-t nfs ";
			break;

		case ("root"):
			$unmount_mode	= "-l ";
		case ("loop"):
			$dev			= "";
			$timeout		= 10;
			break;

		default:
			if ($info['file_system'] == "zfs") {
				$zfs		= true;
				$pool_name	= $info['pool_name'];
			} else {
				$pool_name	= "";
			}
			$dev			= $info['device'];
			$timeout		= 90;
			break;
	}

	$dir		= $info['mountpoint'];

	$mounted	= (($zfs) && ($pool_name)) ? (is_mounted($pool_name, $dir)) : (is_mounted($dev, $dir));
	if ($mounted) {
		/* Are we shutting down Unraid? */
		if (((! $force) || (($force) && (! $smb) && (! $nfs))) && (! is_mounted_read_only($dir))) {
			unassigned_log("Synching file system on '".$dir."'.");
			if ($zfs) {
				if (! $force) {
					/* Sync the file system and wait for it to be done. */
					exec("/usr/sbin/zpool sync ".escapeshellarg($pool_name)." 2>/dev/null");
				} else {
					/* Time out so sync command doesn't get stuck. */
					timed_exec($timeout, "/usr/sbin/zpool sync ".escapeshellarg($pool_name)." 2>/dev/null");
				}
			} else if ((! $force) && (! $smb) && (! $nfs)) {
				/* Sync the file system and wait for it to be done. */
				exec("/bin/sync -f ".escapeshellarg($dir)." 2>/dev/null");
			} else {
				/* Time out so sync command doesn't get stuck. */
				timed_exec($timeout, "/bin/sync -f ".escapeshellarg($dir)." 2>/dev/null");
			}
		}

		if ($zfs) {
			/* Clear readonly flag. */
			exec("/usr/sbin/zfs set readonly=off ".escapeshellarg($pool_name)." 2>/dev/null");

			/* Unmount zfs file system. */
			$cmd = ("/usr/sbin/zfs unmount ".escapeshellarg($dir)." 2>&1");
		} else {
			/* The umount flags are set depending on the unmount conditions.  When the array is being stopped force will
			   be set.  This helps to keep unmounts from hanging. */
			$cmd = "/sbin/umount ".$unmount_type.$unmount_mode.escapeshellarg($dir)." 2>&1";
		}

		unassigned_log("Unmount cmd: ".$cmd);

		if (($zfs) && (! $pool_name)) {
			$o = "Warning: Cannot determine Pool Name of '".$dev."'";
		} else {
			/* Execute the unmount command. */
			$o = timed_exec($timeout, $cmd);

			/* Let the unmount settle. */
			usleep(250 * 1000);

			/* Check to see if the device really unmounted. */
			for ($i=0; $i < 5; $i++) {
				/* The device and mount point both need to be unmounted. */
				if (($zfs) && ($pool_name)) {
					$mounted_dev	= $pool_name;
				} else {
					$mounted_dev	= $dev;
				}

				$mounted	= is_mounted($mounted_dev, $dir);
				if (! $mounted) {
					if (is_dir($dir)) {
						/* Remove the mount point. */
						exec("/bin/rmdir ".escapeshellarg($dir)." 2>/dev/null");

						/* Remove the legacy symlink on /mnt/disks/. */
						$link = $paths['usb_mountpoint']."/".basename($dir);
						if (is_link($link)) {
							@unlink($link);
						}
					}

					unassigned_log("Successfully unmounted '".($dev ? $dev : $dir)."'");

					/* Remove saved pool devices if this is a btrfs pooled device. */
					MiscUD::get_pool_devices($dir, true);

					$rc = true;
					break;
				} else {
					usleep(100 * 1000);
				}
			}
		}

		if (! $rc) {
			unassigned_log("Unmount of '".($dev ? $dev : $dir)."' failed: '".$o."'"); 
		} else if ($zfs) {
			exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
		}
	} else {
		if (($zfs) && (! $pool_name)) {
			unassigned_log("Warning: Cannot determine Pool Name of '".$dev."'");
		}
		unassigned_log("Cannot unmount '".($dev ? $dev : $dir)."'. UD did not mount the device or it was not properly unmounted.");
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

	return (($share == "yes") || ($usb && $auto_usb == "yes")); 
}

/* Toggle samba share on/off. */
function toggle_share($serial, $part, $status) {
	$new 	= ($status == "true") ? "yes" : "no";
	set_config($serial, "share.{$part}", $new);

	return ($new == "yes");
}

/* Add mountpoint to samba shares. */
function add_smb_share($dir, $recycle_bin = false, $fat_fruit = false) {
	global $docroot, $paths, $var, $users, $ud_config;

	/* Get the current UD configuration. */
	$config							= $ud_config["Config"];

	/* Initialize some settings to make sure they are defined. */
	$smb_security					= $config['smb_security'] ?? "";
	$config['force_user']			= $config['force_user'] ?? "";
	$config['hidden_share']			= $config['hidden_share'] ?? "";

	/* Force user setting. */
	$force_user 					= ($config['force_user'] == "yes") ? "\n\tforce User = nobody" : "";

	/* Get the time machine settings. */
	$config['time_machine']			= $config['time_machine'] ?? "";
	$time_machine 					= ($config['time_machine'] == "yes") ? "\n\tfruit:time machine = yes" : "";
	$Config['time_mach_vol_size']	= $config['time_mach_vol_size'] ?? "";
	$time_mach_vol_size				= (($config['time_machine'] == "yes") && ($config['time_mach_vol_size'])) ? "\n\tfruit:time machine max size = ".intval($config['time_mach_vol_size'])."M" : "";

	/* Set whether or not the share is browseable and set browseable setting. */
	$hidden_share					= ($smb_security == "hidden") ? "\n\tbrowseable = no" : "\n\tbrowseable = yes";

	/* Is the Mac OS interoperability setting on? */
	$enable_fruit					= ($var['enableFruit'] == "yes");

	/* Add mountpoint to samba shares. */
	if ($var['shareSMBEnabled'] != "no") {
		if ($smb_security != "no") {
			/* Remove special characters from share name. */
			$share_name		= str_replace( array("(", ")"), "", basename($dir));

			/* Initialize the vfs_objects variable with the dirsort option. */
			$vfs_objects	= "vfs objects = dirsort";

			/* Add the Mac OS stuff if enabled. */
			if ($enable_fruit) {
				if (! $fat_fruit) {
					/* See if the smb-fruit.conf from the /boot/config/ folder. */
					$fruit_file = "/boot/config/smb-fruit.conf";
					if (file_exists($fruit_file)) {
						$fruit_file_settings = explode("\n", file_get_contents($fruit_file));
					} else {
						/* Use the smb-fruit.conf from the /etc/samba/ folder. */
						$fruit_file = "/etc/samba/smb-fruit.conf";
						if (file_exists($fruit_file)) {
							$fruit_file_settings = explode("\n", file_get_contents($fruit_file));
						} else {
							$vfs_objects	.= " catia fruit streams_xattr";
							$fruit_file_settings = array( $vfs_objects );
						}

						/* Set up time machine parameters. */
						if ($config['time_machine'] == "yes") {
							$fruit_file_settings[] = $time_machine;
							$fruit_file_settings[] = $time_mach_vol_size;
							$fruit_file_settings[] = "\n\tfruit:metadata = stream";
						}
					}
				} else {
					/* For fat and exfat file systems. */
					$vfs_objects		.= " catia fruit";
					$fruit_file_settings = array( $vfs_objects );
				}

				/* Apply the fruit settings. */
				$vfs_objects	= "";
				foreach ($fruit_file_settings as $f) {
					/* Remove comment lines. */
					if (($f) && (strpos($f, "#") === false)) {
						$vfs_objects .= "\n\t".$f;
					}
				}
			} else {
				$vfs_objects	= "\n\t".$vfs_objects;
			}

			if (($smb_security == "yes") || ($smb_security == "hidden")) {
				$read_users		= [];
				$write_users	= [];
				$valid_users	= array_keys($users);;

				/* Remove the root user. */
				$valid_users	= array_diff($valid_users, ["root"]);

				/* Get the valid users from the UD config, and create an array of read and write users. */
				$invalid_users = array_filter($valid_users, function ($v) use ($config, &$read_users, &$write_users) {
					switch ($config["smb_$v"]) {
						case "read-only":
							$read_users[] = $v;
							break;
						case "read-write":
							$write_users[] = $v;
							break;
						default:
							return $v;
					}
				});

				/* Remove the invalid users. */
				$valid_users		= array_diff($valid_users, $invalid_users);

				/* File name case settings. */
				$case_setting		= (($config["case_names"] === 'force') || (empty($config["case_names"]))) ? "auto" : $config["case_names"];
				$case_names			= "\n\tcase sensitive = ".$case_setting."\n\tpreserve case = yes\n\tshort preserve case = yes";

				/* Add the valid users and their access. */
				if (count($valid_users)) {
					$valid_users	= "\n\tvalid users = ".implode(' ', $valid_users);
					$write_users	= count($write_users) ? "\n\twrite list = ".implode(' ', $write_users) : "";
					$read_users		= count($read_users) ? "\n\tread list = ".implode(' ', $read_users) : "";
					$share_cont		= "[{$share_name}]\n\tcomment = {$share_name}\n\tpath = {$dir}{$hidden_share}{$force_user}{$valid_users}{$write_users}{$read_users}{$vfs_objects}{$case_names}";
				} else {
					$share_cont 	= "[{$share_name}]\n\tpath = {$dir}{$hidden_share}\n\tinvalid users = @users";
					unassigned_log("Warning: No valid smb users defined. Share '{$dir}' cannot be accessed with smb.");
				}
			} else {
				$share_cont = "[{$share_name}]\n\tpath = {$dir}\n\tread only = No\n\tguest ok = Yes{$force_user}{$vfs_objects}";
			}

			if (! is_dir($paths['smb_usb_shares'])) {
				@mkdir($paths['smb_usb_shares'], 0755, true);
			}
			$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");

			unassigned_log("Adding SMB share '{$share_name}'.");

			@file_put_contents($share_conf, $share_cont);
			if (! (new MiscUD)->exist_in_file($paths['smb_unassigned'], $share_conf)) {
				$c		= (is_file($paths['smb_unassigned'])) ? @file($paths['smb_unassigned'], FILE_IGNORE_NEW_LINES) : [];
				$c[]	= "include = $share_conf";

				/* Do some cleanup. */
				$smb_unassigned_includes = array_unique(preg_grep("/include/i", $c));
				foreach($smb_unassigned_includes as $key => $inc) {
					if (! is_file(parse_ini_string($inc)['include'])) {
						unset($smb_unassigned_includes[$key]);
					}
				} 
				$c		= array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_unassigned_includes);
				$c		= preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
				@file_put_contents($paths['smb_unassigned'], $c);

				/* If the recycle bin plugin is installed, add the recycle bin to the share. */
				if ($recycle_bin) {
					/* Add the recycle bin parameters if plugin is installed */
					$recycle_script = $docroot."/plugins/recycle.bin/scripts/configure_recycle_bin";
					if (is_file($recycle_script)) {
						if (file_exists("/boot/config/plugins/recycle.bin/recycle.bin.cfg")) {
							$recycle_bin_cfg	= @parse_ini_file( "/boot/config/plugins/recycle.bin/recycle.bin.cfg" );
						} else {
							$recycle_nin_cfg	= [];
						}
						if ((isset($recycle_bin_cfg['INCLUDE_UD'])) && ($recycle_bin_cfg['INCLUDE_UD'] == "yes")) {
							if (is_file("/var/run/recycle.bin.pid")) {
								unassigned_log("Enabling the Recycle Bin on share '{$share_name}'.");
							}
							exec(escapeshellcmd("$recycle_script $share_conf"));
						}
					}
				}
			}

			/* Add the [global] tag to the end of the share file. */
			@file_put_contents($share_conf, "\n[global]\n", FILE_APPEND);

			timed_exec(2, "/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
		} else {
			unassigned_log("Warning: Unassigned Devices are not set to be shared with SMB.");
		}
	}

	return true;
}

/* Remove mountpoint from samba shares. */
function rm_smb_share($dir) {
	global $paths;

	/* Remove special characters from share name */
	$share_name = str_replace( array("(", ")"), "", basename($dir));
	$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
	if (is_file($share_conf)) {
		unassigned_log("Removing SMB share '".$share_name."'.");
		@unlink($share_conf);
	}
	if (MiscUD::exist_in_file($paths['smb_unassigned'], $share_conf)) {
		$c = (is_file($paths['smb_unassigned'])) ? @file($paths['smb_unassigned'], FILE_IGNORE_NEW_LINES) : [];

		/* Do some cleanup. */
		$smb_unassigned_includes = array_unique(preg_grep("/include/i", $c));
		foreach($smb_unassigned_includes as $key => $inc) {
			if (! is_file(parse_ini_string($inc)['include'])) {
				unset($smb_unassigned_includes[$key]);
			}
		} 
		$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_unassigned_includes);
		$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
		@file_put_contents($paths['smb_unassigned'], $c);
		timed_exec(2, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) close-share ".escapeshellarg($share_name)." 2>&1");
		timed_exec(2, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
	}

	return true;
}

/* Add a mountpoint to NFS shares. */
function add_nfs_share($dir) {
	global $var;

	/* If NFS is enabled and export setting is 'yes' then add NFS share. */
	if ($var['shareNFSEnabled'] == "yes") {
		if (get_config("Config", "nfs_export") == "yes") {
			$reload = false;
			foreach (array("/etc/exports","/etc/exports-") as $file) {
				if (! MiscUD::exist_in_file($file, $dir)) {
					$c			= (is_file($file)) ? @file($file, FILE_IGNORE_NEW_LINES) : [];
					$fsid		= 200 + count(preg_grep("@^\"@", $c));
					$nfs_sec	= get_config("Config", "nfs_security");
					if ( $nfs_sec == "private" ) {
						$sec	= explode(";", get_config("Config", "nfs_rule"));
					} else {
						$sec[]	= "*(sec=sys,rw,insecure,anongid=100,anonuid=99,all_squash)";
					}
					foreach ($sec as $security) {
						if ($security) {
							$c[]		= "\"{$dir}\" -fsid={$fsid},async,no_subtree_check {$security}";
						}
					}
					$c[]		= "";
					@file_put_contents($file, implode(PHP_EOL, $c));
					$reload		= true;
				}
			}
			if ($reload) {
				unassigned_log("Adding NFS share '".$dir."'.");
				exec("/usr/sbin/exportfs -ra 2>/dev/null");
			}
		} else {
			unassigned_log("Warning: Unassigned Devices are not set to be shared with NFS.");
		}
	}

	return true;
}

/* Remove a mountpoint from NFS shares. */
function rm_nfs_share($dir) {

	/* Remove this disk from the exports file. */
	$reload = false;
	foreach (array("/etc/exports","/etc/exports-") as $file) {
		if ( MiscUD::exist_in_file($file, $dir) && strlen($dir)) {

			/* Read the contents of the file into an array. */
			$fileLines	= file($file, FILE_IGNORE_NEW_LINES);

			$updatedLines = array_filter($fileLines, function($line) use ($dir) {
				return (strpos($line, $dir) === false);
			});

			/* Add a final empty line. */
			$updatedLines[]	= "";

			/* Combine the updated lines into a single string. */
			$updatedContent = implode("\n", $updatedLines);

			/* Write the updated content back to the file. */
			file_put_contents($file, $updatedContent);

			$reload	= true;
		}
	}

	if ($reload) {
		unassigned_log("Removing NFS share '".$dir."'.");
		exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}

	return true;
}

/* Remove all samba and NFS shares for mounted devices. */
function remove_shares() {
	/* Disk mounts */
	foreach (get_unassigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			$info = get_partition_info($p);
			if ( ($info['mounted']) && ($info['shared']) ) {
				rm_smb_share($info['mountpoint']);
				rm_nfs_share($info['mountpoint']);
			}
		}
	}

	/* SMB Mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ( ($info['mounted']) && ($info['smb_share']) ) {
			rm_smb_share($info['mountpoint']);
			rm_nfs_share($info['mountpoint']);
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

/* Reload disk, samba and NFS shares. */
function reload_shares() {
	/* Disk mounts */
	foreach (get_unassigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			$info = get_partition_info($p);
			if ( $info['mounted'] && $info['shared'] ) {
				$fat_fruit = (($info['fstype'] == "vfat") || ($info['fstype'] == "exfat"));
				add_smb_share($info['mountpoint'], (! $info['read_only']), $fat_fruit);
				add_nfs_share($info['mountpoint']);
			}
		}
	}

	/* SMB Mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ( ($info['mounted']) && ($info['smb_share']) ) {
			add_smb_share($info['mountpoint'], $info['fstype'] == "root");
			add_nfs_share($info['mountpoint']);
		}
	}

	/* ISO File Mounts */
	foreach (get_iso_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			add_smb_share($info['mountpoint']);
			add_nfs_share($info['mountpoint']);
		}
	}
}

#########################################################
############		SAMBA FUNCTIONS			#############
#########################################################

/* Get samba mount configuration parameter. */
function get_samba_config($source, $variable) {
	global $samba_config;

	return $samba_config[$source][$variable] ?? "";
}

/* Set samba mount configuration parameter. */
function set_samba_config($source, $variable, $value) {
	global $paths, $samba_config;

	/* Verify we have a serial number. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("smb");

		/* Make file changes. */
		$config_file	= $paths['samba_mount'];

		$samba_config[$source][$variable] = $value;
		save_ini_file($config_file, $samba_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= (isset($samba_config[$source][$variable]));
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Encrypt data. */
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

	/* Encrypt the data using aes256. */
	$value	= trim(openssl_encrypt($data, 'aes256', $key, $options=0, $iv));

	return $value;
}

/* Decrypt data. */
function decrypt_data($data) {
	$key	= get_config("Config", "key");
	$iv		= get_config("Config", "iv");

	/* Decrypt the data using aes256. */
	$value = openssl_decrypt($data, 'aes256', $key, $options=0, $iv);

	/* Make sure the data is UTF-8 encoded. */
	if (! mb_check_encoding($value, 'UTF-8')) {
		unassigned_log("Warning: Data is not UTF-8 encoded");
		$value = "";
	}

	return $value;
}

/* Is the samba mount set for auto mount? */
function is_samba_automount($serial) {
	$auto	= get_samba_config($serial, "automount");

	return ($auto == "yes");
}

/* Is the samba mount set to share? */
function is_samba_share($serial) {
	$smb_share	= get_samba_config($serial, "smb_share");

	return ($smb_share == "yes");
}

/* Is disable mount enabled. */
function is_samba_disable_mount($serial) {
	$disable_mount	= get_samba_config($serial, "disable_mount");

	return ($disable_mount == "yes");
}

/* Is the samba mount set to read only? */
function is_samba_read_only($serial) {
	$smb_readonly	= get_samba_config($serial, "read_only");

	return ($smb_readonly == "yes");
}

/* Is the samba mount set to read only? */
function is_samba_encrypted($serial) {
	$smb_encrypt	= get_samba_config($serial, "encryption");

	return ($smb_encrypt == "yes");
}

/* Get all defined samba and NFS remote shares. */
function get_samba_mounts() {
	global $paths, $samba_config, $default_tld, $local_tld;

	$return			= [];

	/* Get all the samba devices from the configuration. */
	$samba_mounts	= $samba_config;
	if (is_array($samba_mounts)) {
		ksort($samba_mounts, SORT_NATURAL);

		/* Get all the samba mounts. */
		foreach ($samba_mounts as $device => $mount) {
			/* Convert the device to a safe name samba device. */
			$safe_device				= safe_name($device, false);
			$mount['device']			= $device;
			if ($device) {
				$mount['name']			= $safe_device;
				$mount['mountpoint']	= $mount['mountpoint'] ?? "";
				$mount['ip']			= $mount['ip'] ?? "";
				$mount['protocol']		= $mount['protocol'] ?? "";
				$mount['path']			= $mount['path'] ?? "";
				$mount['share']			= $mount['share'] ?? "";
				$mount['share']			= safe_name($mount['share'], false);

				/* Set the mount point and file system. */
				switch ($mount['protocol']) {
					case "NFS":
						$mount['fstype']	= "nfs";
						$path				= $mount['share'];
						break;

					case "ROOT":
						$mount['fstype']	= "root";
						$root_type			= $mount['share'] == "user" ? "Shares-Pools" : "Shares-NoPools";
						$path				= $mount['mountpoint'] ? $mount['mountpoint'] : $root_type;
						break;

					default:
						$mount['fstype']	= "cifs";
						$path				= $mount['share'];
						break;
				}

				/* This is the mount device for checking for an invalid configuration. */
				$dev_check				= ($mount['fstype'] == "nfs") ? $mount['ip'].":".$mount['path'] : "//".$mount['ip'].(($mount['fstype'] == "cifs") ? "/" : "") .$mount['path'];

				/* Remove the 'local' and 'default' tld reference as they are unnecessary. */
				if (! MiscUD::is_ip($mount['ip'])) {
					$dev_check			= str_replace( array(".".$local_tld, ".".$default_tld), "", $dev_check);
				}

				/* Is the remote server on line? */
				$mount['alive']			= is_samba_server_online($mount['ip'], $mount['protocol']);

				/* Is read only enabled? */
				$mount['read_only']		= is_samba_read_only($mount['name']);

				/* Is auto mount enabled? */
				$mount['automount']		= is_samba_automount($mount['name']);

				/* Is smb and nfs sharing enabled? */
				$mount['smb_share']		= is_samba_share($mount['name']);

				/* Is the mount button set disabled? */
				$mount['disable_mount']	= is_samba_disable_mount($mount['name']);

				if ($mount['fstype'] == "root") {
					$mount['mountpoint'] = $paths['root_mountpoint']."/".$path;
				} else {
					/* Determine the mountpoint for this remote share. */
					if (! $mount['mountpoint']) {
						$mount['mountpoint'] = $paths['remote_mountpoint']."/".$mount['ip']."_".$path;
					} else {
						$path = basename($mount['mountpoint']);
						$mount['mountpoint'] = $paths['remote_mountpoint']."/".$path;
					}
				}

				/* This is the device that is actually mounted based on the protocol and is used to check mounted status. */
				$mount['mount_dev']		= ($mount['fstype'] == "nfs") ? $mount['ip'].":".$mount['path'] : (($mount['fstype'] == "cifs") ? "//".$mount['ip']."/".$mount['path'] : $mount['mountpoint']);

				/* Check for mounting/unmounting state. */
				$mount_device			= basename($mount['ip'])."_".basename($mount['path']);

				$mount['mounting']		= MiscUD::get_mounting_status($mount_device);
				$mount['unmounting']	= MiscUD::get_unmounting_status($mount_device);

				/* Is remote share mounted? */
				if ($mount['fstype'] != "root") {
					$mount['mounted']		= is_mounted($mount['mount_dev'], $mount['mountpoint'], false);
				} else {
					$mount['mounted']		= is_mounted("", $mount['mount_dev'], false);
				}

				/* Is the remote share mounted read only? */
				$mount['remote_read_only']	= is_mounted_read_only($mount['mountpoint']);

				/* Check that the device built from the ip and path is consistent with the config file device. */
				$check_device			= safe_name($dev_check, false);

				/* Remove dollar signs in device.  Windows uses a '$' to indicate a hidden folder. */
				$check_device			= str_replace("$", "", $check_device);

				/* If this is a legacy samba mount or is misconfigured, indicate that it should be removed and added back. */
				$mount['invalid']		= (($safe_device != $device) || ($safe_device != $check_device));

				/* Get the disk size, used, and free stats. */
				$stats					= get_device_stats($mount['mountpoint'], $mount['mounted'], $mount['alive']);
				$mount['size']			= $stats[0]*1024;
				$mount['used']			= $stats[1]*1024;
				$mount['avail']			= $stats[2]*1024;

				/* If the device size is zero, the device is effectively off-line. */
				$mount['available']		= ($mount['mounted'] && $mount['size'] == 0) ? false : $mount['alive'];

				/* Target is set to the mount point when the device is mounted. */
				$mount['target']		= $mount['mounted'] ? $mount['mountpoint'] : "";

				$mount['command']		= get_samba_config($mount['device'],"command");
				$mount['command_bg']	= get_samba_config($mount['device'],"command_bg");
				$mount['enable_script']	= $mount['command'] ? get_samba_config($mount['device'],"enable_script") : "false";
				$mount['encryption']	= is_samba_encrypted($mount['device']);
				$mount['prog_name']		= basename($mount['command'], ".sh");
				$mount['user_command']	= get_samba_config($mount['device'],"user_command");
				$mount['logfile']		= ($mount['prog_name']) ? $paths['device_log'].$mount['prog_name'].".log" : "";
				$mount['running']		= ((is_script_running($mount['command'])) || (is_script_running($mount['user_command'], true)));

				/* Add to return array. */
				$return[]				= $mount;
			}
		}
	} else {
		unassigned_log("Error: unable to get the samba mounts.");
	}

	return $return;
}

/* Mount a remote samba or NFS share. */
function do_mount_samba($info) {
	global $docroot, $paths, $var;

	$rc				= false;

	/* Be sure the server online status is current. */
	$is_alive		= $info['alive'];

	/* If the remote server is not online, run the ping update and see if ping status needs to be refreshed. */
	if (! $is_alive) {
		/* Update the remote server ping status. */
		exec($docroot."/plugins/unassigned.devices/scripts/get_ud_stats ping");

		/* See if the server is online now. */
		$is_alive = is_samba_server_online($info['ip'], $info['protocol']);
	}
	
	if ($is_alive) {
		$dir		= $info['mountpoint'];
		$fs			= $info['fstype'];
		$ro			= $info['read_only'];
		$dev		= $info['mount_dev'];
		if (! $info['mounted']) {
			/* Create the mount point and set permissions. */
			if (! is_dir($dir)) {
				@mkdir($dir, 0777, true);
				@chown($dir, 99);
				@chgrp($dir, 100);
			}

			if ($fs == "nfs") {
				if ($var['shareNFSEnabled'] == "yes") {
					$params	= get_mount_params($fs, $dev, $ro);
					$nfs	= (get_config("Config", "nfs_version") == "4") ? "nfs4" : "nfs";
					$cmd	= "/sbin/mount -t ".escapeshellarg($nfs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount NFS command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
					if ($o) {
						unassigned_log("NFS mount failed: '".$o."'.");
					}
				} else {
					unassigned_log("NFS must be enabled in 'Settings->NFS' to mount NFS remote shares.");
				}
			} else if ($var['shareSMBEnabled'] != "no") {
				/* Create the credentials file. */
				$credentials_file = "{$paths['credentials']}_".basename($dir);
				@file_put_contents("$credentials_file", "username=".($info['user'] ? $info['user'] : 'guest')."\n");
				@file_put_contents("$credentials_file", "password=".decrypt_data($info['pass'])."\n", FILE_APPEND);
				@file_put_contents("$credentials_file", "domain=".$info['domain']."\n", FILE_APPEND);

				/* Are we encrypting this mount? */
				$encrypt	= $info['encryption'] ? ",seal" : "";

				/* If the smb version is not required, just mount the remote share with no version. */
				$smb_version = (get_config("Config", "smb_version") == "yes");
				if (! $smb_version) {
					$ver			= "";
					$extra_params	= $ver.$encrypt;
					$params	= sprintf(get_mount_params($fs, $dir, $ro), $extra_params);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '".$dev."' using SMB default protocol.");
					unassigned_log("Mount SMB command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
				} else {
					$o		= "";
				}

				/* If the remote share didn't mount, try SMB 3.1.1. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					$ver	= ",vers=3.1.1";
					$extra_params	= $ver.$encrypt;
					$params	= sprintf(get_mount_params($fs, $dir, $ro), $extra_params);
					$cmd	= "/sbin/mount -t $fs -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '".$dev."' using SMB 3.1.1 protocol.");
					unassigned_log("Mount SMB command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 3.0. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					/* If the mount failed, try to mount with samba vers=3.0. */
					$ver	= ",vers=3.0";
					$params	= sprintf(get_mount_params($fs, $dir, $ro), $ver);
					$cmd	= "/sbin/mount -t $fs -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '".$dev."' using SMB 3.0 protocol.");
					unassigned_log("Mount SMB command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 2.0. */
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					/* If the mount failed, try to mount with samba vers=2.0. */
					$ver	= ",vers=2.0";
					$params	= sprintf(get_mount_params($fs, $dir, $ro), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '".$dev."' using SMB 2.0 protocol.");
					unassigned_log("Mount SMB command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
				}

				/* If the remote share didn't mount, try SMB 1.0. */
				if ((! is_mounted($dev)) && (strpos($o, "Permission denied") === false) && (strpos($o, "Network is unreachable") === false)) {
					/* If the mount failed, try to mount with samba vers=1.0. */
					$ver	= ",vers=1.0";
					$params	= sprintf(get_mount_params($fs, $dir, $ro), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);

					unassigned_log("Mount SMB share '".$dev."' using SMB 1.0 protocol.");
					unassigned_log("Mount SMB command: ".$cmd);

					/* Mount the remote share. */
					$o		= timed_exec(15, $cmd." 2>&1");
					if ($o) {
						unassigned_log("SMB mount failed: '".$o."'.");
					}
				}
				exec("/bin/shred -u ".escapeshellarg($credentials_file));
				unset($pass);
			} else {
				unassigned_log("SMB must be enabled in 'Settings->SMB' to mount SMB remote shares.");
			}

			/* Did the share successfully mount? */
			if (is_mounted($dev, $dir)) {
				$link = $paths['usb_mountpoint']."/";
				if ((get_config("Config", "symlinks") == "yes" ) && (dirname($dir) == $paths['remote_mountpoint'])) {
					$dir .= "/".
					exec("/bin/ln -s ".escapeshellarg($dir)." ".escapeshellarg($link));
					@chmod($dir, 0777);
					@chown($dir, 99);
					@chgrp($dir, 100);
				}
				unassigned_log("Successfully mounted '".$dev."' on '".$dir."'.");

				$rc = true;
			} else {
				unassigned_log("Remote Share '".$dev."' failed to mount.");

				@rmdir($dir);
			}
		} else {
			unassigned_log("Remote Share '".$dev."' is already mounted.");
		}
	} else {
		unassigned_log("Remote Server '".$info['ip']."' is offline and remote share '".$info['path']."' cannot be mounted."); 
	}

	return $rc;
}

/* Toggle samba auto mount on/off. */
function toggle_samba_automount($source, $status) {
	global $paths, $samba_config;

	/* Verify we have a source. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("smb");

		/* Make file changes. */
		$config_file	= $paths['samba_mount'];

		$samba_config[$source]["automount"] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $samba_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($samba_config[$source]["automount"] == "yes");
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Toggle samba share on/off. */
function toggle_samba_share($source, $status) {
	global $paths, $samba_config;

	/* Verify we have a source. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("smb");

		/* Make file changes. */
		$config_file	= $paths['samba_mount'];

		$samba_config[$source]["smb_share"] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $samba_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($samba_config[$source]["smb_share"] == "yes");
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Toggle hide mount on/off. */
function toggle_samba_disable_mount($source, $status) {
	global $paths, $samba_config;

	/* Verify we have a source. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("smb");

		/* Make file changes. */
		$config_file	= $paths['samba_mount'];

		$samba_config[$source]["disable_mount"] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $samba_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($samba_config[$source]["disable_mount"] == "yes") ? "true" : "false";
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Toggle samba read only on/off. */
function toggle_samba_readonly($source, $status) {
	global $paths, $samba_config;

	/* Verify we have a source. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("smb");

		/* Make file changes. */
		$config_file	= $paths['samba_mount'];

		$samba_config[$source]['read_only'] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $samba_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($samba_config[$source]["read_only"] == "yes");
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Remove the samba remote mount configuration. */
function remove_config_samba($source) {
	global $paths, $samba_config;

	/* Get a lock so file changes can be made. */
	$lock_file		= get_file_lock("smb");

	/* Make file changes. */
	$config_file	= $paths['samba_mount'];

	if ( isset($config[$source]) ) {
		unassigned_log("Removing configuration '".$source."'.");
		if (isset($samba_config[$source]['command'])) {
			$command	= $samba_config[$source]['command'];
			if ( isset($command) && is_file($command) ) {
				@unlink($command);
				unassigned_log("Removing script '".$command."'.");
			}
		}
	}
	unset($samba_config[$source]);

	/* Resave the samba config. */
	save_ini_file($config_file, $samba_config);

	/* Release the file lock. */
	release_file_lock($lock_file);

	return (! isset($samba_config[$source]));
}

#########################################################
############		ISO FILE FUNCTIONS		#############
#########################################################

/* Get the iso file configuration parameter. */
function get_iso_config($source, $variable) {
	global $iso_config;

	return $iso_config[$source][$variable] ?? "";
}

/* Set an iso file configuration parameter. */
function set_iso_config($source, $variable, $value) {
	global $paths, $iso_config;

	/* Verify we have a serial number. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("iso");

		/* Make file changes. */
		$config_file	= $paths['iso_mount'];

		$iso_config[$source][$variable] = $value;
		save_ini_file($config_file, $iso_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= (isset($iso_config[$source][$variable]));
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Is the iso file set to auto mount? */
function is_iso_automount($serial) {
	$auto			= get_iso_config($serial, "automount");
	return ( ($auto) ? ($auto == "yes") : false);
}

/* Get all ISO moints. */
function get_iso_mounts() {
	global $paths, $iso_config;

	/* Create an array of iso file mounts and set paramaters. */
	$return			= [];

	$iso_mounts		= $iso_config;

	/* Sort the iso mounts. */
	ksort($iso_mounts, SORT_NATURAL);

	if (is_array($iso_mounts)) {
		foreach ($iso_mounts as $device => $mount) {
			$mount['device']			= $device;
			if ($mount['device']) {
				$mount['fstype']		= "loop";
				$mount['automount'] 	= is_iso_automount($mount['device']);
				$mount['mountpoint']	= $mount['mountpoint'] ?? "";
				$mount['share']			= $mount['share'] ?? "";
				$mount['file']			= $mount['file'] ?? "";

				if (! $mount['mountpoint']) {
					$mount["mountpoint"] = $paths['usb_mountpoint']."/".$mount['share'];
				}

				/* Remove special characters. */
				$mount_device			= safe_name(basename($mount['device']), true, true);

				/* Check mounting and unmounting status. */
				$mount['mounting']		= MiscUD::get_mounting_status($mount_device);
				$mount['unmounting']	= MiscUD::get_unmounting_status($mount_device);

				/* Is the ios file mounted? */
				$mount['mounted']		= is_mounted("", $mount['mountpoint'], false);

				/* If this is a legacy iso mount indicate that it should be removed. */
				$safe_device			= safe_name($mount['file']);
				$mount['invalid']		= (basename($safe_device) != $device);
	
				/* Target is set to the mount point when the device is mounted. */
				$mount['target']		= $mount['mounted'] ? $mount['mountpoint'] : "";

				$mount['alive']			= is_file($mount['file']);
				$stats					= get_device_stats($mount['mountpoint'], $mount['mounted'], $mount['alive']);
				$mount['size']			= $stats[0]*1024;
				$mount['used']			= $stats[1]*1024;
				$mount['avail']			= $stats[2]*1024;
				$mount['command']		= get_iso_config($mount['device'],"command");
				$mount['command_bg']	= get_iso_config($mount['device'],"command_bg");
				$mount['enable_script']	= $mount['command'] ? get_iso_config($mount['device'],"enable_script") : "false";
				$mount['prog_name']		= basename($mount['command'], ".sh");
				$mount['user_command']	= get_iso_config($mount['device'],"user_command");
				$mount['logfile']		= ($mount['prog_name']) ? $paths['device_log'].$mount['prog_name'].".log" : "";
				$mount['running']		= ((is_script_running($mount['command'])) || (is_script_running($mount['user_command'], true)));

				/* Add to the iso mounts array. */
				$return[] = $mount;
			}

		}
	} else {
		unassigned_log("Error: unable to get the ISO mounts.");
	}

	return $return;
}

/* Mount ISO file. */
function do_mount_iso($info) {
	global $paths;

	$rc = false;
	$file	= $info['file'];
	$dir	= $info['mountpoint'];
	if (is_file($file)) {
		if (! $info['mounted']) {
			@mkdir($dir, 0777, true);

			$cmd = "/sbin/mount -ro loop ".escapeshellarg($file)." ".escapeshellarg($dir);
			unassigned_log("Mount iso cmd: ".$cmd);
			$o = timed_exec(15, $cmd." 2>&1");
			if (is_mounted("", $dir)) {
				unassigned_log("Successfully mounted '".$file."' on '".$dir."'.");

				$rc = true;
			} else {
				@rmdir($dir);
				unassigned_log("Mount of '".$file."' failed: '".$o."'");
			}
		} else {
			unassigned_log("ISO file '".$file."' is already mounted.");
		}
	} else {
		unassigned_log("Error: ISO file '".$file."' is missing and cannot be mounted.");
	}

	return $rc;
}

/* Toggle iso file automount on/off. */
function toggle_iso_automount($source, $status) {
	global $paths, $iso_config;

	/* Verify we have a serial number. */
	if ($source) {
		/* Get a lock so file changes can be made. */
		$lock_file		= get_file_lock("iso");

		/* Make file changes. */
		$config_file	= $paths['iso_mount'];

		$iso_config[$source]["automount"] = ($status == "true") ? "yes" : "no";
		save_ini_file($config_file, $iso_config);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= ($iso_config[$source]["automount"] == "yes");
	} else {
		$rc	= false;
	}

	return $rc;
}

/* Remove ISO configuration. */
function remove_config_iso($source) {
	global $paths, $iso_config;

	/* Get a lock so file changes can be made. */
	$lock_file		= get_file_lock("iso");

	/* Make file changes. */
	$config_file	= $paths['iso_mount'];

	if ( isset($iso_config[$source]) ) {
		unassigned_log("Removing ISO configuration '".$source."'.");
		if (isset($iso_config[$source]['command'])) {
			$command	= $iso_config[$source]['command'];
			if ( isset($command) && is_file($command) ) {
				@unlink($command);
				unassigned_log("Removing script '".$command."'.");
			}
		}

		unset($iso_config[$source]);

		/* Save new iso config. */
		save_ini_file($config_file, $iso_config);

		$rc	= (! isset($iso_config[$source]));
	} else {
		$rc	= false;
	}

	if (! $rc) {
		unassigned_log("Error: Could not remove ISO configuration '".$source."'.");
	}

	/* Release the file lock. */
	release_file_lock($lock_file);

	return $rc;
}


#########################################################
############		DISK FUNCTIONS			#############
#########################################################

/* Get an array of all unassigned disks. */
function get_unassigned_disks() {
	global $unraid_disks;

	/* Initialize arrays. */
	$ud_disks = [];
	$devicePaths = [];

	/* Get all devices by id and eliminate any duplicates. */
	foreach (array_unique(listFile("/dev/disk/by-id")) as $physicalDevice) {
		$realPath = realpath($physicalDevice);

		/* Only /dev/sd*, /dev/hd*, and /dev/nvme* devices. */
		if (array_reduce(["/dev/sd", "/dev/hd", "/dev/nvme"], function ($carry, $substring) use ($realPath) {
			return $carry || strpos($realPath, $substring) !== false;
		}, false)) {
			$devicePaths[$realPath] = $physicalDevice;
		}
	}

	ksort($devicePaths, SORT_NATURAL);

	/* Create the array of unassigned devices. */
	foreach ($devicePaths as $path => $device) {
		/* Check if $device is not empty and doesn't contain "part" in its name. */
		if ($device && (preg_match("#^(.(?!part))*$#", $device))) {

			/* Check if $path is not in $unraid_disks */
			if (! in_array($path, $unraid_disks, true)) {

				/* Check if $path is not in the 'device' field of any array in $ud_disks. */
				if (! in_array($path, array_map(function ($ar) {
						return $ar['device'];
					}, $ud_disks), true)) {

					/* Get partitions matching the pattern "$device.*-part\d+". */
					$partitions = array_values(preg_grep("|$device.*-part\d+|", $devicePaths));

					/* Sort partitions using natural order. */
					natsort($partitions);

					/* Add entry to $ud_disks. */
					$ud_disks[$device] = array("device" => $path, "partitions" => $partitions);
				}
			}
		}
	}

	return $ud_disks;
}

/* Get all the disk information for each disk device. */
function get_all_disks_info() {

	/* Get all the unassigned disks. */
	$ud_disks = get_unassigned_disks();

	/* If there are unassigned disks, get all disk information from udev. */
	if (is_array($ud_disks)) {
		/* Read the disk sizes into an array so we only call lsblk once. */
		$lsblkOutput	= timed_exec(0.5, "/bin/lsblk -b -n -o NAME,SIZE,TYPE | /bin/awk '$3 == \"disk\" {print $1 \",\" $2}' 2</dev/null");

		/* Explode the output into an array based on newline character. */
		$lines = explode("\n", trim($lsblkOutput));

		/* Initialize an empty array to store the results. */
		$disk_sizes = [];

		/* Iterate through each line and split by comma to create key-value pairs. */
		foreach ($lines as $line) {
			$parts = explode(",", $line);
			if (count($parts) == 2) {
				$disk_sizes[$parts[0]] = $parts[1];
			}
		}

		/* Go through each disk and get the information for each. */
		foreach ($ud_disks as $key => $disk) {
			/* Add as a UD device. */
			$dev			= basename(realpath($key));

			/* Set the disk size from the lsblk array. */
			$disk['size']	= intval($disk_sizes[$dev]);

			/* Get all the disk partitions. */
			$disk			= array_merge($disk, get_disk_info($key));
			$disk['zvol']	= [];

			/* Get all the partitions and additional settings. */
			if (! empty($disk['partitions'])) {
				foreach ($disk['partitions'] as $k => $p) {
					if (! empty($p)) {
						$disk['partitions'][$k]	= get_partition_info($p);

						/* If this is a zfs disk, see if there are any zfs volumes. */
						if ($disk['partitions'][$k]['fstype'] == "zfs") {
							/* Get any zfs volumes. */
							$disk['zvol']		= array_merge($disk['zvol'], get_zvol_info($disk['partitions'][$k]));
						}
					}
				}
			}

			/* Remove the original UD entry and add the new UD reference. */
			unset($ud_disks[$key]);
			$disk['path'] = $key;

			/* Set the ud_dev to the current value in the devs.ini file. */
			$disk['ud_dev']		= get_disk_dev($disk['device']);

			/* Use the devX designation or the sdX if there is no devX designation for disk device sorting. */
			$unassigned_dev			= $disk['unassigned_dev'] ?: ($disk['ud_dev'] ?: basename($disk['device']));

			/* If there is already a devX that is the same, use the disk device sdX designation. */
			if ((is_dev_device($unassigned_dev)) && (array_key_exists($unassigned_dev, $ud_disks))) {
				/* Get the sdX device designation. */
				$unassigned_dev		= basename($disk['device']);
			}

			/* See if any partitions have a file system. */
			$disk['file_system']	= in_array(true, array_map(function($partition) {
				return !empty($partition['fstype']);
			}, $disk['partitions']), true);

			/* Any partitions mounted? */
			$disk['mounted']		= array_reduce($disk['partitions'], function ($carry, $partition) {
				return $carry || $partition['mounted'];
			}, false);

			/* Was the disk unmounted properly? */
			$disk['not_unmounted']		= array_reduce($disk['partitions'], function ($carry, $partition) {
				return $carry || $partition['not_unmounted'];
			}, false);

			/* Mark all partitions as mounting if the disk is mounting. */
			if ($disk['mounting'] === true) {
				/* Loop through partitions and set 'mounting' to true. */
				foreach ($disk['partitions'] as &$partition) {
					$partition['mounting'] = true;
				}

				/* Check if 'mounting' is true in the zvol and set for zvols. */
				if (isset($disk['zvol'])) {
					foreach ($disk['zvol'] as &$zvol) {
						$zvol['mounting'] = true;
					}
				}
			} else {
				/* Get the mounting states of disks and all partitions. */
				$disk['mounting']		= (
					in_array(true, array_map(function($partition) {
						return $partition['mounting'];
					}, $disk['partitions']), true) ||

					in_array(true, array_map(function($zvol) {
						return $zvol['mounting'];
					}, $disk['zvol']), true)
				);
			}

			/* Mark any partitions as unmounting if the disk is unmounting. */
			if ($disk['unmounting'] === true) {
				/* Loop through partitions and set 'unmounting' to true. */
				foreach ($disk['partitions'] as &$partition) {
					$partition['unmounting'] = true;
				}

				/* Check if 'unmounting' is true in the zvol and set for zvols. */
				if (isset($disk['zvol'])) {
					foreach ($disk['zvol'] as &$zvol) {
						$zvol['unmounting'] = true;
					}
				}
			} else {
				/* Get the unmounting states of disks and all partitions. */
				$disk['unmounting'] 	= (
					in_array(true, array_map(function($partition) {
						return $partition['unmounting'];
					}, $disk['partitions']), true) ||

					in_array(true, array_map(function($zvol) {
						return $zvol['unmounting'];
					}, $disk['zvol']), true)
				);
			}

			/* Is a partition script running? */
			$disk['running']		= array_reduce($disk['partitions'], function ($carry, $partition) {
				return $carry || $partition['running'];
			}, false);

			/* Device is disabled unless a partition is found with a valid file system. */
			$disk['disable']		= (! $disk['file_system']);

			/* Is this a pool disk? */
			$disk['pool_disk']		= array_reduce($disk['partitions'], function ($carry, $partition) {
				return $carry || $partition['pool'];
			}, false);

			/* Check that udev matches the file system on the disk. */
			$disk['not_udev']		= array_reduce($disk['partitions'], function ($carry, $partition) {
				return $carry || $partition['not_udev'];
			}, false);

			/* Add this device as a UD device. */
			$ud_disks[$unassigned_dev] = $disk;
		}
	} else {
		/* There was a problem getting the unassigned disks array. */
		unassigned_log("Error: unable to get unassigned disks.");
		$ud_disks = [];
	}

	/* Sort the unassigned devoces in natural order. */
	ksort($ud_disks, SORT_NATURAL);

	return $ud_disks;
}

/* Get the udev disk information. */
function get_udev_info($dev, $udev = null) {
	global $paths;

	$rc		= [];

	/* Make file changes. */
	$state	= is_file($paths['state']) ? @parse_ini_file($paths['state'], true, INI_SCANNER_RAW) : [];

	/* Be sure the device name has safe characters. */
	$device	= safe_name($dev);

	/* If the udev is not null, save it to the unassigned.devices.ini file. */
	if (isset($udev)) {
		unassigned_log("Udev: Update udev info for ".$dev.".", $GLOBALS['UDEV_DEBUG']);

 		/* Save this entry unless the ACTION is remove. */
 		if ($udev['ACTION'] != "remove") {
			/* Remove proxy environment variables that are added to php environment variables. */
			if (isset($udev['http_proxy'])) {
				unset($udev['http_proxy']);
				unset($udev['https_proxy']);
				unset($udev['no_proxy']);
			}

			$state[$device] = $udev;
		} else {
			/* Remove all entries for this serial number. */
			$serial	= $udev['ID_SERIAL'] ?? "";
			if ($serial) {
				foreach ($state as $key => $val) {
					if ($val['ID_SERIAL'] == $serial) {
						unset($state[$key]);
					}
				}
			}
		}
		/* Get a lock so file changes can be made. */
		$lock_file	= get_file_lock("udev");

		save_ini_file($paths['state'], $state);

		/* Write to temp file and then move to destination file for diagnostics. */
		$tmp_file	= $paths['tmp_file'];
		@copy($paths['state'], $tmp_file);
		@rename($tmp_file, $paths['diag_state']);

		/* Release the file lock. */
		release_file_lock($lock_file);

		$rc	= $udev;
	} else if (array_key_exists($device, $state)) {
		$rc	= $state[$device];
	} else {
		$dev_state = @parse_ini_string(timed_exec(5, "/sbin/udevadm info --query=property --path $(/sbin/udevadm info -q path -n ".escapeshellarg($device)." 2>/dev/null) 2>/dev/null"), INI_SCANNER_RAW);
		if (is_array($dev_state)) {
			unassigned_log("Udev: Refresh udev info for ".$dev.".", $GLOBALS['UDEV_DEBUG']);

			$state[$device] = $dev_state;

			/* Get a lock so file changes can be made. */
			$lock_file	= get_file_lock("udev");

			/* Write the file to the ram file system. */
			save_ini_file($paths['state'], $state, false);

			/* Write to temp file and then move to diagnostics destination file. */
			$tmp_file	= $paths['tmp_file'];
			@copy($paths['state'], $tmp_file);
			@rename($tmp_file, $paths['diag_state']);

			/* Release the file lock. */
			release_file_lock($lock_file);

			$rc	= $state[$device];
		}
	}

	return $rc;
}

/* Get information on specific disk device. */
function get_disk_info($dev) {
	global $unraid_disks, $Preclear;

	/* Get all the disk information for this disk device. */
	$disk						= [];
	$attrs						= (isset($_ENV['DEVTYPE'])) ? get_udev_info($dev, $_ENV) : get_udev_info($dev, null);
	$disk['serial_short']		= $attrs['ID_SCSI_SERIAL'] ?? ($attrs['ID_SERIAL_SHORT'] ?? "");
	$disk['device']				= realpath($dev);
	$disk['serial']				= get_disk_id($disk['device'], trim($attrs['ID_SERIAL']));
	$disk['id_bus']				= $attrs['ID_BUS'] ?? "";
	$disk['fstype']				= $attrs['ID_FS_TYPE'] ?? "";
	$disk['ud_dev']				= get_disk_dev($disk['device']);
	$disk['ud_device']			= is_dev_device($disk['ud_dev']);
	$disk['unassigned_dev']		= get_config($disk['serial'], "unassigned_dev");
	$disk['ud_unassigned_dev']	= is_dev_device($disk['unassigned_dev']);
	$disk['ssd']				= is_disk_ssd($disk['device']);
	$rw							= get_disk_reads_writes($disk['ud_dev'], $disk['device']);
	$disk['reads']				= $rw[0];
	$disk['writes']				= $rw[1];
	$disk['read_rate']			= $rw[2];
	$disk['write_rate']			= $rw[3];
	$disk['spinning']			= is_disk_spinning($disk['ud_dev']);
	$usb						= (isset($attrs['ID_BUS']) && ($attrs['ID_BUS'] == "usb"));
	$disk['automount']			= is_automount($disk['serial'], $usb);
	$disk['disable_mount']		= is_disable_mount($disk['serial']);
	$disk['temperature']		= get_temp($disk['ud_dev'], $disk['device'], $disk['spinning']);
	$disk['command']			= get_config($disk['serial'], "command.1");
	$disk['command_bg']			= get_config($disk['serial'], "command_bg.1");
	$disk['enable_script']		= $disk['command'] ? get_config($disk['serial'], "enable_script.1") : "false";
	$disk['user_command']		= get_config($disk['serial'], "user_command.1");
	$disk['show_partitions']	= (get_config($disk['serial'], "show_partitions") == "no") ? false : true;
	$disk['array_disk']			= in_array($disk['device'], $unraid_disks);
	$disk['pass_through']		= is_pass_through($disk['serial']);
	$disk['formatting']			= MiscUD::get_formatting_status(basename($disk['device']));
	$disk['mounting']			= MiscUD::get_mounting_status(basename($disk['device']));
	$disk['unmounting']			= MiscUD::get_unmounting_status(basename($disk['device']));

	/* Are there any preclearing operations going on? */
	if ((! $disk['fstype']) && ($Preclear)) {
		$disk['preclearing']	= (($Preclear->isRunning(basename($disk['device']))) || (shell_exec("/usr/bin/ps -ef | /bin/grep 'preclear' | /bin/grep " . escapeshellarg($disk['device']) . " | /bin/grep -v 'grep'") != ""));
	} else {
		$disk['preclearing']	= false;
	}

	/* Get the hostX from the DEVPATH so we can re-attach a disk. */
	MiscUD::save_device_host($disk['serial'], $attrs['DEVPATH']);

	return $disk;
}

/* Get partition information. */
function get_partition_info($dev) {
	global $paths;

	$partition	= [];
	$attrs	= (isset($_ENV['DEVTYPE'])) ? get_udev_info($dev, $_ENV) : get_udev_info($dev, null);
	if ($attrs['DEVTYPE'] == "partition") {
		$partition['serial_short']		= $attrs['ID_SCSI_SERIAL'] ?? ($attrs['ID_SERIAL_SHORT'] ?? "");
		$partition['device']			= realpath($dev);
		$partition['serial']			= isset($attrs['ID_SERIAL']) ? get_disk_id($partition['device'], trim($attrs['ID_SERIAL'])) : "";
		$partition['uuid']				= $attrs['ID_FS_UUID'] ?? "";

		/* Get partition number */
		preg_match_all("#(.*?)(\d+$)#", $partition['device'], $matches);
		$partition['part']				= $matches[2][0] ?? "";
		$partition['disk']				= (isset($matches[1][0])) ? MiscUD::base_device($matches[1][0]) : "";

		/* Get the physical disk label or generate one based on the vendor id and model or serial number. */
		if (isset($attrs['ID_FS_LABEL'])){
			$partition['label']			= safe_name($attrs['ID_FS_LABEL']);
			$partition['disk_label']	= $partition['label'];
		} else {
			if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
				$partition['label']		= sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
			} else {
				$partition['label']		= safe_name($attrs['ID_SERIAL_SHORT']);
			}
			$all_disks					= array_unique(array_map(function($ar){return realpath($ar);}, listFile("/dev/disk/by-id")));
			$partition['label']			= (isset($partition['label']) && (isset($matches[1][0])) && (count(preg_grep("%".$matches[1][0]."%i", $all_disks)) > 2)) ? $partition['label']."-part".$matches[2][0] : $partition['label'];
			$partition['disk_label']	= "";
		}

		/* Any partition with an 'UNRAID' label is an array disk. */
		$partition['array_disk']		= ($partition['label'] == "UNRAID");

		/* Get the file system type. */
		$partition['fstype']			= safe_name($attrs['ID_FS_TYPE'] ?? "");
		$partition['fstype']			= ($partition['fstype'] == "zfs_member") ? "zfs" : $partition['fstype'];

		/* Get the mount point from the configuration and if not set create a default mount point. */
		$partition['mountpoint']		= get_config($partition['serial'], "mountpoint.{$partition['part']}");
		if (! $partition['mountpoint']) { 
			$partition['mountpoint']	= preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mountpoint'], $partition['label']));
		}

		/* crypto_LUKS file system. */
		/* The device is /dev/mapper/... for all luks devices. */
		if ($partition['fstype'] == "crypto_LUKS") {
			$partition['luks']			= $partition['device'];
			$partition['device']		= "/dev/mapper/".safe_name(basename($partition['mountpoint']));
			$dev						= $partition['luks'];
		} else {
			$partition['luks']			= "";
			$dev						= $partition['device'];
		}

		/* This is the file system reported by lsblk. */
		$partition['file_system']		= ($partition['fstype']) ? part_fs_type($partition['device']) : "";

		/* Check for udev and lsblk file system type matching. If not then udev is not reporting the correct file system. */
		$partition['not_udev']			= ($partition['fstype'] != "crypto_LUKS") ? ($partition['fstype'] != $partition['file_system']) : false;

		/* Get the partition mounting, unmounting, and formatting status. */
		$partition['mounting']			= MiscUD::get_mounting_status(basename($dev));
		$partition['unmounting']		= MiscUD::get_unmounting_status(basename($dev));

		/* Set up all disk parameters and status. */
		$partition['pass_through']		= is_pass_through($partition['serial']);

		/* Is the disk mount point mounted? */
		/* If the partition doesn't have a file system, it can't possibly be mounted by UD. */
		$partition['mounted']			= ((! $partition['pass_through']) && ($partition['fstype'])) ? is_mounted("", $partition['mountpoint'], false) : false;

		/* is the partition mounted read only. */
		$partition['part_read_only']	= ($partition['mounted']) ? is_mounted_read_only($partition['mountpoint']) : false;

		/* Is this a btrfs pooled disk. */
		if ($partition['mounted'] && $partition['fstype'] == "btrfs") {
			/* Get the members of a pool if this is a pooled disk. */
			$pool_devs					= MiscUD::get_pool_devices($partition['mountpoint']);

			/* First pooled device is the primary member. */
			unset($pool_devs[0]);

			/* This is a secondary pooled member if not the primary member. */
			$partition['pool']			= in_array($partition['device'], $pool_devs);
		} else {
			$partition['pool']			= false;
		}

		/* See if this is a zfs file system. */
		$zfs							= ($partition['file_system'] == "zfs");

		/* Get the pool name for a zfs device whether or not it is mounted. */
		$partition['pool_name']			= (($partition['mounted']) && ($zfs)) ? MiscUD::zfs_pool_name("", $partition['mountpoint']) : "";

		/* If the disk mount point is mounted, we need to verify it is also mounted by device. */
		/* If it is not, then the disk was probably removed before being properly unmounted. */
		/* Or the user has several disks with the same mount point. */
		/* If the disk is part of a btrfs pool, ignore the not unmounted check. */
		if (($partition['mounted']) && (! $partition['pool'])) {
			/* Not unmounted is a check that the disk is mounted by mount point but not by device. */
			/* The idea is to catch the situation where a disk is removed before being unmounted. */
			if ($zfs) {
				$partition['not_unmounted']	= $partition['pool_name'] ? (! is_mounted($partition['pool_name'], "", false)) : false;
			} else {
				$partition['not_unmounted']	= (! is_mounted($partition['device'], "", false));
			}
		} else {
			$partition['not_unmounted']		= false;
		}

		/* Is the disable mount button switch on? */
		$partition['disable_mount']	= is_disable_mount($partition['serial']);

		/* Target is set to the mount point when the device is mounted. */
		$partition['target']		= $partition['mounted'] ? $partition['mountpoint'] : "";

		$stats						= get_device_stats($partition['mountpoint'], $partition['mounted']);
		$partition['size']			= $stats[0]*1024;
		$partition['used']			= $stats[1]*1024;
		$partition['avail']			= $stats[2]*1024;
		$partition['owner']			= (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
		$partition['read_only']		= is_read_only($partition['serial']);
		$usb						= (isset($attrs['ID_BUS']) && ($attrs['ID_BUS'] == "usb"));
		$partition['shared']		= config_shared($partition['serial'], $partition['part'], $usb);
		$partition['command']		= get_config($partition['serial'], "command.{$partition['part']}");
		$partition['user_command']	= get_config($partition['serial'], "user_command.{$partition['part']}");
		$partition['command_bg']	= get_config($partition['serial'], "command_bg.{$partition['part']}");
		$partition['enable_script']	= $partition['command'] ? get_config($partition['serial'], "enable_script.{$partition['part']}") : "false";
		$partition['prog_name']		= basename($partition['command'], ".sh");
		$partition['logfile']		= ($partition['prog_name']) ? $paths['device_log'].$partition['prog_name'].".log" : "";
		$partition['running']		= ((is_script_running($partition['command'])) || (is_script_running($partition['user_command'], true)));

		/* Values not needed but must exist for make_mount_button() on the partition. */
		$partition['ud_device']		= true;
		$partition['formatting']	= false;
		$partition['preclearing']	= false;
	}

	return $partition;
}

/* Get zfs volume info if the partition has any zvols. */
function get_zvol_info($disk) {

	/* Get any zfs volumes. */
	$zvol		= [];
	if ((get_config("Config", "zvols") == "yes") && ($disk['fstype'] == "zfs") && ($disk['mounted'])) {
		$serial		= $disk['serial'];
		$zpool_name	= $disk['pool_name'];

		foreach (glob("/dev/zvol/".$zpool_name."/*") as $n => $q) {
			$vol							= basename($q);
			$volume							= $zpool_name.".".basename($q);
			$zvol[$vol]['pool_name']		= $zpool_name;
			$zvol[$vol]['volume']			= $volume;
			$zvol[$vol]['device']			= realpath($q);
			$zvol[$vol]['active']			= (strpos($q, "-part") !== false) ? true : empty(glob($q."-part*"));
			$zvol[$vol]['mountpoint']		= $disk['mountpoint'].".".basename($q);
			$zvol[$vol]['mounted']			= is_mounted("", $zvol[$vol]['mountpoint'], false);
			$zvol[$vol]['mounting']			= MiscUD::get_mounting_status(basename($zvol[$vol]['device']));
			$zvol[$vol]['unmounting']		= MiscUD::get_unmounting_status(basename($zvol[$vol]['device']));
			$zvol[$vol]['fstype']			= "zvol";
			$zvol[$vol]['file_system']		= part_fs_type($zvol[$vol]['device']);
			$zvol[$vol]['file_system']		= ($zvol[$vol]['file_system']) ?: zvol_fs_type($zvol[$vol]['device']);
			$zvol[$vol]['zfs_read_only']	= is_mounted_read_only($zvol[$vol]['mountpoint']);
			$stats							= get_device_stats($zvol[$vol]['mountpoint'], $zvol[$vol]['mounted'], $zvol[$vol]['active']);
			$zvol[$vol]['size']				= $stats[0]*1024;
			$zvol[$vol]['used']				= $stats[1]*1024;
			$zvol[$vol]['avail']			= $stats[2]*1024;
			$zvol[$vol]['read_only']		= is_read_only($serial, true, $vol);
			$zvol[$vol]['target']			= $zvol[$vol]['mounted'] ? $zvol[$vol]['mountpoint'] : "";
			$zvol[$vol]['pass_through']		= is_pass_through($serial, $vol);
			$zvol[$vol]['disable_mount']	= is_disable_mount($serial, $vol);

			/* Values not needed but must exist for make_mount_button() for this partition. */
			$zvol[$vol]['array_disk']		= false;
			$zvol[$vol]['not_unmounted']	= false;
			$zvol[$vol]['not_udev']			= false;
			$zvol[$vol]['ud_device']		= true;
			$zvol[$vol]['running']			= false;
			$zvol[$vol]['command']			= "";
			$zvol[$vol]['user_command']		= "";
		}
	}

	return $zvol;
}


/* Get the check file system command based on disk file system. */
function get_fsck_commands($fs, $dev, $type = "ro") {
	switch ($fs) {
		case 'vfat':
			$cmd = array('ro'=>'/sbin/fsck.vfat -n %s', 'rw'=>'/sbin/fsck -a %s');
			break;

		case 'ntfs':
			$cmd = array('ro'=>'/bin/ntfsfix -n %s', 'rw'=>'/bin/ntfsfix -b -d %s');
			break;

		case 'hfsplus';
			$cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s', 'rw'=>'/usr/sbin/fsck.hfsplus -y %s');
			break;

		case 'xfs':
			$cmd = array('ro'=>'/sbin/xfs_repair -n %s', 'rw'=>'/sbin/xfs_repair -e %s', 'log'=>'/sbin/xfs_repair -e -L %s');
			break;

		case 'zfs':
			$cmd = array('ro'=>'/usr/sbin/zpool scrub -w %s');
			break;

		case 'exfat':
			$cmd = array('ro'=>'/usr/sbin/fsck.exfat -n %s', 'rw'=>'/usr/sbin/fsck.exfat %s');
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
	$smb_file		= $paths['shares_state'];
	$smb_config		= @parse_ini_file($smb_file, true);

	/* Get all share names from the state file. */
	$smb_shares		= array_keys($smb_config);
	$smb_shares		= array_flip($smb_shares);
	$smb_shares		= array_change_key_case($smb_shares, CASE_UPPER);
	$smb_shares		= array_flip($smb_shares);

	/* Parse the disks state file. */
	$disks_file 	= $paths['disks_state'];
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
		if (! in_array($name, $disk_names, true)) {
			$disk_names[] = strtoupper($name);
		}
	}

	/* Start with an empty array of ud_shares. */
	$ud_shares		= [];

	/* Get an array of all ud shares. */
	$share_names	= MiscUD::get_json($paths['share_names']);
	foreach ($share_names as $device => $name) {
		$devs		= explode(",", $device);
		$keep		= true;
		foreach ($devs as $check) {
			if ($check == $dev) {
				$keep	= false;
				break;
			}
		}

		/* If this device is not self, then keep it. */
		if ($keep) {
			$ud_shares[] = strtoupper($name);
		}
	}

	/* Merge samba shares, reserved names, and ud shares. */
	$shares = array_merge($smb_shares, $ud_shares, $disk_names);

	/* See if the share name is already being used. */
	if (is_array($shares) && in_array(strtoupper($mountpoint), $shares, true)) {
		unassigned_log("Error: Device '".$dev."' mount point '".$mountpoint."' - name is reserved, used in the array or a pool, or by an unassigned device.");
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
			$old_mountpoint	= basename(get_config($serial, "mountpoint.{$partition}"));
			$mountpoint		= $paths['usb_mountpoint']."/".$mountpoint;
			set_config($serial, "mountpoint.{$partition}", $mountpoint);
			$mountpoint		= safe_name(basename($mountpoint));
			switch ($fstype) {
				case 'xfs';
					timed_exec(20, "/usr/sbin/xfs_admin -L ".escapeshellarg($mountpoint)." ".escapeshellarg($dev)." 2>/dev/null");
					break;

				case 'btrfs';
					timed_exec(20, "/sbin/btrfs filesystem label ".escapeshellarg($dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					break;

				case 'zfs';
					/* Change the pool name. */
					$pool_name	= MiscUD::zfs_pool_name($dev);
					shell_exec("/usr/sbin/zpool export ".escapeshellarg($pool_name));
					sleep(1);

					shell_exec("/usr/sbin/zpool import -N ".escapeshellarg($pool_name)." ".escapeshellarg($mountpoint));
					sleep(1);

					shell_exec("/usr/sbin/zpool export ".escapeshellarg($mountpoint));
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
					$mapper	= safe_name(basename($mountpoint));
					$cmd	= "luksOpen ".escapeshellarg($dev)." ".escapeshellarg($mapper);
					$pass	= decrypt_data(get_config($serial, "pass"));
					if (! $pass) {
						$o		= shell_exec("/usr/local/sbin/emcmd cmdCryptsetup=".escapeshellarg($cmd)." 2>&1");

						/* Check for the mapper file existing. If it's not there, unraid did not open the luks disk. */
						if (! file_exists("/dev/mapper/".$mapper)) {
							$o	= "Error: Passphrase or Key File not found.";
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
						switch (part_fs_type("/dev/mapper/".$mapper)) {
							case "btrfs":
								/* btrfs label change. */
								timed_exec(20, "/sbin/btrfs filesystem label ".escapeshellarg($mapper)." ".escapeshellarg($mountpoint)." 2>/dev/null");
								break;

							case "xfs":
								/* xfs label change. */
								timed_exec(20, "/usr/sbin/xfs_admin -L ".escapeshellarg($mountpoint)." ".escapeshellarg($mapper)." 2>/dev/null");
								break;

							case "zfs":
								/* Change the pool name. */
								$pool_name	= MiscUD::zfs_pool_name($dev);
								shell_exec("/usr/sbin/zpool export ".escapeshellarg($pool_name));
								sleep(1);

								shell_exec("/usr/sbin/zpool import -N ".escapeshellarg($pool_name)." ".escapeshellarg($mountpoint));
								sleep(1);

								shell_exec("/usr/sbin/cryptsetup luksUUID --uuid=".escapeshellarg($mountpoint)." ".$mapper);
								sleep(1);

								shell_exec("/usr/sbin/zpool export ".escapeshellarg($mountpoint));
								break;

							default:
								unassigned_log("Warning: Cannot change the disk label on device '".basename($dev)."'.");
								break;
						}
					}

					exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper)." 2>/dev/null");
					break;

				default;
					unassigned_log("Warning: Cannot change the disk label on device '".basename($dev)."'.");
				break;
			}
		}

		/* Let things settle. */
		sleep(1);
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
	global $docroot, $plugin, $paths, $var;

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
		timed_exec(20, escapeshellcmd($docroot."/plugins/".$plugin."/scripts/luks_uuid.sh ".escapeshellarg($device)));
		$mapper	= basename($luks)."_UUID";
		$cmd	= "luksOpen ".escapeshellarg($luks)." ".escapeshellarg($mapper);
		$pass	= decrypt_data(get_config($serial, "pass"));
		if (! $pass) {
			$o		= shell_exec("/usr/local/sbin/emcmd cmdCryptsetup=".escapeshellarg($cmd)." 2>&1");

			/* Check for the mapper file existing. If it's not there, unraid did not open the luks disk. */
			if (! file_exists("/dev/mapper/".$mapper)) {
				$o	= "Error: Passphrase or Key File not found.";
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
			unassigned_log("luksOpen error: ".$o);
		} else {
			/* Get the crypto luks file system. */
			$mapper_dev = "/dev/mapper/".$mapper;
			switch (part_fs_type($mapper_dev)) {
				case "xfs":
					/* Change the xfs UUID. */
					$rc = timed_exec(10, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($mapper_dev));
					break;

				case "btrfs":
					$rc = timed_exec(10, "/sbin/btrfstune -uf ".escapeshellarg($mapper_dev));
					break;

				default:
					$rc = "Cannot change UUID.";
					break;
			}

			/* Close the luks device. */
			exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper)." 2>/dev/null");
		}
	} else if ($fs_type == "xfs") {
		/* Change the xfs UUID. */
		$rc		= timed_exec(60, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($device));
	} else if ($fs_type == "btrfs") {
		/* Change the btrfs UUID. */
		$rc		= timed_exec(60, "/sbin/btrfstune -uf ".escapeshellarg($device));
	}

	/* Show the result of the UUID change operation. */
	if ($rc) {
		unassigned_log("Changed partition UUID on '".$device."' with result: ".$rc);
	}
}

/* Check to see if a pool has already been upgraded. */
function is_upgraded_ZFS_pool($pool_name) {

	/* See if the pool is aready upgraded. */
	$upgrade	= trim(shell_exec("/usr/sbin/zpool status ".escapeshellarg($pool_name)." | /usr/bin/grep 'Enable all features using.'") ?? "");

	return ($upgrade ? false : true);
}

/* Upgrade the zfs disk. */
function upgrade_ZFS_pool($pool_name) {

	if (! is_upgraded_ZFS_pool($pool_name)) {
		/* Upgrade the zfs pool. */
		shell_exec("/usr/sbin/zpool upgrade ".escapeshellarg($pool_name));

		unassigned_log("ZFS pool '".$pool_name."' has been upgraded");
	} else {
		unassigned_log("ZFS pool '".$pool_name."' has already been upgraded");
	}
}
?>
