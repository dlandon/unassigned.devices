<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2021, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
/* $VERBOSE=TRUE; */

$paths = [	"smb_extra"			=> "/tmp/{$plugin}/smb-settings.conf",
			"smb_usb_shares"	=> "/etc/samba/unassigned-shares",
			"usb_mountpoint"	=> "/mnt/disks",
			"remote_mountpoint"	=> "/mnt/remotes",
			"device_log"		=> "/tmp/{$plugin}/",
			"config_file"		=> "/tmp/{$plugin}/config/{$plugin}.cfg",
			"state"				=> "/var/state/{$plugin}/{$plugin}.ini",
			"mounted"			=> "/var/state/{$plugin}/{$plugin}.json",
			"hdd_temp"			=> "/var/state/{$plugin}/hdd_temp.json",
			"run_status"		=> "/var/state/{$plugin}/run_status.json",
			"ping_status"		=> "/var/state/{$plugin}/ping_status.json",
			"df_status"			=> "/var/state/{$plugin}/df_status.json",
			"hotplug_status"	=> "/var/state/{$plugin}/hotplug_status.json",
			"diskio"			=> "/var/state/{$plugin}/diskio.json",
			"dev_state"			=> "/usr/local/emhttp/state/devs.ini",
			"samba_mount"		=> "/tmp/{$plugin}/config/samba_mount.cfg",
			"iso_mount"			=> "/tmp/{$plugin}/config/iso_mount.cfg",
			"unmounting"		=> "/var/state/{$plugin}/unmounting_%s.state",
			"mounting"			=> "/var/state/{$plugin}/mounting_%s.state",
			"formatting"		=> "/var/state/{$plugin}/formatting_%s.state",
			"scripts"			=> "/tmp/{$plugin}/scripts/",
			"credentials"		=> "/tmp/{$plugin}/credentials",
			"authentication"	=> "/tmp/{$plugin}/authentication",
			"luks_pass"			=> "/tmp/{$plugin}/luks_pass",
			"script_run"		=> "/tmp/{$plugin}/script_run"
		];

$docroot	= $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$users		= @parse_ini_file("$docroot/state/users.ini", true);
$disks		= @parse_ini_file("$docroot/state/disks.ini", true);

/* Read Unraid variables file. Used to determine disks not assigned to the array and other array parameters. */
if (! isset($var)){
	if (! is_file("$docroot/state/var.ini")) shell_exec("/usr/bin/wget -qO /dev/null localhost:$(ss -napt | /bin/grep emhttp | /bin/grep -Po ':\K\d+') >/dev/null");
	$var = @parse_ini_file("$docroot/state/var.ini");
}

/* See if NETBIOS is enabled on Unraid. */
if ((! isset($var['USE_NETBIOS']) || ((isset($var['USE_NETBIOS'])) && ($var['USE_NETBIOS'] == "yes")))) {
	$use_netbios = "yes";
} else {
	$use_netbios = "no";
}

/* See if the preclear plugin is installed. */
if ( is_file( "plugins/preclear.disk/assets/lib.php" ) )
{
	require_once( "plugins/preclear.disk/assets/lib.php" );
	$Preclear = new Preclear;
}
else
{
	$Preclear = null;
}

/* Get the current diskio setting. */
$tc		= $paths['diskio'];
$diskio = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();

########################################################
#############		MISC FUNCTIONS        ##############
########################################################

class MiscUD
{

	public function save_json($file, $content)
	{
		file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT ));
	}

	public function get_json($file)
	{
		return file_exists($file) ? @json_decode(file_get_contents($file), true) : [];
	}

	public function disk_device($disk)
	{
		return (file_exists($disk)) ? $disk : "/dev/{$disk}";
	}

	public function disk_name($disk)
	{
		return (file_exists($disk)) ? basename($disk) : $disk;
	}

	public function array_first_element($arr)
	{
		return (is_array($arr) && count($arr)) ? $arr[0] : $arr;
	}
}

/* Check for a valid IP address. */
function is_ip($str) {
	return filter_var($str, FILTER_VALIDATE_IP);
}

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

/* Save ini and cfg files to tmp file system and copy cfg changes to flash. */
function save_ini_file($file, $array) {
	global $plugin;

	$res = array();
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = PHP_EOL."[$key]";
			foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}

	/* Write changes to tmp file. */
	file_put_contents($file, implode(PHP_EOL, $res));

	/* Write changes to flash. */
	$file_path = pathinfo($file);
	if ($file_path['extension'] == "cfg") {
		file_put_contents("/boot/config/plugins/".$plugin."/".basename($file), implode(PHP_EOL, $res));
	}
}

/* Unassigned Devices logging. */
function unassigned_log($m, $type = "NOTICE") {
	global $plugin;

	if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
	$m		= print_r($m,true);
	$m		= str_replace("\n", " ", $m);
	$m		= str_replace('"', "'", $m);
	exec("/usr/bin/logger"." ".escapeshellarg($m)." -t ".escapeshellarg($plugin));
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
		if (! $fileinfo->isDir()) $paths[] = $path;
	}

	return $paths;
}

/* Remove characters that will cause issues in names. */
function safe_name($string, $convert_spaces = TRUE) {

	$string = stripcslashes($string);

	/* Convert reserved php characters to underscore. */
	$string = str_replace( array("'", '"', "?", "#", "&", "!"), "_", $string);

	/* Convert spaces to underscore. */
	if ($convert_spaces) {
		$string = str_replace(" " , "_", $string);
	}
	$string = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
	$string = preg_replace('/[^A-Za-z0-9\-_] /', '', $string);

	return trim($string);
}

/* Check for text in a file. */
function exist_in_file($file, $text) {
	return (preg_grep("%{$text}%", @file($file))) ? TRUE : FALSE;
}

/* Get the size, used, and free space on a mount point. */
function get_device_stats($mountpoint, $mounted, $active = TRUE) {
	global $paths, $plugin;

	$rc			= "";
	$tc			= $paths['df_status'];

	/* Get the device stats if it is mounted. */
	if ($mounted) {
		$df_status	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
		/* Run the stats script to update the state file. */
		if (($active) && ((time() - $df_status[$mountpoint]['timestamp']) > 90) ) {
			exec("/usr/local/emhttp/plugins/{$plugin}/scripts/get_ud_stats df_status ".escapeshellarg($tc)." ".escapeshellarg($mountpoint)." &");
		}

		/* Get the updated device stats. */
		$df_status	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
		if (isset($df_status[$mountpoint])) {
			$rc = $df_status[$mountpoint]['stats'];
		}
	}

	return preg_split('/\s+/', $rc);
}

/* Remove the partition and return the base device. */
function base_device($dev) {
	return (strpos($dev, "nvme") !== false) ? preg_replace("#\d+p#i", "", $dev) : preg_replace("#\d+#i", "", $dev);
}

/* Get the devX designation for this device from the devs.ini. */
function get_disk_dev($dev) {
	global $paths;

	$rc		= basename($dev);
	$sf		= $paths['dev_state'];

	/* Check for devs.ini file and get the devX designation for this device. */
	if (is_file($sf)) {
		$devs = parse_ini_file($sf, true);
		foreach ($devs as $d) {
			if (($d['device'] == basename($dev)) && isset($d['name'])) {
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
		$devs	= parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$rc[0] = $devs[$ud_dev]['numReads'];
			$rc[1] = $devs[$ud_dev]['numWrites'];
		}
	}

	/* Get the base device - remove the partition. */
	$dev	= base_device(basename($dev));
	$diskio	= @(array)parse_ini_file('state/diskload.ini');
	$data	= explode(' ',$diskio[$dev] ?? '0 0 0 0');

	/* Read rate. */
	$rc[2] = is_numeric($data[0]) ? $data[0] : 0;

	/* Write rate. */
	$rc[3] = is_numeric($data[1]) ? $data[1] : 0;

	return $rc;
}

/* Check to see if the disk is spinning up or down. */
function is_disk_running($ud_dev, $dev) {
	global $paths;

	$rc			= FALSE;
	$run_devs	= FALSE;
	$sf			= $paths['dev_state'];
	$tc			= $paths['run_status'];

	/* Check for dev state file to get the current spindown state. */
	if (is_file($sf)) {
		$devs	= parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$rc			= ($devs[$ud_dev]['spundown'] == '0') ? TRUE : FALSE;
			$device		= $ud_dev;
			$run_devs	= TRUE;
		}
	}

	/* If the spindown can't be gotten from the dev state, do hdparm to get it. */
	$run_status	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
	if (! $run_devs) {
		if (isset($run_status[$dev]) && (time() - $run_status[$dev]['timestamp']) < 60) {
			$rc		= ($run_status[$dev]['running'] == 'yes') ? TRUE : FALSE;
		} else {
			$state	= trim(timed_exec(10, "/usr/sbin/hdparm -C ".escapeshellarg($dev)." 2>/dev/null | /bin/grep -c standby"));
			$rc		= ($state == 0) ? TRUE : FALSE;
		}
		$device		= $dev;
	}

	$spin		= isset($run_status[$device]['spin']) ? $run_status[$device]['spin'] : "";
	$spin_time	= isset($run_status[$device]['spin']) ? $run_status[$device]['spin_time'] : 0;
	$run_status[$device] = array('timestamp' => time(), 'running' => $rc ? 'yes' : 'no', 'spin_time' => $spin_time, 'spin' => $spin);
	file_put_contents($tc, json_encode($run_status));

	return $rc;
}

