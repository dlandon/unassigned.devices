<?php
/* Copyright 2015-2020, Guilherme Jardim
 * Copyright 2022-2025, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

/* Define our plugin name. */
define('UNASSIGNED_PRECLEAR_PLUGIN', 'unassigned.devices.preclear');

define('PRECLEAR_PLUGIN_PATH', json_encode(UNASSIGNED_PRECLEAR_PLUGIN ?? 'unassigned.devices.preclear'));

/* Define the docroot path. */
if (!defined('DOCROOT')) {
	define('DOCROOT', $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
}

/* Load the Unraid ColorCoding, Wrappers, and Helpers files. */
require_once(DOCROOT."/webGui/include/ColorCoding.php");
require_once(DOCROOT."/webGui/include/Wrappers.php");
require_once(DOCROOT."/webGui/include/Helpers.php");

/* add translations */
require_once(DOCROOT."/webGui/include/Translations.php");

/* Verbose logging. */
define('VERBOSE', false);

if (!isset($var)) {
	if (!is_file(DOCROOT."/state/var.ini")) {
		shell_exec("wget -qO /dev/null localhost:$(lsof -nPc emhttp | grep -Po 'TCP[^\d]*\K\d+')");
	}
	$var = @parse_ini_file(DOCROOT."/state/var.ini");
}

$state_file			= "/var/state/unassigned.devices.preclear/state.ini";
$log_file	 		= "/var/log/preclear/preclear.log";
$diskinfo	 		= "/var/local/emhttp/plugins/diskinfo/diskinfo.json";
$hotplug_event		= "/tmp/unassigned.devices/hotplug_event";
$preclear_status	= "/tmp/preclear/preclear_stat_";
$tmp_preclear		= "/tmp/.preclear/";
$preclear_reports	= "/boot/preclear_reports/";
$unsupported		= "/var/state/".UNASSIGNED_PRECLEAR_PLUGIN."/unsupported";

/* Get the version of Unraid we are running. */
$version = @parse_ini_file("/etc/unraid-version");

function preclear_log($msg, $type = "NOTICE")
{
	if ( ($type == "DEBUG") && (! VERBOSE) ) {
		return NULL;
	}
	$msg = date("M j H:i:s")." ".print_r($msg,true)."\n";
	file_put_contents($GLOBALS["log_file"], $msg, FILE_APPEND);
}

class TMUX
{
	/* Is tmux executable. */
	public static function isExecutable()
	{
		return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? true : false) : false;
	}

	/* Check that $name is a current session. */
	public static function hasSession($name)
	{
		exec("/usr/bin/tmux ls 2>/dev/null|cut -d: -f1", $screens);
		return in_array($name, $screens);
	}

	/* Create a new tmux session. */
	public static function NewSession($name)
	{
		if (! TMUX::hasSession($name)) {
			exec("/usr/bin/tmux new-session -d -s ".escapeshellarg($name)." 2>/dev/null");
		}
	}

	/* Is this $name a current session? */
	public static function getSession($name)
	{
		return (TMUX::hasSession($name)) ? shell_exec("/usr/bin/tmux capture-pane -t ".escapeshellarg($name)." 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : "";
	}

	/* Send a tmux command. */
	public static function sendCommand($name, $cmd)
	{
		exec("/usr/bin/tmux send -t ".escapeshellarg($name)." ".escapeshellarg($cmd)." ENTER 2>/dev/null");
	}

	/* Send tmux keys. */
	public static function sendKeys($name, $keys)
	{
		exec("/usr/bin/tmux send-keys -t ".escapeshellarg($name)." ".escapeshellarg($keys)." ENTER 2>/dev/null");
	}

	/* Kill tmux session. */
	public static function killSession($name)
	{
		if (TMUX::hasSession($name)) {
			exec("/usr/bin/tmux kill-session -t ".escapeshellarg($name)." >/dev/null 2>&1");
		}
	}
}

class Misc
{
	/* Save array to json file. */
	public static function save_json($file, $content)
	{
		file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT ));
	}

	/* Get json file to array. */
	public static function get_json($file)
	{
		$out = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
		return is_array($out) ? $out : []; 
	}

	/* Is $disk a valid device. */
	public static function disk_device($disk)
	{
		$name = Misc::disk_name($disk);
		return (file_exists($disk)) ? $disk : "/dev/{$name}";
	}

	/* Is $disk a vaid disk name. */
	public static function disk_name($disk)
	{
		return (file_exists($disk)) ? basename($disk) : $disk;
	}

	/* Get the first element of an array. */
	public static function array_first_element($arr)
	{
		return (is_array($arr) && count($arr)) ? $arr[0] : $arr;
	}
}

