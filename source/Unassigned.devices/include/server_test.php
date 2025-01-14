<?php
/* 
 *  Execute get_ud_stats Command to test remote server
 */

/* Define our plugin name. */
if (!defined('DOCROOT')) {
	define('DOCROOT', $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
}

$server = strtoupper(htmlspecialchars(urldecode($_POST['server'])));

/* Execute the get_ud_stats command to check SMB and NFS on a remote server. */
$command = DOCROOT."/plugins/unassigned.devices/scripts/get_ud_stats remote_test ".$server;

echo shell_exec($command);
?>