/* Check for disk in the process of spinning up or down. */
function is_disk_spin($ud_dev, $running) {
	global $paths;

	$rc = FALSE;
	$tc = $paths['run_status'];
	$run_status	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();

	/* Is disk spinning up or down? */
	if (isset($run_status[$ud_dev]['spin'])) {
		/* Stop checking if it takes too long. */
		switch ($run_status[$ud_dev]['spin']) {
			case "up":
				if ((! $running) && ((time() - $run_status[$ud_dev]['spin_time']) < 15)) {
					$rc = TRUE;
				} 
				break;

			case "down":
				if (($running) && ((time() - $run_status[$ud_dev]['spin_time']) < 15)) {
					$rc = TRUE;
				}
				break;

			default:
				break;
		}

		/* See if we need to update the run spin status. */
		if ((! $rc) && ($run_status[$ud_dev]['spin'])) {
			$run_status[$ud_dev]['spin'] = "";
			$run_status[$ud_dev]['spin_time'] = 0;
			file_put_contents($tc, json_encode($run_status));
		}
	}

	return($rc);
}

/* Check to see if a samba server is online by pinging it. */
function is_samba_server_online($ip) {
	global $paths, $plugin;

	$is_alive		= FALSE;
	$server			= $ip;
	$tc				= $paths['ping_status'];

	/* Get the updated ping status. */
	$ping_status	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
	if (isset($ping_status[$server])) {
		$is_alive = ($ping_status[$server]['online'] == 'yes') ? TRUE : FALSE;
	}

	return $is_alive;
}

/* Check to see if a mount script is running. */
function is_script_running($cmd, $user=FALSE) {
	global $paths;

	$is_running = FALSE;
	/* Check for a command file. */
	if ($cmd) {
		$script_name	= $cmd;
		$tc				= $paths['script_run'];
		$script_run		= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();

		/* Check to see if the script was running. */
		if (isset($script_run[$script_name])) {
			$was_running = ($script_run[$script_name]['running'] == 'yes') ? TRUE : FALSE;
		} else {
			$was_running = FALSE;
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
		$is_running = shell_exec("/usr/bin/ps -ef | /bin/grep ".escapeshellarg(basename($cmd))." | /bin/grep -v 'grep' | /bin/grep ".escapeshellarg($source)) != "" ? TRUE : FALSE;
		$script_run[$script_name] = array('running' => $is_running ? 'yes' : 'no','user' => $user ? 'yes' : 'no');

		/* Update the current running state. */
		file_put_contents($tc, json_encode($script_run));
		if (($was_running) && (! $is_running)) {
			publish("reload", json_encode(array("rescan" => "yes"),JSON_UNESCAPED_SLASHES));
		}
	}

	return($is_running);
}

/* Get disk temperature. */
function get_temp($ud_dev, $dev, $running) {
	global $var, $paths;

	$rc		= "*";
	$temp	= "";
	$sf		= $paths['dev_state'];

	/* Get temperature from the devs.ini file. */
	if (is_file($sf)) {
		$devs = parse_ini_file($sf, true);
		if (isset($devs[$ud_dev])) {
			$temp	= $devs[$ud_dev]['temp'];
			$rc		= $temp;
		}
	}

	/* If devs.ini does not exist, then query the disk for the temperature. */
	if (($running) && (! $temp)) {
		$tc		= $paths['hdd_temp'];
		$temps	= is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
		if (isset($temps[$dev]) && (time() - $temps[$dev]['timestamp']) < $var['poll_attributes'] ) {
			$rc = $temps[$dev]['temp'];
		} else {
			$temp	= trim(timed_exec(10, "/usr/sbin/smartctl -n standby -A ".escapeshellarg($dev)." | /bin/awk 'BEGIN{t=\"*\"} $1==\"Temperature:\"{t=$2;exit};$1==190||$1==194{t=$10;exit} END{print t}'"));
			$temp	= ($temp < 128) ? $temp : "*";
			$temps[$dev] = array('timestamp' => time(), 'temp' => $temp);
			file_put_contents($tc, json_encode($temps));
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
			$rc = FALSE;
			break;
	}

	return $rc;
}

/* Format a disk. */
function format_disk($dev, $fs, $pass) {
	global $paths;

	/* Make sure it doesn't have partitions. */
	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev && count($d['partitions'])) {
			unassigned_log("Aborting format: disk '{$dev}' has '".count($d['partitions'])."' partition(s).");
			return FALSE;
		}
	}

	$max_mbr_blocks = hexdec("0xFFFFFFFF");
	$disk_blocks	= intval(trim(shell_exec("/sbin/blockdev --getsz ".escapeshellarg($dev)." | /bin/awk '{ print $1 }' 2>/dev/null")));
	$disk_schema	= ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
	$parted_fs		= ($fs == 'exfat') ? "fat32" : $fs;
	unassigned_log("Device '{$dev}' block size: {$disk_blocks}.");

	unassigned_log("Clearing partition table of disk '{$dev}'.");
	$o = trim(shell_exec("/usr/bin/dd if=/dev/zero of=".escapeshellarg($dev)." bs=2M count=1 2>&1"));
	if ($o) {
		unassigned_log("Clear partition result:\n{$o}");
	}

	unassigned_log("Reloading disk ".escapeshellarg($dev)." partition table.");
	$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
	if ($o) {
		unassigned_log("Reload partition table result:\n{$o}");
	}

	/* Update udev. */
	shell_exec("/sbin/udevadm trigger --action=change ".escapeshellarg($dev));

	if ($fs == "xfs" || $fs == "xfs-encrypted" || $fs == "btrfs" || $fs == "btrfs-encrypted") {
		$is_ssd = is_disk_ssd($dev);
		if ($disk_schema == "gpt") {
			unassigned_log("Creating Unraid compatible gpt partition on disk '{$dev}'.");
			shell_exec("/sbin/sgdisk -Z ".escapeshellarg($dev));

			/* Alignment is 4,096 for spinners and 1Mb for SSD */
			$alignment = $is_ssd ? "" : "-a 8";
			$o = shell_exec("/sbin/sgdisk -o ".$alignment." -n 1:32K:0 ".escapeshellarg($dev));
			if ($o) {
				unassigned_log("Create gpt partition table result:\n{$o}");
			}
		} else {
			unassigned_log("Creating Unraid compatible mbr partition on disk '{$dev}'.");
			/* Alignment is 4,096 for spinners and 1Mb for SSD */
			$start_sector = $is_ssd ? "2048" : "64";
			$o = shell_exec("/usr/local/sbin/mkmbr.sh ".escapeshellarg($dev)." ".escapeshellarg($start_sector));
			if ($o) {
				unassigned_log("Create mbr partition table result:\n{$o}");
			}
		}
		unassigned_log("Reloading disk ".escapeshellarg($dev)." partition table.");
		$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
		if ($o) {
			unassigned_log("Reload partition table result:\n{$o}");
		}
	} else {
		unassigned_log("Creating a 'gpt' partition table on disk '{$dev}'.");
		$o = trim(shell_exec("/usr/sbin/parted ".escapeshellarg($dev)." --script -- mklabel gpt 2>&1"));
		if ($o) {
			unassigned_log("Create 'gpt' partition table result:\n{$o}");
		}

		$o = trim(shell_exec("/usr/sbin/parted -a optimal ".escapeshellarg($dev)." --script -- mkpart primary ".escapeshellarg($parted_fs)." 0% 100% 2>&1"));
		if ($o) {
			unassigned_log("Create primary partition result:\n{$o}");
		}
	}

	unassigned_log("Formatting disk '{$dev}' with '$fs' filesystem.");
	if (strpos($fs, "-encrypted") !== false) {
		if (strpos($dev, "nvme") !== false) {
			$cmd = "luksFormat {$dev}p1";
		} else {
			$cmd = "luksFormat {$dev}1";
		}
		if (! $pass) {
			$o				= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
		} else {
			$luks			= basename($dev);
			$luks_pass_file	= "{$paths['luks_pass']}_".$luks;
			file_put_contents($luks_pass_file, $pass);
			$o				= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
			exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
		}
		if ($o)
		{
			unassigned_log("luksFormat error: {$o}");
			return FALSE;
		}
		$mapper = "format_".basename($dev);
		if (strpos($dev, "nvme") !== false) {
			$device	= $dev."p1";
		} else {
			$device	= $dev."1";
		}
		$cmd	= "luksOpen ".escapeshellarg($device)." ".escapeshellarg($mapper);
		if (! $pass) {
			$o = exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
		} else {
			$luks			= basename($dev);
			$luks_pass_file	= "{$paths['luks_pass']}_".$luks;
			file_put_contents($luks_pass_file, $pass);
			$o				= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
			exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
		}
		if ($o && stripos($o, "warning") === FALSE)
		{
			unassigned_log("luksOpen result: {$o}");
			return FALSE;
		}
		exec(get_format_cmd("/dev/mapper/{$mapper}", $fs),escapeshellarg($out), escapeshellarg($return));
		sleep(3);
		shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
	} else {
		if (strpos($dev, "nvme") !== false) {
			exec(get_format_cmd("{$dev}p1", $fs),escapeshellarg($out), escapeshellarg($return));
		} else {
			exec(get_format_cmd("{$dev}1", $fs),escapeshellarg($out), escapeshellarg($return));
		}
	}
	if ($return)
	{
		unassigned_log("Format disk '{$dev}' with '{$fs}' filesystem failed:\n".implode(PHP_EOL, $out));
		return FALSE;
	}
	if ($out) {
		unassigned_log("Format disk '{$dev}' with '{$fs}' filesystem:\n".implode(PHP_EOL, $out));
	}

	sleep(3);
	unassigned_log("Reloading disk '{$dev}' partition table.");
	$o = trim(shell_exec("/usr/sbin/hdparm -z ".escapeshellarg($dev)." 2>&1"));
	if ($o) {
		unassigned_log("Reload partition table result:\n{$o}");
	}

	/* Clear the $pass variable. */
	unset($pass);

	/* Update udev. */
	shell_exec("/sbin/udevadm trigger --action=change ".escapeshellarg($dev));

	sleep(3);

	/* Refresh partition information. */
	exec("/usr/sbin/partprobe ".escapeshellarg($dev));

	return TRUE;
}