class Preclear
{
	public $preclear_plugin = UNASSIGNED_PRECLEAR_PLUGIN;
	public $allDisks, $log_file;

	function __construct()
	{
		global $diskinfo;

		$this->allDisks = (new Misc)->get_json($diskinfo);
		$this->log_file = $GLOBALS['log_file'];
	}

	public function diskSerial($disk)
	{
		$disk	= Misc::disk_name($disk);
		return (count($this->allDisks) && (isset($this->allDisks[$disk]["SERIAL_SHORT"]))) ? $this->allDisks[$disk]["SERIAL_SHORT"] : null;
	}
	
	public function serialDisk($serial)
	{
		$disks = array_values(array_filter($this->allDisks, function($v) use ($serial) {return $v["SERIAL_SHORT"] == $serial;}));
		return count($disks) ? $disks[0]['DEVICE'] : NULL;
	}

	public function Authors()
	{
		$authors			= ["gfjardim" => "Enhanced", "joel" => "Legacy", "docker" => "Docker"];
		$scripts			= $this->scriptFiles();

		foreach ($authors as $key => $name) {
			$capabilities = array_key_exists($key, $scripts) ? $this->scriptCapabilities($scripts[$key]) : [];

			if ( array_key_exists("version", $capabilities) && $capabilities["version"] ) {
				$authors[$key] = "$name - ".$capabilities['version'];
			}
		}

		return $authors;
	}

	public function Author($author)
	{
		return $this->Authors()[$author];
	}

	public function scriptCapabilities($file)
	{
		$o["version"]		= (is_executable($file)) ? trim(shell_exec(escapeshellcmd($file)." -v 2>/dev/null | cut -d: -f2")) : NULL;
		$o["file"]			= $file;
		$o["fast_postread"]	= $o["version"] ? (strpos(file_get_contents($file), "fast_postread") ? true : false ) : false;
		$o["notifications"]	= $o["version"] ? (strpos(file_get_contents($file), "notify_channels") ? true : false ) : false;
		$o["noprompt"]		= $o["version"] ? (strpos(file_get_contents($file), "noprompt")	? true : false ) : false;
		return $o;
	}

	public function scriptFiles()
	{
		$scripts = ["gfjardim"	=> DOCROOT."/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/scripts/preclear_disk.sh",
					"joel"		=> "/usr/local/sbin/preclear_disk_ori.sh",
					"docker"	=> DOCROOT."/plugins/".UNASSIGNED_PRECLEAR_PLUGIN."/scripts/preclear_disk_docker.sh"];

		foreach ($scripts as $author => $file) {
			if (! is_executable($file)) {
				unset($scripts[$author]);
			} else if ($author == "docker") {
				$image = shell_exec("/usr/bin/docker ps | grep binhex-preclear");
				if (! $image) {
					unset($scripts[$author]);
				}
			}
		}

		return $scripts;
	}

