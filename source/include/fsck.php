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
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$translations = file_exists("$docroot/webGui/include/Translations.php");

if ($translations) {
	/* add translations */
	$_SERVER['REQUEST_URI'] = 'unassigneddevices';
	require_once "$docroot/webGui/include/Translations.php";
} else {
	/* legacy support (without javascript) */
	$noscript = true;
	require_once "$docroot/plugins/$plugin/include/Legacy.php";
}

readfile('logging.htm');

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

if ( isset($_GET['device']) && isset($_GET['fs']) ) {
	$device	= trim(urldecode($_GET['device']));
	$fs		= trim(urldecode($_GET['fs']));
	$check_type	= isset($_GET['check_type']) ? trim(urldecode($_GET['check_type'])) : 'ro';
	$luks	= trim(urldecode($_GET['luks']));
	$serial	= trim(urldecode($_GET['serial']));
	write_log("FS: $fs<br /><br />");
	if ($fs == "crypto_LUKS") {
		$mapper	= basename($device);
		$cmd	= "luksOpen {$luks} {$mapper}";
		$pass	= decrypt_data(get_config($serial, "pass"));
		if ($pass == "") {
			if (file_exists($var['luksKeyfile'])) {
				$cmd	= $cmd." -d {$var['luksKeyfile']}";
				$o		= shell_exec("/sbin/cryptsetup {$cmd} 2>&1");
			} else {
				$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup={$cmd}' 2>&1");
			}
		} else {
			$luks_pass_file = "{$paths['luks_pass']}_".basename($luks);
			file_put_contents($luks_pass_file, $pass);
			$cmd	= $cmd." -d $luks_pass_file";
			$o		= shell_exec("/sbin/cryptsetup {$cmd} 2>&1");
			@unlink("$luks_pass_file");
		}
		if ($o != "") {
			echo("luksOpen error: ".$o."<br />");
			return;
		}
	}
	$file_system = $fs;
	if ($fs == "crypto_LUKS") {
		$o = shell_exec("/sbin/fsck -vy {$device} 2>&1");
		if (strpos($o, 'XFS') !== false) {
			$file_system = "xfs";
		} elseif (strpos($o, 'REISERFS') !== false) {
			$file_system = "resierfs";
		} elseif (strpos($o, 'BTRFS') !== false) {
			$file_system = "btrfs";
		}
	}
	$command = get_fsck_commands($file_system, $device, $check_type)." 2>&1";
	write_log($command."<br /><br />");
	$proc = popen($command, 'r');
	while (! feof($proc)) {
		write_log(fgets($proc));
	}
	if ($fs == "crypto_LUKS") {
		shell_exec("/sbin/cryptsetup luksClose ".$mapper);
	}
}
write_log("<center><button type='button' onclick='document.location=\"/plugins/{$plugin}/include/fsck.php?device={$device}&fs={$fs}&luks={$luks}&serial={$serial}&check_type=rw&type="._('Done')."\"'>"._('Run with CORRECT flag')."</button></center>");
?>