/* Remove a disk partition. */
function remove_partition($dev, $part) {

	$rc = TRUE;

	foreach (get_all_disks_info() as $d) {
		if ($d['device'] == $dev) {
			foreach ($d['partitions'] as $p) {
				if ($p['part'] == $part && $p['target']) {
					unassigned_log("Aborting removal: partition '{$part}' is mounted.");
					return FALSE;
				} 
			}
		}
	}
	unassigned_log("Removing partition '{$part}' from disk '{$dev}'.");
	$out = shell_exec("/usr/sbin/parted ".escapeshellarg($dev)." --script -- rm ".escapeshellarg($part)." 2>&1");
	if ($out) {
		unassigned_log("Remove parition failed: '{$out}'.");
		$rc = FALSE;
	}

	/* Undate udev info. */
	shell_exec("/sbin/udevadm trigger --action=change ".escapeshellarg($dev));

	sleep(5);

	/* Refresh partition information. */
	exec("/usr/sbin/partprobe ".escapeshellarg($dev));

	return $rc;
}

/* Procedure to determine the time a command takes to run. */
function benchmark() {
	$params		= func_get_args();
	$function	= $params[0];
	array_shift($params);
	$time		= -microtime(true); 
	$out		= call_user_func_array($function, $params);
	$time	   += microtime(true); 
	$type		= ($time > 10) ? "INFO" : "DEBUG";
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
	}

	return $out;
}

/* Find the file system of a luks device. */
function luks_fs_type($dev) {

	$rc = "luks";
	if ($dev) {
		$return	= shell_exec( "/bin/cat /proc/mounts | /bin/grep -w ".escapeshellarg($dev)." | /bin/awk '{print $3}'");
		$rc		= (! $return) ? $rc : $return;
	}

	return $rc;
}

#########################################################
############		CONFIG FUNCTIONS		#############
#########################################################

function get_config($sn, $var) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	return (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
}

function set_config($sn, $var, $val) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn][$var] = htmlentities($val, ENT_COMPAT);
	save_ini_file($config_file, $config);
	return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
}

function is_automount($sn, $usb=FALSE) {
	$auto = get_config($sn, "automount");
	$auto_usb = get_config("Config", "automount_usb");
	$pass_through = get_config($sn, "pass_through");
	return ( (($pass_through != "yes") && ($auto == "yes")) || ($usb && $auto_usb == "yes" && (! $auto)) ) ? TRUE : FALSE;
}

function is_read_only($sn) {
	$read_only = get_config($sn, "read_only");
	$pass_through = get_config($sn, "pass_through");
	return ( $pass_through != "yes" && $read_only == "yes" ) ? TRUE : FALSE;
}

function is_pass_through($sn) {
	return (get_config($sn, "pass_through") == "yes") ? TRUE : FALSE;
}

function toggle_automount($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["automount"] == "yes") ? 'true' : 'false';
}

function toggle_read_only($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["read_only"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["read_only"] == "yes") ? 'true' : 'false';
}

function toggle_pass_through($sn, $status) {
	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	$config[$sn]["pass_through"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$sn]["pass_through"] == "yes") ? 'true' : 'false';
}

/* Execute the device script. */
function execute_script($info, $action, $testing=FALSE) { 
	global $paths;

	/* Set environment variables. */
	putenv("ACTION={$action}");
	foreach ($info as $key => $value) {
		putenv(strtoupper($key)."={$value}");
	}
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
	$cmd = escapeshellcmd($info['command']);
	$bg = ($info['command_bg'] != "false" && $action == "ADD") ? "&" : "";
	if ($cmd) {
		$command_script = $paths['scripts'].basename($cmd);
		copy($cmd, $command_script);
		@chmod($command_script, 0755);
		unassigned_log("Running device script: '".basename($cmd)."' with action '{$action}'.");

		$script_running = is_script_running($cmd);
		if ((! $script_running) || (($script_running) && ($action != "ADD"))) {
			if (! $testing) {
				if ($action == "REMOVE" || $action == "ERROR_MOUNT" || $action == "ERROR_UNMOUNT") {
					sleep(1);
				}
				$cmd = isset($info['serial']) ? "$command_script > /tmp/{$info['serial']}.log 2>&1 $bg" : "$command_script > /tmp/".preg_replace('~[^\w]~i', '', $info['device']).".log 2>&1 $bg";

				/* Run the script. */
				exec($cmd, escapeshellarg($out), escapeshellarg($return));
				if ($return) {
					unassigned_log("Error: device script failed: '{$return}'");
				}
			} else {
				return $command_script;
			}
		} else {
			unassigned_log("Device script '".basename($cmd)."' aleady running!");
		}
	}

	return FALSE;
}

/* Remove a historical disk configuration. */
function remove_config_disk($sn) {

	$config_file = $GLOBALS["paths"]["config_file"];
	$config = @parse_ini_file($config_file, true);
	if ( isset($config[$sn]) ) {
		unassigned_log("Removing configuration '{$sn}'.");
	}
	/* Remove up to three partition script files. */
	for ($i = 1; $i <= 5; $i++) {
		$command = "command.".$i;
		$cmd = $config[$sn][$command];
		if ( isset($cmd) && is_file($cmd) ) {
			@unlink($cmd);
			unassigned_log("Removing script '{$cmd}'.");
		}
	}
	unset($config[$sn]);
	save_ini_file($config_file, $config);
	return (! isset($config[$sn])) ? TRUE : FALSE;
}

