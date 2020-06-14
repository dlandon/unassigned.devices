<?php
/*
 * Language translations wrapper.
 */

$translations =   version_compare($unRaidSettings['version'],"6.9.0-beta1",">");
if ( $translations ) {
	require_once "$docroot/plugins/dynamix/include/Translations.php";
	$translationFile = "$docroot/languages/{$_SESSION['locale']}/".basename(strtolower($_SERVER['REQUEST_URI'])).".txt";
	$genericFile = "$docroot/languages/{$_SESSION['locale']}/translations.txt";
	$pluginTranslations = @parse_lang_file($translationFile);
	$genericTranslations = @parse_lang_file($genericFile);
	$language = array_merge(is_array($genericTranslations) ? $genericTranslations : [],is_array($pluginTranslations) ? $pluginTranslations : [] );
	if ( empty($language) )
		$translations = false;
}

function tr($string,$ret=false) {
	global $translations;

	if ( $translations)
		$string = _($string);
	if ( $ret )
		return $string;
	else
		echo $string;
}
?>
