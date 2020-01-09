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
readfile('logging.htm');

function write_log($string) {
	global $var;

	if (empty($string)) {
		return;
	}
	$string = str_replace("\n", "<br>", $string);
	$string = str_replace('"', "\\\"", trim($string));
	echo "<script>addLog(\"{$string}\");</script>";
	@flush();
}

if ( isset($_GET['device']) && isset($_GET['fs']) ) {
	$device	= trim(urldecode($_GET['device']));
	$fs		= trim(urldecode($_GET['fs']));
	$type	= isset($_GET['type']) ? trim(urldecode($_GET['type'])) : 'ro';
	$mapper	= trim(urldecode($_GET['mapper']));
	echo "FS: $fs<br /><br />";
	if ($fs == "crypto_LUKS") {
		$luks	= basename($device);
		$cmd	= "luksOpen {$mapper} {$luks}";
		if (file_exists($var['luksKeyfile'])) {
			$cmd	= $cmd." -d {$var['luksKeyfile']}";
			$o		= shell_exec("/sbin/cryptsetup {$cmd} 2>&1");
		} else {
			$o		= shell_exec("/usr/local/sbin/emcmd 'cmdCryptsetup={$cmd}' 2>&1");
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
	$command = get_fsck_commands($file_system, $device, $type)." 2>&1";
	write_log($command."<br /><br />");
	$proc = popen($command, 'r');
	while (!feof($proc)) {
		write_log(fgets($proc));
	}
	if ($fs == "crypto_LUKS") {
		shell_exec("/sbin/cryptsetup luksClose ".basename($luks));
	}
}
write_log("<center><button type='button' onclick='document.location=\"/plugins/{$plugin}/include/fsck.php?device={$device}&fs={$fs}&type=rw&mapper={$mapper}\"'>Run with CORRECT flag</button></center>");
?>