/* Is a disk device an SSD? */
function is_disk_ssd($device) {

	$rc		= FALSE;
	/* Get the base device - remove the partition. */
	$device	= base_device(basename($device));
	if (strpos($device, "nvme") === false) {
		$file = "/sys/block/".basename($device)."/queue/rotational";
		if (is_file($file)) {
			$rc = (@file_get_contents($file) == 0) ? TRUE : FALSE;
		} else {
			unassigned_log("Warning: Can't get rotational setting of '{$device}'.");
		}
	} else {
		$rc = TRUE;
	}

	return $rc;
}

/* Spin disk up/down using Unraid api. */
function spin_disk($down, $dev) {
	if ($down) {
		exec(escapeshellcmd("/usr/local/sbin/emcmd cmdSpindown=".escapeshellarg($dev)));
	} else {
		exec(escapeshellcmd("/usr/local/sbin/emcmd cmdSpinup=".escapeshellarg($dev)));
	}
}

#########################################################
############		MOUNT FUNCTIONS			#############
#########################################################

/* Is a device mounted? */
function is_mounted($dev, $dir=FALSE) {

	$rc = FALSE;
	if ($dev) {
		$data	= timed_exec(1, "/sbin/mount");
		$append	= ($dir) ? " " : " on";
		$rc		= (strpos($data, $dev.$append) != 0) ? TRUE : FALSE;
	}

	return $rc;
}

/* Get the mount parameters based on the file system. */
function get_mount_params($fs, $dev, $ro = FALSE) {
	global $paths;

	$rc				= "";
	$config_file	= $paths['config_file'];
	$config			= @parse_ini_file($config_file, true);
	if (($config['Config']['discard'] != "no") && ($fs != "cifs") && ($fs != "nfs")) {
		$discard = is_disk_ssd($dev) ? ",discard" : "";;
	} else {
		$discard = "";
	}
	$rw	= $ro ? "ro" : "rw";
	switch ($fs) {
		case 'hfsplus':
			$rc = "force,{$rw},users,async,umask=000";
			break;

		case 'xfs':
			$rc = "{$rw},noatime,nodiratime{$discard}";
			break;

		case 'btrfs':
			$rc = "{$rw},auto,async,noatime,nodiratime{$discard}";
			break;

		case 'exfat':
			$rc = "{$rw},auto,async,noatime,nodiratime,nodev,nosuid,umask=000";
			break;

		case 'vfat':
			$rc = "{$rw},auto,async,noatime,nodiratime,nodev,nosuid,iocharset=utf8,umask=000";
			break;

		case 'ntfs':
			$rc = "{$rw},auto,async,noatime,nodiratime,nodev,nosuid,nls=utf8,umask=000";
			break;

		case 'crypto_LUKS':
			$rc = "{$rw},noatime,nodiratime{$discard}";
			break;

		case 'ext4':
			$rc = "{$rw},auto,noatime,nodiratime,async,nodev,nosuid{$discard}";
			break;

		case 'cifs':
			$credentials_file = "{$paths['credentials']}_".basename($dev);
			$rc = "rw,noserverino,nounix,iocharset=utf8,file_mode=0777,dir_mode=0777,uid=99,gid=100%s,credentials=".escapeshellarg($credentials_file);
			break;

		case 'nfs':
			$rc = "rw,noacl";
			break;

		default:
			$rc = "{$rw},auto,async,noatime,nodiratime";
			break;
	}

	return $rc;
}

/* Mount a device. */
function do_mount($info) {
	global $var, $paths;

	$rc = FALSE;
	if ($info['fstype'] == "cifs" || $info['fstype'] == "nfs") {
		$rc = do_mount_samba($info);
	} else if($info['fstype'] == "loop") {
		$rc = do_mount_iso($info);
	} else if ($info['fstype'] == "crypto_LUKS") {
		if (! is_mounted($info['device']) || ! is_mounted($info['mountpoint'], TRUE)) {
			$luks	= basename($info['device']);
			$discard = is_disk_ssd($info['luks']) ? "--allow-discards" : "";
			$cmd	= "luksOpen $discard ".escapeshellarg($info['luks'])." ".escapeshellarg($luks);
			$pass	= decrypt_data(get_config($info['serial'], "pass"));
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
				file_put_contents($luks_pass_file, $pass);
				unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/sbin/cryptsetup ".escapeshellcmd($cmd)." -d ".escapeshellarg($luks_pass_file)." 2>&1");
				exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
				unset($pass);
			}
			if ($o && stripos($o, "warning") === FALSE) {
				unassigned_log("luksOpen result: {$o}");
				shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg(basename($info['device'])));
			} else {
				$rc = do_mount_local($info);
			}
		} else {
			unassigned_log("Drive '{$info['device']}' already mounted.");
		}
	} else {
		$rc = do_mount_local($info);
	}

	return $rc;
}

/* Mount a disk device. */
function do_mount_local($info) {
	global $paths;

	$rc		= FALSE;
	$dev	= $info['device'];
	$dir	= $info['mountpoint'];
	$fs		= $info['fstype'];
	$ro		= ($info['read_only'] == 'yes') ? TRUE : FALSE;
	if (! is_mounted($dev) || ! is_mounted($dir, TRUE)) {
		if ($fs) {
			@mkdir($dir, 0777, TRUE);
			if ($fs != "crypto_LUKS") {
				if ($fs == "apfs") {
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
			if (($fs == "apfs") && ! (is_file("/usr/bin/apfs-fuse"))) {
				$o = "Install Unassigned Devices Plus to mount an apfs file system";
			} else {
				$o = shell_exec(escapeshellcmd($cmd)." 2>&1");
			}
			if ($fs == "apfs") {
				/* Remove all password variables. */
				unset($password);
				unset($recovery);
				unset($cmd);
			}
			if ($o && $fs == "ntfs" && is_mounted($dev)) {
				unassigned_log("Mount warning: {$o}.");
			}
			for ($i=0; $i < 5; $i++) {
				if (is_mounted($dev) && is_mounted($dir, TRUE)) {
					@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);

					unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

					$rc = TRUE;
					break;
				} else {
					sleep(0.5);
				}
			}
			if (! $rc) {
				if ($fs == "crypto_LUKS" ) {
					shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg(basename($info['device'])));
				}
				unassigned_log("Mount of '{$dev}' failed: '{$o}'");
				@rmdir($dir);
			}
		} else {
			unassigned_log("No filesystem detected on '{$dev}'.");
		}
	} else {
		unassigned_log("Drive '{$dev}' already mounted.");
	}

	return $rc;
}

/* Unmount a device. */
function do_unmount($dev, $dir, $force=FALSE, $smb=FALSE, $nfs=FALSE) {
	global $paths;

	$rc = FALSE;
	if ( is_mounted($dev) && is_mounted($dir, TRUE) ) {
		unassigned_log("Synching file system on '{$dir}'.");
		exec("/bin/sync -f ".escapeshellarg($dir));
		$cmd = "/sbin/umount".($smb ? " -t cifs" : "").($force ? " -fl" : ($nfs ? " -l" : ""))." ".escapeshellarg($dev)." 2>&1";
		unassigned_log("Unmount cmd: {$cmd}");
		$timeout = ($smb || $nfs) ? ($force ? 30 : 10) : 90;
		$o = timed_exec($timeout, $cmd);
		for ($i=0; $i < 5; $i++) {
			if (! is_mounted($dev) && ! is_mounted($dir, TRUE)) {
				if (is_dir($dir)) {
					@rmdir($dir);
					$link = $paths['usb_mountpoint']."/".basename($dir);
					if (is_link($link)) {
						@unlink($link);
					}
				}

				unassigned_log("Successfully unmounted '{$dev}'");
				$rc = TRUE;
				break;
			} else {
				sleep(0.5);
			}
		}
		if (! $rc) {
			unassigned_log("Unmount of '{$dev}' failed: '{$o}'"); 
		}
	} else {
		unassigned_log("Cannot unmount '{$dev}'. UD did not mount the device.");
	}

	return $rc;
}

#########################################################
############		SHARE FUNCTIONS			#############
#########################################################

function config_shared($sn, $part, $usb=FALSE) {
	$share = get_config($sn, "share.{$part}");
	$auto_usb = get_config("Config", "automount_usb");
	return (($share == "yes") || ($usb && $auto_usb == "yes" && (! $share))) ? TRUE : FALSE; 
}