	public function Script()
	{
		global $display, $var;
		$trim_var					= array_intersect_key( $var, array_flip( ["csrf_token"] ));
		$trim_display				= array_intersect_key( $display, array_flip( ["unit","number"] ));
		echo "var preclear_display	= ".json_encode($trim_display).";\n";
		echo "var preclear_vars		= ".json_encode($trim_var).";\n";
		echo "var preclear_plugin	= '".$this->preclear_plugin."';\n";
		echo "var preclear_authors	= ".json_encode($this->Authors()).";\n";
		echo "var preclear_scope	= 'gfjardim';\n";
		echo "var preclear_scripts	= ".json_encode($this->scriptFiles()).";\n";
		printf("var zip = '%s-%s-%s.zip';\n", str_replace(' ','_',strtolower($var['NAME'])), $this->preclear_plugin, date('Ymd-Hi') );

		echo "var preclear_footer_icon = \"<i class='icon-preclear'></i>\"";
		echo "</script>\n";
		echo "<script src='";autov("/plugins/$this->preclear_plugin/assets/javascript.js"); echo "'></script>\n";
		echo "<script>\n";
	}

	public function Link($disk, $type)
	{
		$serial	= $this->diskSerial($disk);
		$icon	= "<a title='"._('Start Preclear')."' class='exec tooltip' onclick='getResumablePreclear(\"{$serial}\")'><i class='icon-preclear'></i></a>";
		$text	= "<a title='"._('Start Preclear')."' class='exec' onclick='getResumablePreclear(\"{$serial}\")'>"._('Start Preclear')."</a>";
		return ($type == "text") ? $text : $icon;
	}

	public function isRunning($disk)
	{
		$serial = $this->diskSerial($disk);
		if ( TMUX::hasSession("preclear_disk_{$serial}") )
		{
			return true;
		} else {
			$file = $GLOBALS['preclear_status'].$disk;
			if (is_file($file)) {
				$stat = explode("|", file_get_contents($file));
				return count($stat) == 4 ? file_exists( "/proc/".trim($stat[3])) : true;
			} else {
				return false;
			}
		}
	}

	public function Status($disk, $serial)
	{
		$disk		= Misc::disk_name($disk);
		$status		= "";

		$file		= $GLOBALS['preclear_status'].$disk;
		$serial		= $this->diskSerial($disk) ?? "";
		$session 	= TMUX::hasSession("preclear_disk_{$serial}");

		/* Pick up the docker stat file. */
		$docker = shell_exec("/usr/bin/docker ps | grep binhex-preclear");
		$docker_running	= exec("/usr/bin/ps -ef | grep preclear_binhex.sh  | /bin/grep -v 'grep'");
		if ($docker && $session && $docker_running) {
			shell_exec("/usr/bin/docker cp binhex-preclear:/tmp/preclear_stat_{$disk} /tmp/preclear/ 2>/dev/null");
		}
		$paused		= file_exists($GLOBALS['tmp_preclear'].$disk."/pause") ? "<a class='exec tooltip' style='margin-left:10px;color:#00BE37;' onclick='resumePreclear(\"{$disk}\")' title='"._('Resume')."'><i class='fa fa-play'></i></a>" : "";
		$rm			= "<a id='preclear_rm_{$disk}' class='exec tooltip' style='color:#CC0000;font-weight:bold;margin-left:6px;' title='%s' onclick='stopPreclear(\"{$serial}\",\"%s\");'>";
		$rm			.= "<i class='fa fa-times hdd'></i></a>";
		$preview	= "<a id='preclear_open_{$disk}' class='exec tooltip' style='margin-left:10px;color:#1E90FF;' onclick='openPreclear(\"{$serial}\");' title='"._('Preview Progress Report')."'><i class='fa fa-eye hdd'></i></a>";

		if (is_file($file)) {
			$stat = explode("|", file_get_contents($file));

			$stat[2] = strpos(TMUX::getSession("preclear_disk_{$serial}"), "Yes to continue") !== false ? 
						"<span class='yellow-orb'><i class='fa fa-bell' /> Please answer Yes to continue</span>" : $stat[2];

			switch ( count($stat) ) {
				case 4:
					$running	= file_exists( "/proc/".trim($stat[3]) );
					$log		= "<a class='exec tooltip' title='"._('Preclear Disk Log')."' style='margin:0px -3px 0px 8px;color:#1E90FF;' onclick='openPreclearLog(\"preclear_disk_{$serial}_".trim($stat[3])."\");'><i class='fa fa-align-left'></i></a>";

					if ($running) {
						if (file_exists($GLOBALS['tmp_preclear'].$disk."/pause") || file_exists($GLOBALS['tmp_preclear'].$disk."/queued")) {
							$status .= "<span style='color:#ccb800;margin-right:8px;'>{$stat[2]}</span>";
						} else {
							$status .= "<span style='color:#00BE37;margin-right:8px;'>{$stat[2]}</span>";
						}
					} else {
						if (preg_match("#failed|FAIL#", $stat[2]) || preg_match("#Error|error#", $stat[2])) {
							$status .= "<span style='color:#CC0000;margin-right:8px;'>{$stat[2]}</span>";
						} else {
							$status .= "<span style='margin-right:8px;'>{$stat[2]}</span>";
						}
					}
					$preview = "{$paused}{$log}{$preview}";
					break;

				default:
					$running	= true;
					$log		= "";
					$status		.= "<span>{$stat[2]}</span>";
					break;
			}

			if ($session && $running) {
				$status .= $preview;
				$status .= sprintf($rm, _("Stop Preclear"), "ask");
			} else if ($session) {
				$status .= $preview;
				$status .= sprintf($rm, _("Remove Report"), "");
			} else if ( $file ) {
				$status .= sprintf($rm, _("Clear Stats"), "");
			}
		} else if ($this->isRunning($disk)) {
			$status .= $preview;
			$status .= sprintf($rm, _("Clear Stats"), "");
		} else {
			$status .= sprintf($rm, _("Clear Stats"), "");
		}

		return str_replace("^n", "<br>" , $status);
	}

