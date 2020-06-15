<?php
/*
 * Language translations wrapper.
 */

$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$translations = is_file("$docroot/plugins/dynamix/include/Translations.php");

function tr($string,$ret=false) {
	if ( function_exists("_") )
		$string = str_replace('"',"&#34;",str_replace("'","&#39;",_($string)));
	if ( $ret )
		return $string;
	else
		echo $string;
}
?>
