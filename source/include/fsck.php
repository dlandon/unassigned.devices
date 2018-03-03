<?php
/* Copyright 2015, Guilherme Jardim
 * Copyright 2016-2018, Dan Landon
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
	echo "FS: $fs<br /><br />";
	$command = get_fsck_commands($fs, $device, $type)." 2>&1";
	write_log($command."<br /><br />");
	$proc = popen($command, 'r');
	while (!feof($proc)) {
		write_log(fgets($proc));
	}
}
write_log("<center><button type='button' onclick='document.location=\"/plugins/{$plugin}/include/fsck.php?device={$device}&fs={$fs}&type=rw\"'> Run with CORRECT flag</button></center>");
?>