function toggle_share($serial, $part, $status) {
	$new = ($status == "true") ? "yes" : "no";
	set_config($serial, "share.{$part}", $new);
	return ($new == 'yes') ? TRUE : FALSE;
}

/* Add mountpoint to samba. */
function add_smb_share($dir, $recycle_bin=TRUE) {
	global $paths, $var, $users;

	if ( ($var['shareSMBEnabled'] != "no") ) {
		/* Remove special characters from share name. */
		$share_name = str_replace( array("(", ")"), "", basename($dir));
		$config = @parse_ini_file($paths['config_file'], true);
		$config = $config["Config"];

		$vfs_objects = "";
		if (($recycle_bin) || ($var['enableFruit']) == 'yes') {
			$vfs_objects .= "\n\tvfs objects = ";
			if ($var['enableFruit'] == 'yes') {
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
				elseif ($config["smb_{$v}"] == "read-write") {$write_users[] = $v;}
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
			} elseif ($config["case_names"] == "yes") {
				$case_names = "\n\tcase sensitive = yes\n\tpreserve case = yes\n\tshort preserve case = yes";
			} elseif ($config["case_names"] == "force") {
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

		if (! is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");

		unassigned_log("Adding SMB share '{$share_name}'.");
		file_put_contents($share_conf, $share_cont);
		if (! exist_in_file($paths['smb_extra'], $share_conf)) {
			$c		= (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
			$c[]	= "";
			$c[]	= "include = $share_conf";

			/* Do some cleanup */
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c		= array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c		= preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);

			/* If the recycle bin plugin is installed, add the recycle bin to the share. */
			if ($recycle_bin) {
				/* Add the recycle bin parameters if plugin is installed */
				$recycle_script = "plugins/recycle.bin/scripts/configure_recycle_bin";
				if (is_file($recycle_script)) {
					$recycle_bin_cfg = parse_ini_file( "/boot/config/plugins/recycle.bin/recycle.bin.cfg" );
					if ($recycle_bin_cfg['INCLUDE_UD'] == "yes") {
						unassigned_log("Enabling the Recycle Bin on share '{$share_name}'.");
						shell_exec(escapeshellcmd("$recycle_script $share_conf"));
					}
				}
			} else {
				file_put_contents($share_conf, "\n", FILE_APPEND);
			}
		}

		timed_exec(5, "$(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
	}

	return TRUE;
}

/* Remove a samba share. */
function rm_smb_share($dir) {
	global $paths, $var;

	/* If samba is enabled remove the share. */
	if ( ($var['shareSMBEnabled'] != "no") ) {
		/* Remove special characters from share name */
		$share_name = str_replace( array("(", ")"), "", basename($dir));
		$share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
		if (is_file($share_conf)) {
			@unlink($share_conf);
			unassigned_log("Removing SMB share '{$share_name}'");
		}
		if (exist_in_file($paths['smb_extra'], $share_conf)) {
			$c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();

			/* Do some cleanup. */
			$smb_extra_includes = array_unique(preg_grep("/include/i", $c));
			foreach($smb_extra_includes as $key => $inc) if (! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
			$c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
			$c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
			file_put_contents($paths['smb_extra'], $c);
			timed_exec(5, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) close-share ".escapeshellarg($share_name)." 2>&1");
			timed_exec(5, "/usr/bin/smbcontrol $(/bin/cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
		}
	}

	return TRUE;
}

/* Add a mount to NFS share. */
function add_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		unassigned_log("Adding NFS share '{$dir}'.");
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if (! exist_in_file($file, "\"{$dir}\"")) {
				$c			= (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
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
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload		= TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}

	return TRUE;
}

/* Remove a NFS share. */
function rm_nfs_share($dir) {
	global $var;

	if ( ($var['shareNFSEnabled'] == "yes") && (get_config("Config", "nfs_export") == "yes") ) {
		$reload = FALSE;
		unassigned_log("Removing NFS share '{$dir}'.");
		foreach (array("/etc/exports","/etc/exports-") as $file) {
			if ( exist_in_file($file, "\"{$dir}\"") && strlen($dir)) {
				$c		= (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
				$c		= preg_grep("@\"{$dir}\"@i", $c, PREG_GREP_INVERT);
				$c[]	= "";
				file_put_contents($file, implode(PHP_EOL, $c));
				$reload	= TRUE;
			}
		}
		if ($reload) shell_exec("/usr/sbin/exportfs -ra 2>/dev/null");
	}

	return TRUE;
}

/* Remove all samba and NFS shares for mounted devices. */
function remove_shares() {
	/* Disk mounts */
	foreach (get_unassigned_disks() as $name => $disk) {
		foreach ($disk['partitions'] as $p) {
			$info = get_partition_info($p);
			if ( $info['mounted'] ) {
				$device = $disk['device'];
				$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV) : get_udev_info($device, NULL);
				if (config_shared( $info['serial'], $info['part'], strpos($attrs['DEVPATH'], "usb"))) {
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
				$attrs = (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV) : get_udev_info($device, NULL);
				if (config_shared( $info['serial'], $info['part'], strpos($attrs['DEVPATH'], "usb"))) {
					add_smb_share($info['mountpoint']);
					add_nfs_share($info['mountpoint']);
				}
			}
		}
	}

	/* SMB Mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			add_smb_share($info['mountpoint'], FALSE);
		}
	}

	/* ISO File Mounts */
	foreach (get_iso_mounts() as $name => $info) {
		if ( $info['mounted'] ) {
			add_smb_share($info['mountpoint'], FALSE);
			add_nfs_share($info['mountpoint']);
		}
	}
}

#########################################################
############		SAMBA FUNCTIONS			#############
#########################################################

function get_samba_config($source, $var) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$config = @parse_ini_file($config_file, true, INI_SCANNER_RAW);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function set_samba_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$config = @parse_ini_file($config_file, true);
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

/* Encrypt passwords. */
function encrypt_data($data) {
	$key = get_config("Config", "key");
	if ((! $key) || strlen($key) != 32) {
		$key = substr(base64_encode(openssl_random_pseudo_bytes(32)), 0, 32);
		set_config("Config", "key", $key);
	}
	$iv = get_config("Config", "iv");
	if ((! $iv) || strlen($iv) != 16) {
		$iv = substr(base64_encode(openssl_random_pseudo_bytes(16)), 0, 16);
		set_config("Config", "iv", $iv);
	}

	$val = openssl_encrypt($data, 'aes256', $key, $options=0, $iv);
	$val = str_replace("\n", "", $val);

	return($val);
}

/* Decrypt passwords. */
function decrypt_data($data) {

	$key	= get_config("Config", "key");
	$iv		= get_config("Config", "iv");
	$val	= openssl_decrypt($data, 'aes256', $key, $options=0, $iv);

	if (! preg_match("//u", $val)) {
		unassigned_log("Warning: Password is not UTF-8 encoded");
		$val = "";
	}

	return($val);
}

function is_samba_automount($sn) {
	$auto = get_samba_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : FALSE);
}

function is_samba_share($sn) {
	$smb_share = get_samba_config($sn, "smb_share");
	return ( ($smb_share) ? ( ($smb_share == "yes") ? TRUE : FALSE ) : TRUE);
}

/* Get all defined samba and NFS remote shares. */
function get_samba_mounts() {
	global $paths;

	$o = array();
	$config_file = $paths['samba_mount'];
	$samba_mounts = @parse_ini_file($config_file, true);
	if (is_array($samba_mounts)) {
		foreach ($samba_mounts as $device => $mount) {
			$mount['device']	= $device;
			$mount['name']		= $device;

			/* Set the mount protocol. */
			if ($mount['protocol'] == "NFS") {
				$mount['fstype'] = "nfs";
				$path = basename($mount['path']);
			} else {
				$mount['fstype'] = "cifs";
				$path = $mount['path'];
			}

			$mount['mounted']		= is_mounted(($mount['fstype'] == "cifs") ? "//".$mount['ip']."/".$path : $mount['device']);
			$mount['is_alive']		= is_samba_server_online($mount['ip']);
			$mount['automount']		= is_samba_automount($mount['name']);
			$mount['smb_share']		= is_samba_share($mount['name']);
			if (! $mount['mountpoint']) {
				$mount['mountpoint'] = "{$paths['usb_mountpoint']}/{$mount['ip']}_{$path}";
				if (! $mount['mounted'] || ! is_mounted($mount['mountpoint'], TRUE) || is_link($mount['mountpoint'])) {
					$mount['mountpoint'] = "{$paths['remote_mountpoint']}/{$mount['ip']}_{$path}";
				}
			} else {
				$path = basename($mount['mountpoint']);
				$mount['mountpoint'] = "{$paths['usb_mountpoint']}/{$path}";
				if (! $mount['mounted'] || ! is_mounted($mount['mountpoint'], TRUE) || is_link($mount['mountpoint'])) {
					$mount['mountpoint'] = "{$paths['remote_mountpoint']}/{$path}";
				}
			}
			$stats					= get_device_stats($mount['mountpoint'], $mount['mounted'], $mount['is_alive']);
			$mount['size']			= intval($stats[0])*1024;
			$mount['used']			= intval($stats[1])*1024;
			$mount['avail']			= intval($stats[2])*1024;
			$mount['target']		= $mount['mountpoint'];
			$mount['prog_name']		= basename($mount['command'], ".sh");
			$mount['command']		= get_samba_config($mount['device'],"command");
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
	global $use_netbios, $paths;

	$rc = FALSE;
	$config_file	= $paths['config_file'];
	$config			= @parse_ini_file($config_file, true);

	/* Be sure the server online status is current. */
	$info['is_alive'] = is_samba_server_online($info['ip']);
	if ($info['is_alive']) {
		$dir	= $info['mountpoint'];
		$fs		= $info['fstype'];
		$dev	= ($fs == "cifs") ? "//".$info['ip']."/".$info['path'] : $info['device'];
		if (! is_mounted($dev) || ! is_mounted($dir, TRUE)) {
			@mkdir($dir, 0777, TRUE);
			if ($fs == "nfs") {
				$params	= get_mount_params($fs, $dev);
				$nfs	= (get_config("Config", "nfs_version") == "4") ? "nfs4" : "nfs";
				$cmd	= "/sbin/mount -t ".escapeshellarg($nfs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);
				unassigned_log("Mount NFS command: {$cmd}");
				$o		= timed_exec(10, $cmd." 2>&1");
				if ($o) {
					unassigned_log("NFS mount failed: '{$o}'.");
				}
			} else {
				$credentials_file = "{$paths['credentials']}_".basename($dev);
				file_put_contents("$credentials_file", "username=".($info['user'] ? $info['user'] : 'guest')."\n");
				file_put_contents("$credentials_file", "password=".decrypt_data($info['pass'])."\n", FILE_APPEND);
				file_put_contents("$credentials_file", "domain=".$info['domain']."\n", FILE_APPEND);
				/* If the smb version is not required, just mount the remote share with no version. */
				$smb_version = (get_config("Config", "smb_version") == "yes") ? TRUE : FALSE;
				if (! $smb_version) {
					$ver	= "";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);
					unassigned_log("Mount SMB share '{$dev}' using SMB default protocol.");
					unassigned_log("Mount SMB command: {$cmd}");
					$o		= timed_exec(10, $cmd." 2>&1");
				}
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === FALSE) && (strpos($o, "Network is unreachable") === FALSE)) {
					if (! $smb_version) {
						unassigned_log("SMB default protocol mount failed: '{$o}'.");
					}
					$ver	= ",vers=3.0";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t $fs -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);
					unassigned_log("Mount SMB share '{$dev}' using SMB3 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");
					$o		= timed_exec(10, $cmd." 2>&1");
				}
				if (! is_mounted($dev) && (strpos($o, "Permission denied") === FALSE) && (strpos($o, "Network is unreachable") === FALSE)) {
					unassigned_log("SMB3 mount failed: '{$o}'.");
					/* If the mount failed, try to mount with samba vers=2.0. */
					$ver	= ",vers=2.0";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);
					unassigned_log("Mount SMB share '{$dev}' using SMB2 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");
					$o		= timed_exec(10, $cmd." 2>&1");
				}
				if ((! is_mounted($dev) && ($use_netbios == 'yes')) && (strpos($o, "Permission denied") === FALSE) && (strpos($o, "Network is unreachable") === FALSE)) {
					unassigned_log("SMB2 mount failed: '{$o}'.");
					/* If the mount failed, try to mount with samba vers=1.0. */
					$ver	= ",sec=ntlm,vers=1.0";
					$params	= sprintf(get_mount_params($fs, $dev), $ver);
					$cmd	= "/sbin/mount -t ".escapeshellarg($fs)." -o ".$params." ".escapeshellarg($dev)." ".escapeshellarg($dir);
					unassigned_log("Mount SMB share '{$dev}' using SMB1 protocol.");
					unassigned_log("Mount SMB command: {$cmd}");
					$o		= timed_exec(10, $cmd." 2>&1");
					if ($o) {
						unassigned_log("SMB1 mount failed: '{$o}'.");
						$rc = FALSE;
					}
				}
				exec("/bin/shred -u ".escapeshellarg($credentials_file));
				unset($pass);
			}
			if (is_mounted($dev) && is_mounted($dir, TRUE)) {
				@chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
				$link = $paths['usb_mountpoint']."/";
				if ((get_config("Config", "symlinks") == "yes" ) && (dirname($dir) == $paths['remote_mountpoint'])) {
					$dir .= "/".
					exec("/bin/ln -s ".escapeshellarg($dir)." ".escapeshellarg($link));
				}
				unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

				$rc = TRUE;
			} else {
				@rmdir($dir);
				unassigned_log("Mount of '{$dev}' failed: '{$o}'.");
			}
		} else {
			unassigned_log("Share '{$dev}' already mounted.");
		}
	} else {
		unassigned_log("Remote SMB/NFS server '{$info['ip']}' is offline and share '{$info['device']}' cannot be mounted."); 
	}

	return $rc;
}

function toggle_samba_automount($source, $status) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$config = @parse_ini_file($config_file, true);
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
}

function toggle_samba_share($source, $status) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$config = @parse_ini_file($config_file, true);
	$config[$source]["smb_share"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["smb_share"] == "yes") ? TRUE : FALSE;
}

/* Remove the samba remote mount configuration. */
function remove_config_samba($source) {
	$config_file = $GLOBALS["paths"]["samba_mount"];
	$config = @parse_ini_file($config_file, true);
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
	return (! isset($config[$source])) ? TRUE : FALSE;
}

#########################################################
############		ISO FILE FUNCTIONS		#############
#########################################################

function get_iso_config($source, $var) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	$config = @parse_ini_file($config_file, true, INI_SCANNER_RAW);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function set_iso_config($source, $var, $val) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	$config = @parse_ini_file($config_file, true);
	$config[$source][$var] = $val;
	save_ini_file($config_file, $config);
	return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
}