	public function html()
	{
		$cycles	= "";
		for ($i=1; $i <= 3; $i++) {
			$cycles .= "<option value='$i'>$i</option>";
		}

		$size	= "";
		foreach (range(0,8) as $i) {
			$x=pow(2,$i);
			$size .= "<option value='65536 -b ".($x*16)."'>{$x}M</option>";
		}

		$cycles2	= "";
		for ($i=1; $i <= 3; $i++) {
			$cycles2 .= "<option value='$i'>$i</option>";
		}

		$size2	= "";
		foreach (range(5,11) as $i) {
			$x=pow(2,$i);
			$size2 .= "<option value='".($x*16*65536)."'>{$x}M</option>";
		}

		$queue = "";
		for ($i=0; $i <= 10; $i++) {
			$queue .= ($i == 0) ? "<option value='$i'>disable</option>" : "<option value='$i'>$i</option>";
		}
		$scripts = $this->scriptFiles();
		$capabilities = array_key_exists("joel", $scripts) ? $this->scriptCapabilities($scripts["joel"]) : [];
		$capabilities = array_key_exists("docker", $scripts) ? $this->scriptCapabilities($scripts["docker"]) : [];
?>
<style>
	.dl-dialog{margin-bottom: 8px; line-height: 16px; text-align: left;}
	.sweet-alert input[type="checkbox"] {display: initial; width: auto; height: auto; margin: auto 3px auto auto; vertical-align: top;}
</style>
<div id="preclear-dialog" style="display:none;" title=""></div>
<div id="dialog-header-defaults" style="display:none;">
	<dl class="dl-dialog"><dt><?=_('Device Model')?>:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{model}</span></dd></dl>
	<dl class="dl-dialog"><dt><?=_('Serial Number')?>:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{serial_short}</span></dd></dl>
	<dl class="dl-dialog"><dt><?=_('Firmware')?>:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{firmware}</span></dd></dl>
	<dl class="dl-dialog"><dt><?=_('Disk Size')?>:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{size_h}</span></dd></dl>
	<dl class="dl-dialog"><dt><?=_('Device')?>:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{device}</span></dd></dl>
</div>
<div id="dialog-multiple-defaults" style="display:none;">
	<dl class="dl-dialog">
		<dt><?= _('Select Disks') ?>: </dt>
		<dd style="margin-bottom:0px;">
			<select id="multiple_preclear" name="disks" multiple class="chosen" data-placeholder="<?= _('Preclear Disks') ?>">
				{0}
			</select>
		</dd>
	</dl>
</div>

<div id="joel-start-defaults" style="display:none;">
	<dl class="dl-dialog">
		<dt><?=_('Operation')?>: </dt>
		<dd>
			<select name="op" onchange="toggleSettings(this);">
				<option value='0'><?=_('Clear Disk')?></option>
				<option value='-V'><?=_('Post-read Verify')?></option>
				<option value='-t'><?=_('Verify Signature')?></option>
				<option value='-z'><?=_('Clear Signature')?></option>
			</select>
		</dd>
		<div class='write_options'>
			<dt><?=_('Cycles')?>: </dt>
			<dd>
				<select name="-c"><?=$cycles;?></select>
			</dd>
		</div>
		<?if ( array_key_exists("notifications", $capabilities) && $capabilities["notifications"] ):?>
		<div class="notify_options">
			<dt><?=_('Notifications')?>: </dt>
			<dd style="font-weight: normal;">
				<input type="checkbox" name="preclear_notify" onchange="toggleFrequency(this, '-M');">Per Unraid Settings
			</dd>
			<dt>&nbsp;</dt>
			<dd>
			<dd>
				<select name="-M" disabled>
					<option value="1" selected>On preclear end</option>
					<option value="2">On every cycle end</option>
					<option value="3">On every cycle and step end</option>
					<option value="4">On every 25% of progress</option>
				</select>
				</dd>
		</div>
		<?endif;?>
		<div class='read_options'>
			<dt><?=_('Read size')?>: </dt>
			<dd>
				<select name="-r">
					<option value="0">Default</option><?=$size;?>
				</select>
			</dd>
		</div>
		<div class='write_options'>
			<dt><?=_('Write size')?>: </dt>
			<dd>
				<select name="-w">
					<option value="0">Default</option><?=$size;?>
				</select>
			</dd>
			<dt><?=_('Skip Pre-read')?>: </dt>
			<dd>
				<input type="checkbox" name="-W" class="switch" >
			</dd>
		</div>
		<?if ( array_key_exists("fast_postread", $capabilities) && $capabilities["fast_postread"] ):?>
		<div class='postread_options'>
			<dt><?=_('Fast Post-read')?>: </dt>
			<dd>
				<input type="checkbox" name="-f" class="switch" >
			</dd>
		</div>
		<?endif;?>
		<div class='inline_help'>
			<p><?=_('Enable Testing for debugging')?></p>
			<dt><?=_('Testing')?>:</dt>
			<dd>
				<input type="checkbox" name="-s" class="switch" >
			</dd>
		</div>
	</dl>
</div>

<div id="docker-start-defaults" style="display:none;">
	<dl class="dl-dialog">
		<dt><?=_('Operation')?>: </dt>
		<dd>
			<select name="op" onchange="toggleSettings(this);">
				<option value='0'><?=_('Clear Disk')?></option>
				<option value='-V'><?=_('Post-read Verify')?></option>
				<option value='-t'><?=_('Verify Signature')?></option>
				<option value='-z'><?=_('Clear Signature')?></option>
			</select>
		</dd>
		<div class='write_options'>
			<dt><?=_('Cycles')?>: </dt>
			<dd>
				<select name="-c"><?=$cycles;?></select>
			</dd>
		</div>
		<?if ( array_key_exists("notifications", $capabilities) && $capabilities["notifications"] ):?>
		<div class="notify_options">
			<dt><?=_('Notifications')?>: </dt>
			<dd>
				<select name="-M">
					<option value="0"><?=_('Disabled')?></option>
					<option value="1"><?=_('On preclear end')?></option>
					<option value="2"><?=_('On every cycle end')?></option>
					<option value="3"><?=_('On every cycle and step end')?></option>
					<option value="4"><?=_('On every 25% of progress')?></option>
				</select>
				</dd>
		</div>
		<?endif;?>
		<div class='read_options'>
			<dt><?=_('Read size')?>: </dt>
			<dd>
				<select name="-r">
					<option value="0"><?=_('Default')?></option><?=$size;?>
				</select>
			</dd>
		</div>
		<div class='write_options'>
			<dt><?=_('Write size')?>: </dt>
			<dd>
				<select name="-w">
					<option value="0"><?=_('Default')?></option><?=$size;?>
				</select>
			</dd>
			<dt><?=_('Skip Pre-read')?>: </dt>
			<dd>
				<input type="checkbox" name="-W" class="switch" >
			</dd>
		</div>
		<?if ( array_key_exists("fast_postread", $capabilities) && $capabilities["fast_postread"] ):?>
		<div class='postread_options'>
			<dt><?=_('Fast Post-read')?>: </dt>
			<dd>
				<input type="checkbox" name="-f" class="switch" >
			</dd>
		</div>
		<?endif;?>
		<div class='inline_help'>
			<p><?=_('Enable Testing for debugging')?></p>
			<dt><?=_('Testing')?>:</dt>
			<dd>
				<input type="checkbox" name="-s" class="switch" >
			</dd>
		</div>
	</dl>
</div>

<div id="gfjardim-start-defaults" style="display:none;">
	<dl class="dl-dialog">
		<dt><?=_('Operation')?>: </dt>
		<dd>
			<select name="op" onchange="toggleSettings(this);">
				<option value="0"><?=_('Clear Disk')?></option>
				<option value="--verify"><?=_('Verify Disk')?></option>
				<option value="--signature"><?=_('Verify Signature')?></option>
				<option value="--erase"><?=_('Erase Disk')?></option>
				<option value="--erase-clear"><?=_('Erase and Clear Disk')?></option>
			</select>
		</dd>
		<div class="write_options cycles_options">
			<dt><?=_('Cycles')?>: </dt>
			<dd>
				<select name="--cycles"><?=$cycles2;?></select>
			</dd>
		</div>
		<div class="notify_options">
			<dt><?=_('Notifications')?>:</dt>
			<dd style="font-weight: normal;">
				<input type="checkbox" name="preclear_notify" onchange="toggleFrequency(this, '--frequency');">Per Unraid Settings
			</dd>
			<dt>&nbsp;</dt>
			<dd>
				<select name="--frequency" disabled>
					<option value="1" selected><?=_('On preclear end')?></option>
					<option value="2"><?=_('On every cycle end')?></option>
					<option value="3"><?=_('On every cycle and step end')?></option>
					<option value="4"><?=_('On every 25% of progress')?></option>
				</select>
			</dd>
		</div>
		<div class="write_options">
			<dt><?=_('Skip Pre-Read')?>: </dt>
			<dd>
				<input type="checkbox" name="--skip-preread" class="switch" >
			</dd>
			<dt><?=_('Skip Post-Read')?>: </dt>
			<dd>
				<input type="checkbox" name="--skip-postread" class="switch" >
			</dd>
		</div>
		<div class='inline_help'>
			<p><?=_('Enable Testing for debugging')?></p>
			<dt><?=_('Testing')?>:</dt>
			<dd>
				<input type="checkbox" name="--test" class="switch" >
			</dd>
		</div>
	</dl>
</div>

<div id="preclear-set-queue-defaults" style="display:none;">
	<dl>
		<?=_('If you set a queue limit, all running preclear sessions above that limit will be paused and remain in the queue until a session finishes')?>.<br><br>
	</dl>
	<dl class="dl-dialog">
		<dt><?=_('Concurrent Sessions')?>: </dt>
		<dd>
			<select name="queue">
					<?=$queue;?>
				</select>
		</dd>
	</dl>
</div>
	<?
	}
}
?>
