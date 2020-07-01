<?php
/* Copyright 2016-2020, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
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

if ( isset($_GET['device']) && isset($_GET['owner']) ) {
	$device = trim(urldecode($_GET['device']));
	$info = get_partition_info($device, true);
	$owner = trim(urldecode($_GET['owner']));
	$command = execute_script($info, 'ADD', TRUE);
	if ($command != "") {
		$command = $command." 2>&1";
		@touch($GLOBALS['paths']['reload']);
		putenv("OWNER={$owner}");
		write_log($command."<br><br>");
		$proc = popen($command, 'r');
		while (! feof($proc)) {
			write_log(fgets($proc));
		}
	} elseif ($command !== FALSE) {
		echo _("No script file to execute")."!";
	} else {
		echo _("Script is already running")."!";
	}
}
?>