function is_iso_automount($sn) {
	$auto = get_iso_config($sn, "automount");
	return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : FALSE);
}

/* Get all ISO moints. */
function get_iso_mounts() {
	global $paths;

	$o = array();
	$config_file = $paths['iso_mount'];
	$iso_mounts = @parse_ini_file($config_file, true);
	if (is_array($iso_mounts)) {
		foreach ($iso_mounts as $device => $mount) {
			$mount['device']		= $device;
			$mount['fstype']		= "loop";
			$mount['automount'] = is_iso_automount($mount['device']);
			if (! $mount["mountpoint"]) {
				$mount["mountpoint"] = preg_replace("%\s+%", "_", "{$paths['usb_mountpoint']}/{$mount['share']}");
			}
			$mount['target']		= $mount['mountpoint'];
			$is_alive				= is_file($mount['file']);
			$mount['mounted']		= is_mounted($mount['device']);
			$stats					= get_device_stats($mount['mountpoint'], $mount['mounted']);
			$mount['size']			= intval($stats[0])*1024;
			$mount['used']			= intval($stats[1])*1024;
			$mount['avail']			= intval($stats[2])*1024;
			$mount['prog_name']		= basename($mount['command'], ".sh");
			$mount['command']		= get_iso_config($mount['device'],"command");
			$mount['user_command']	= get_iso_config($mount['device'],"user_command");
			$mount['logfile']		= ($mount['prog_name']) ? $paths['device_log'].$mount['prog_name'].".log" : "";
			$o[] = $mount;
		}
	} else {
		unassigned_log("Error: unable to get the ISO mounts.");
	}

	return $o;
}

