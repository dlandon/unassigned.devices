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

$plugin = "unassigned.devices";

require_once("plugins/{$plugin}/include/lib.php");

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

/* add translations */
$_SERVER['REQUEST_URI'] = 'fsck';
require_once "$docroot/webGui/include/Translations.php";

readfile('logging.htm');

/* Write text to the pop up dialog. */
function write_log($string) {
	global $var;

	if (empty($string)) {
		return;
	}
	$string = str_replace("\n", "<br />", $string);
	$string = str_replace('"', "\\\"", trim($string));
	echo "<script>addLog(\"{$string}\");</script>";
	@flush();
}

/* Main entry point. */
if ( isset($_GET['device']) && isset($_GET['fs']) ) {
	$device		= $_GET['device'];
	$fs			= $_GET['fs'];
	$check_type	= isset($_GET['check_type']) ? $_GET['check_type'] : 'ro';
	$luks		= $_GET['luks'];
	$serial		= $_GET['serial'];
	$mountpoint	= $_GET['mountpoint'];
	$mounted	= is_mounted($mountpoint);
	$rc			= true;

	/* Display the file system. */
	write_log("FS: $fs<br /><br />");

	/* If the disk format is encrypted, we need to open the luks device. */
	if (($fs == "crypto_LUKS") && (! $mounted)) {
		$mapper	= basename($device);
		$cmd	= "luksOpen {$luks} ".escapeshellarg($mapper);
		$pass	= decrypt_data(get_config($serial, "pass"));
		write_log("Opening crypto_LUKS device '$luks'...<br />");
		if (! $pass) {
			if (file_exists($var['luksKeyfile'])) {
				unassigned_log("Using luksKeyfile to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/sbin/cryptsetup ".$cmd." -d ".escapeshellarg($var['luksKeyfile'])." 2>&1");
			} else {
				unassigned_log("Using Unraid api to open the 'crypto_LUKS' device.");
				$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup=$cmd' 2>&1");
			}
			$luks_pass_file	= "";
		} else {
			$luks_pass_file = "{$paths['luks_pass']}_".basename($luks);
			file_put_contents($luks_pass_file, $pass);
			unassigned_log("Using disk password to open the 'crypto_LUKS' device.");
			$o		= shell_exec("/sbin/cryptsetup ".$cmd." -d ".escapeshellarg($luks_pass_file)." 2>&1");
		}
		if ($o) {
			write_log("luksOpen error: ".$o."<br />");
			$rc = false;
		}

		/* Remove the password/passphrase. */
		unset($pass);
		if (($luks_pass_file) && (file_exists($luks_pass_file))) {
			exec("/bin/shred -u ".escapeshellarg($luks_pass_file));
		}
	}

	/* If there was no error from the luks open command or the disk is not encrypted, go ahead with the file check. */
	$file_system = ($fs == "crypto_LUKS") ? luks_fs_type($device) : $fs;
	if ($rc) {
		if ($file_system) {
			/* If the file system is btrfs, we will do a scrub. */
			if (($file_system == "btrfs") || ($file_system == "zfs")) {
				write_log("Executing file system scrub:&nbsp;");
			} else {
				write_log("Executing file system check:&nbsp;");
			}

			/* Get the file system check command based on the file system. */
			if ($file_system == "zfs") {
				if (! $mounted) {
					$pool_name	= (new MiscUD)->zfs_pool_name($device);
					exec("/usr/sbin/zpool import -N ".escapeshellarg($pool_name)." 2>/dev/null");
					sleep(1);
				} else {
					$pool_name	= (new MiscUD)->zfs_pool_name($mountpoint, $mounted);
				}
				$command = get_fsck_commands($file_system, $pool_name, $check_type)." 2>&1";
			} else {
				$command = get_fsck_commands($file_system, $device, $check_type)." 2>&1";
			}
			write_log($command."<br />");

			/* Execute the fsck command and pipe it to $proc. */
			$proc = popen($command, 'r');
			while (! feof($proc)) {
				write_log(fgets($proc));
			}

			/* Show the results of the zfs scrub. */
			if ($file_system == "zfs") {
				$command = "/usr/sbin/zpool status ".escapeshellarg($pool_name);
				/* Execute the status command and pipe it to $proc. */
				$proc = popen($command, 'r');
				while (! feof($proc)) {
					write_log(fgets($proc));
				}
				if (! $mounted) {
					exec("/usr/sbin/zpool export ".escapeshellarg($pool_name)." 2>/dev/null");
				}
			}

			/* Close $proc and get the process error code. */
			$rc_check = pclose($proc);
		} else {
			if ($fs == "crypto_LUKS") {
				write_log("Cannot determine file system on an encrypted disk!<br />");
			}
			$rc_check = 0;
		}
	}

	/* Close the device if it is encrypted. */
	if (($fs == "crypto_LUKS") && (! $mounted)) {
		write_log("Closing crypto_LUKS device '$luks'...<br />");
		$o = shell_exec("/sbin/cryptsetup luksClose ".escapeshellarg($mapper));
		if ($o) {
			write_log("luksClose error: ".$o."<br />");
		}
	}
}

/* Btrfs file systems have to be mounted to scrub them. */
if (($file_system == "btrfs") && (! $mounted)) {
	write_log("<br />"._('A btrfs file system has to be mounted to be scrubbed')."!<br />");
} else {
	/* Check the fsck return code and process the return code. */
	if ($rc_check != 0) {
		if ((($file_system == "xfs") && ($rc_check == 1)) || ($file_system != "xfs")) {
			write_log("<br />"._('File system corruption detected')."!<br />");
			write_log("<center><button type='button' onclick='document.location=\"/plugins/{$plugin}/include/fsck.php?device={$device}&fs={$fs}&luks={$luks}&serial={$serial}&check_type=rw&type="._('Done')."\"'>"._('Run with Correct flag')."</button></center>");
		} else if (($file_system == "xfs") && ($rc_check == 2)) {
			write_log("<br />"._('Dirty log detected')."!<br />");
			write_log("<center><button type='button' onclick='document.location=\"/plugins/{$plugin}/include/fsck.php?device={$device}&fs={$fs}&luks={$luks}&serial={$serial}&check_type=log&type="._('Done')."\"'>"._('Force Log Zeroing')."</button></center>");
			write_log("<br />"._('Note: While there is some risk, if it is not possible to first mount the filesystem to clear the log, zeroing it is the only option to try and repair the filesystem, and in most cases it results in little or no data loss').".&nbsp;&nbsp;");
		} else if (($file_system == "xfs") && ($rc_check == 4)){
			write_log("<br />"._('File system corruption fixed')."!<br />");
		} 
	} else if ($file_system == "xfs") {
		write_log("<br />"._('No file system corruption detected')."!<br />");
	}
}
?>
