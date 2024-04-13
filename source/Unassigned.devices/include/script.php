<?php
/* Copyright 2016-2024, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$plugin = "unassigned.devices";
require_once("plugins/".$plugin."/include/lib.php");

/* add translations */
$_SERVER['REQUEST_URI'] = 'unassigneddevices';
require_once("$docroot/webGui/include/Translations.php");

readfile('logging.htm');

function write_log($string) {
	if (! empty($string)) {
		$string = str_replace("\n", "<br \>", $string);
		$string = str_replace('"', "\\\"", trim($string));
		echo "<script>addLog(\"{$string}\");</script>";
		@flush();
	}
}

if ( isset($_GET['device']) && isset($_GET['type']) ) {
	$device = trim(urldecode($_GET['device']));
	$info = get_partition_info($device, true);
	$command = execute_script($info, 'ADD', true);
	if ($command != "") {
		$command = $command." 2>&1";
		putenv("OWNER=udev");
		write_log(_("Executing").": ".basename($command)."<br \><br \>");
		$proc = popen($command, 'r');
		while (! feof($proc)) {
			write_log(fgets($proc));
		}
	} else if ($command !== false) {
		echo _("No script file to execute")."!";
	} else {
		echo _("Script is already running")."!";
	}
}
?>