/* Mount ISO file. */
function do_mount_iso($info) {
	global $paths;

	$rc = FALSE;
	$dev = $info['device'];
	$dir = $info['mountpoint'];
	if (is_file($info['file'])) {
		if (! is_mounted($dev) || ! is_mounted($dir, TRUE)) {
			@mkdir($dir, 0777, TRUE);
			$cmd = "/sbin/mount -ro loop ".escapeshellarg($dev)." ".escapeshellarg($dir);
			unassigned_log("Mount iso command: mount -ro loop '{$dev}' '{$dir}'");
			$o = timed_exec(15, $cmd." 2>&1");
			if (is_mounted($dev) && is_mounted($dir, TRUE)) {
				unassigned_log("Successfully mounted '{$dev}' on '{$dir}'.");

				$rc = TRUE;
			} else {
				@rmdir($dir);
				unassigned_log("Mount of '{$dev}' failed: '{$o}'");
			}
		} else {
			unassigned_log("Share '{$dev}' already mounted.");
		}
	} else {
		unassigned_log("Error: ISO file '{$info[file]}' is missing and cannot be mounted.");
	}

	return $rc;
}

function toggle_iso_automount($source, $status) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	$config = @parse_ini_file($config_file, true);
	$config[$source]["automount"] = ($status == "true") ? "yes" : "no";
	save_ini_file($config_file, $config);
	return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
}

/* Remove ISO configuration. */
function remove_config_iso($source) {
	$config_file = $GLOBALS["paths"]["iso_mount"];
	$config = @parse_ini_file($config_file, true);
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
	return (! isset($config[$source])) ? TRUE : FALSE;
}


#########################################################
############		DISK FUNCTIONS			#############
#########################################################

/* Get an array of all unassigned disks. */
function get_unassigned_disks() {
	global $disks;

	$ud_disks = $paths = $unraid_disks = array();

	/* Get all devices by id. */
	foreach (listDir("/dev/disk/by-id/") as $p) {
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

	foreach ($unraid_disks as $k) {$o .= " $k\n";};

	/* Create the array of unassigned devices. */
	foreach ($paths as $path => $d) {
		if ($d && (preg_match("#^(.(?!wwn|part))*$#", $d))) {
			if (! in_array($path, $unraid_disks)) {
				if (in_array($path, array_map(function($ar){return $ar['device'];}, $ud_disks)) ) continue;
				$m = array_values(preg_grep("|$d.*-part\d+|", $paths));
				natsort($m);
				$ud_disks[$d] = array("device"=>$path,"type"=>"ata", "partitions"=>$m);
			}
		}
	}

	return $ud_disks;
}

/* Get all the disk information for each disk device. */
function get_all_disks_info($bus = "all") {

	$ud_disks = get_unassigned_disks();
	if (is_array($ud_disks)) {
		foreach ($ud_disks as $key => $disk) {
			$dp = time();
			if ($disk['type'] != $bus && $bus != "all") continue;
			$disk['size']	= intval(trim(timed_exec(5, "/bin/lsblk -nb -o size ".escapeshellarg(realpath($key))." 2>/dev/null")));
			$disk			= array_merge($disk, get_disk_info($key));
			foreach ($disk['partitions'] as $k => $p) {
				if ($p) $disk['partitions'][$k] = get_partition_info($p);
			}
			$ud_disks[$key]	= $disk;
		}
	} else {
		unassigned_log("Error: unable to get unassigned disks.");
		$ud_disks = array();
	}

	return $ud_disks;
}

/* Get the udev disk information. */
function get_udev_info($dev, $udev = NULL) {
	global $paths;

	$state	= is_file($paths['state']) ? @parse_ini_file($paths['state'], true, INI_SCANNER_RAW) : array();
	$device	= safe_name($dev);
	if ($udev) {
		$state[$device] = $udev;
		save_ini_file($paths['state'], $state);
		$rc	= $udev;
	} else if (array_key_exists($device, $state)) {
		$rc	= $state[$device];
	} else {
		$state[$device] = parse_ini_string(timed_exec(5,"/sbin/udevadm info --query=property --path $(/sbin/udevadm info -q path -n ".escapeshellarg($device)." 2>/dev/null) 2>/dev/null"), INI_SCANNER_RAW);
		save_ini_file($paths['state'], $state);
		$rc	= $state[$device];
	}

	return $rc;
}

/* Get information on specific disk device. */
function get_disk_info($device) {

	$disk						= array();
	$attrs						= (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV) : get_udev_info($device, NULL);
	$device						= realpath($device);
	$disk['serial_short']		= isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
	$disk['serial']				= "{$attrs['ID_MODEL']}_{$disk['serial_short']}";
	$disk['device']				= $device;
	$disk['ud_dev']				= get_disk_dev($device);
	$disk['ssd']				= is_disk_ssd($device);
	$rw							= get_disk_reads_writes($disk['ud_dev'], $device);
	$disk['reads']				= $rw[0];
	$disk['writes']				= $rw[1];
	$disk['read_rate']			= $rw[2];
	$disk['write_rate']			= $rw[3];
	$disk['running']			= is_disk_running($disk['ud_dev'], $disk['device']);
	$disk['temperature']		= get_temp($disk['ud_dev'], $disk['device'], $disk['running']);
	$disk['command']			= get_config($disk['serial'],"command.1");
	$disk['user_command']		= get_config($disk['serial'],"user_command.1");
	$disk['show_partitions']	= (get_config($disk['serial'], "show_partitions") == "no") ? FALSE : TRUE;

	return $disk;
}

/* Get partition information. */
function get_partition_info($device) {
	global $_ENV, $paths;

	$disk	= array();
	$attrs	= (isset($_ENV['DEVTYPE'])) ? get_udev_info($device, $_ENV) : get_udev_info($device, NULL);
	$device	= realpath($device);
	if ($attrs['DEVTYPE'] == "partition") {
		$disk['serial_short']	= isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
		$disk['serial']			= "{$attrs['ID_MODEL']}_{$disk['serial_short']}";
		$disk['device']			= $device;

		/* Get partition number */
		preg_match_all("#(.*?)(\d+$)#", $device, $matches);
		$disk['part']			= $matches[2][0];
		$disk['disk']			= $matches[1][0];
		if (strpos($disk['disk'], "nvme") !== false) {
			$disk['disk']		= rtrim($disk['disk'], "p");
		}
		if (isset($attrs['ID_FS_LABEL'])){
			$disk['label'] = safe_name($attrs['ID_FS_LABEL_ENC']);
		} else {
			if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
				$disk['label'] = sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
			} else {
				$disk['label'] = safe_name($attrs['ID_SERIAL']);
			}
			$all_disks = array_unique(array_map(function($ar){return realpath($ar);},listDir("/dev/disk/by-id")));
			$disk['label'] = (count(preg_grep("%".$matches[1][0]."%i", $all_disks)) > 2) ? $disk['label']."-part".$matches[2][0] : $disk['label'];
		}
		$disk['fstype'] = safe_name($attrs['ID_FS_TYPE']);
		$disk['mountpoint'] = get_config($disk['serial'], "mountpoint.{$disk['part']}");
		if ( ($mountpoint === FALSE) || (! $disk['mountpoint']) ) { 
			$disk['mountpoint'] = $disk['target'] ? $disk['target'] : preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mountpoint'], $disk['label']));
		}
		$disk['luks']			= safe_name($disk['device']);
		if ($disk['fstype'] == "crypto_LUKS") {
			$disk['device']		= "/dev/mapper/".safe_name(basename($disk['mountpoint']));
		}
		$disk['mounted']		= is_mounted($disk['device']);
		$disk['disk_label']		= $disk['label'];
		$disk['pass_through']	= (! $disk['mounted']) ? is_pass_through($disk['serial']) : FALSE;
		$disk['target']			= str_replace("\\040", " ", trim(shell_exec("/bin/cat /proc/mounts 2>&1 | /bin/grep ".escapeshellarg($disk['device'])." | /bin/awk '{print $2}'")));
		$stats					= get_device_stats($disk['mountpoint'], $disk['mounted']);
		$disk['size']			= intval($stats[0])*1024;
		$disk['used']			= intval($stats[1])*1024;
		$disk['avail']			= intval($stats[2])*1024;
		$disk['owner']			= (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
		$disk['automount']		= is_automount($disk['serial'], strpos($attrs['DEVPATH'],"usb"));
		$disk['read_only']		= is_read_only($disk['serial']);
		$disk['shared']			= config_shared($disk['serial'], $disk['part'], strpos($attrs['DEVPATH'],"usb"));
		$disk['command']		= get_config($disk['serial'], "command.{$disk['part']}");
		$disk['user_command']	= get_config($disk['serial'], "user_command.{$disk['part']}");
		$disk['command_bg']		= get_config($disk['serial'], "command_bg.{$disk['part']}");
		$disk['prog_name']		= basename($disk['command'], ".sh");
		$disk['logfile']		= ($disk['prog_name']) ? $paths['device_log'].$disk['prog_name'].".log" : "";
	
		return $disk;
	}
}

/* Get the file system check command based on file system. */
function get_fsck_commands($fs, $dev, $type = "ro") {
	switch ($fs) {
		case 'vfat':
			$cmd = array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
			break;

		case 'ntfs':
			$cmd = array('ro'=>'/bin/ntfsfix -n %s','rw'=>'/bin/ntfsfix -b -d %s');
			break;

		case 'hfsplus';
			$cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
			break;

		case 'xfs':
			$cmd = array('ro'=>'/sbin/xfs_repair -n %s','rw'=>'/sbin/xfs_repair %s');
			break;

		case 'exfat':
			$cmd = array('ro'=>'/usr/sbin/fsck.exfat %s','rw'=>'/usr/sbin/fsck.exfat %s');
			break;

		case 'btrfs':
			$cmd = array('ro'=>'/sbin/btrfs scrub start -B -R -d -r %s','rw'=>'/sbin/btrfs scrub start -B -R -d %s');
			break;

		case 'ext4':
			$cmd = array('ro'=>'/sbin/fsck.ext4 -vn %s','rw'=>'/sbin/fsck.ext4 -v -f -p %s');
			break;

		case 'reiserfs':
			$cmd = array('ro'=>'/sbin/reiserfsck --check %s','rw'=>'/sbin/reiserfsck --fix-fixable %s');
			break;

		case 'crypto_LUKS':
			$cmd = array('ro'=>'/sbin/fsck -vy %s','rw'=>'/sbin/fsck %s');
			break;

		default:
			$cmd = array('ro'=>false,'rw'=>false);
			break;
	}

	return $cmd[$type] ? sprintf($cmd[$type], $dev) : "";
}

/* Check for a duplicate share name when changing the mount point. */
function check_for_duplicate_share($dev, $mountpoint, $fstype = "") {

	$rc = TRUE;

	/* Parse the samba config file. */
	$smb_file 	= "/etc/samba/smb-shares.conf";
	$smb_config	= parse_ini_file($smb_file, true);

	/* Get all shares from the smb configuration file. */
	$smb_shares = array_keys($smb_config);
	$smb_shares = array_flip($smb_shares);
	$smb_shares	= array_change_key_case($smb_shares, CASE_UPPER);
	$smb_shares = array_flip($smb_shares);

	$ud_shares = array();
	/* Get all disk mounts */
	foreach (get_all_disks_info() as $name => $info) {
		foreach ($info['partitions'] as $p) {
			$device = ($fstype == 'crypto_LUKS') ? $p['luks'] : $p['device'];
			if ($device != $dev) {
				$s = basename($p['mountpoint']);
				$ud_shares[] .= strtoupper($s);
			}
		}
	}

	/* Get the samba mounts */
	foreach (get_samba_mounts() as $name => $info) {
		if ($info['device'] != $dev) {
			$s = basename($info['mountpoint']);
			$ud_shares[] .= strtoupper($s);
		}
	}

	/* Get ISO File Mounts */
	foreach (get_iso_mounts() as $name => $info) {
		if ($info['device'] != $dev) {
			$s = basename($info['mountpoint']);
			$ud_shares[] .= strtoupper($s);
		}
	}

	/* Merge samba shares and ud shares. */
	$shares = array_merge($smb_shares, $ud_shares);

	/* See if the share name is already being used. */
	if (is_array($shares) && in_array(strtoupper($mountpoint), $shares)) {
		unassigned_log("Error: Cannot use that mount point! Share '{$mountpoint}' is already being used in the array or another unassigned device.");
		$rc = FALSE;
	}

	return $rc;
}

/* Change disk mount point and update the physical disk label. */
function change_mountpoint($serial, $partition, $dev, $fstype, $mountpoint) {
	global $paths, $var;

	$rc = TRUE;
	if ($mountpoint) {
		$rc = check_for_duplicate_share($dev, $mountpoint, $fstype);
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
						file_put_contents($luks_pass_file, $pass);
						unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
						$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
						exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
						unset($pass);
					}
					if ($o) {
						unassigned_log("Change disk label luksOpen error: ".$o);
						return FALSE;
					}

					/* Try xfs label change. */
					$mapper_dev = "/dev/mapper/$mapper";
					timed_exec(20, "/usr/sbin/xfs_admin -L ".escapeshellarg($mountpoint)." ".escapeshellarg($mapper_dev)." 2>/dev/null");

					/* Try btrfs label change. */
					timed_exec(20, "/sbin/btrfs filesystem label ".escapeshellarg($mapper_dev)." ".escapeshellarg($mountpoint)." 2>/dev/null");
					shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
					break;
			}
		}
	} else {
		unassigned_log("Error: Cannot change mount point! Mount point is blank.");
		$rc = FALSE;
	}

	return $rc;
}

