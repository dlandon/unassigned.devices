<?php
/*
 * Language translations wrapper.
 */

$translations =   version_compare($unRaidSettings['version'],"6.9.0-beta1",">");
if ( $translations ) {
	$translationFile = "$docroot/languages/{$_SESSION['locale']}/".basename(strtolower($_SERVER['REQUEST_URI'])).".txt";
	$genericFile     = "$docroot/languages/{$_SESSION['locale']}/translations.txt";
	$pluginTranslations = @parse_language($translationFile);
	$genericTranslations = @parse_language($genericFile);
	$language = array_merge(is_array($genericTranslations) ? $genericTranslations : [],is_array($pluginTranslations) ? $pluginTranslations : [] );
	if ( empty($language) ) 
		$translations = false;
}

function parse_language($file) {
	return array_filter(parse_ini_string(preg_replace(['/"/m','/^(null|yes|no|true|false|on|off|none)=/mi','/^([^>].*)=([^"\'`].*)$/m','/^:((help|plug)\d*)$/m','/^:end$/m'],['\'','$1.=','$1="$2"',"_$1_=\"",'"'],str_replace("=\n","=''\n",file_get_contents($file)))),'strlen');
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
