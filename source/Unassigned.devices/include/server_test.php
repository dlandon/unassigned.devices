<?php
/* 
 *  Execute get_ud_stats Command TO TEST REMOTE SERVER
 */


$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

$server = strtoupper($_POST['server']);

/* Execute the get_ud_stats command to check SMB and NFS on a remote server. */
$command = $docroot."/plugins/unassigned.devices/scripts/get_ud_stats remote_test ".$server;

echo shell_exec($command);
?>