/* Change samba mount point. */
function change_samba_mountpoint($dev, $mountpoint) {
	global $paths;

	$rc = TRUE;
	if ($mountpoint) {
		$rc = check_for_duplicate_share($dev, $mountpoint);
		if ($rc) {
			$mountpoint = $mountpoint;
			set_samba_config($dev, "mountpoint", $mountpoint);
		}
	} else {
		unassigned_log("Cannot change mount point! Mount point is blank.");
		$rc = FALSE;
	}

	return $rc;
}

/* Change iso file mount point. */
function change_iso_mountpoint($dev, $mountpoint) {
	global $paths;

	$rc = TRUE;
	if ($mountpoint) {
		$rc = check_for_duplicate_share($dev, $mountpoint);
		if ($rc) {
			$mountpoint = $paths['usb_mountpoint']."/".$mountpoint;
			set_iso_config($dev, "mountpoint", $mountpoint);
		} else {
		}
	} else {
		unassigned_log("Cannot change mount point! Mount point is blank.");
		$rc = FALSE;
	}

	return $rc;
}

/* Change the xfs disk UUID. */
function change_UUID($dev) {
	global $plugin;

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
	$device	.=(strpos($dev, "nvme") === false) ? "1" : "p1";
	if ($fs_type == "crypto_LUKS") {
		timed_exec(20, escapeshellcmd("plugins/{$plugin}/scripts/luks_uuid.sh ".escapeshellarg($device)));
		$mapper	= basename($dev);
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
			file_put_contents($luks_pass_file, $pass);
			unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
			$o		= shell_exec("/sbin/cryptsetup $cmd -d ".escapeshellarg($luks_pass_file)." 2>&1");
			exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
			unset($pass);
		}
		if ($o) {
			unassigned_log("luksOpen error: {$o}");
			return;
		}
		$mapper_dev = "/dev/mapper/".$mapper;
		$rc = timed_exec(10, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($mapper_dev));
		shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
	} else {
		$rc		= timed_exec(20, "/usr/sbin/xfs_admin -U generate ".escapeshellarg($device));
	}
	unassigned_log("Changing partition '{$device}' UUID. Result: {$rc}");
}

/* If the disk is not a SSD, set the spin down timer if allowed by settings. */
function setSleepTime($device) {
	global $paths;

	$sf	= $paths['dev_state'];

	/* If devs.ini does not exist, do the spindown using the disk timer. */
	if ((! is_file($sf)) && get_config("Config", "spin_down") == 'yes') {
		if (! is_disk_ssd($device)) {
			unassigned_log("Set spin down timer for device '{$device}'.");
			timed_exec(5, "/usr/sbin/hdparm -S180 $device 2>&1");
		} else {
			unassigned_log("Don't spin down device '{$device}'.");
			timed_exec(5, "/usr/sbin/hdparm -S0 $device 2>&1");
		}
	}
}

/* Setup a socket for nchan publish events. */
function curl_socket($socket, $url, $postdata=NULL) {
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
function publish($endpoint, $message) {
	curl_socket("/var/run/nginx.socket", "http://localhost/pub/$endpoint?buffer_length=1", $message);
}
?>
