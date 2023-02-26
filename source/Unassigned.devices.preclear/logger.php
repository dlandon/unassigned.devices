<?php
/* Copyright 2015-2020, Guilherme Jardim
 * Copyright 2022-2023, Dan Landon
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

/* HEADER */

$docroot		= $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$relative_path	= str_replace($docroot, '', __FILE__);
require_once	"$docroot/webGui/include/ColorCoding.php";

openlog(__FILE__, LOG_PID | LOG_PERROR, LOG_LOCAL0);

/* FUNCTIONS */

function preclear_log($msg) {
	syslog(LOG_INFO, $msg);
	return;
}

function readLastLines($filename, $lines, &$position, $find = "", $reverse = false)
{
	global $match, $color_coding;
	$output = [];
	if ($position == 0) {
		$contents = file($filename);
		$eof = count($contents);
		if (strlen($find)) $contents = preg_grep("/".$find."/i", $contents);
		$output = array_slice($contents, ($position ? $position : -$lines), null, true );
		$position = $eof;
	} else {
		$file = new SplFileObject($filename, 'r');
		$file->seek( PHP_INT_MAX );
		$eof = $file->key();
		for ($i=0; $i <= $eof; $i++) { 
			$file->seek($eof - $i);
			$line = $file->current();
			if (! strlen($find) || (strlen($find) && strpos($line, $find) !== false)) {
				$output[] = $line;
			}
			if ( ($eof - $i) == $position) {
				break;
			}
		}
		$position = $eof;
		$output = array_reverse($output);
	}

	foreach ($output as $key => $line) {
		$span = "span class='normal'";
		if ($color_coding) foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']}'"; break 2;}
		$line = preg_replace("/ /i", "&nbsp;", htmlspecialchars($line));
		$output[$key] = ($color_coding) ? "<$span>".$line."</span>" : $line;
	}

	return $output;
}

function isTimeout(&$counter, $timeout)
{
	$current = time();
	$counter = $counter ? $counter : time();
	if ( ($current - $counter) >= $timeout	) {
		$counter = null;
		return true;
	}
	return false;
}

/* MAIN */

$file_position = 0;
$search			= "";
$done_button	= "Done";
$action			= "";

foreach ($_POST as $key => $value) {
	switch ($key) {
		case 'action':
			$action = $value;
			break;
		case 'title':
			$title = $value;
			break;
		case 'search':
			$search = $value;
			break;
		case 'done_button':
			$done_button = $value;
			break;
		case 'color_coding':
			$color_coding = $value;
			break;
		case 'file':
			$file = $value;
			break;
		case 'file_position':
			$file_position = $value;
			break;
		case 'file_lines':
			$file_lines = $value;
			break;
		case 'command':
			$command = $value;
			break;
		case 'socket_name':
			$socket_name = $value;
			break;
		case 'timeout':
			$timeout = $value;
			break;
	}
}

if (!isset($file_lines)) {
	$file_lines = 30;
}
if (!isset($timeout)) {
	$timeout = 60;
}
if (!isset($color_coding) && $file) {
	$color_coding = true;
}

if ((isset($argv)) && (is_array($argv))) {
	foreach ($argv as $key => $value) {
		switch ($key) {
			case 1:
				$command = $value;
				break;
			case 2:
				$socket_name = $value;
				break;
			case 3:
				$search	= $value;
				break;
		}
	}
}

if (PHP_SAPI === "cli" && isset($command) && isset($socket_name)) {
	$task = "command_run";
} else if ($action == "get_command" && isset($command) && isset($socket_name)) {
	$task = "command_get_output";
} else if ( isset($file) && $action == "get_log" ) {
	 $task = "file_get_lines";
} else if (isset($file) && is_file($file) && $action == "download") {
	$task = "file_download";
} else if (isset($file) || isset($command)) {
	$task = "show_logger_html";
} else {
	exit();
}

/* Run command in the background. */
if ( $task == "command_run" ) {
	set_time_limit(0);

	$socket_file = "/tmp/.".$socket_name.".sock";

	$socket = stream_socket_server("unix://".$socket_file, $errno, $errstr);
	if (!$socket) {
		echo "$errstr ($errno)\n";
		exit(1);
	}

	$descriptorspec = [["pipe", "r"],["pipe", "w"],["pipe", "w"]];

	preclear_log("command: ".$command);

	$process = proc_open($command, $descriptorspec, $pipes, NULL, NULL);
	stream_set_blocking($pipes[1], 0);

	$return = "";
	$line = 0;
	$lock = true;
	$timer = time();
	$do_exit = false;

	if (is_resource($process)) {
		while ($lock) {
			if ($conn = stream_socket_accept($socket)) {
				$return = "";	 
				while ($line = fread($pipes[1],4096)) {
					if (strpos($line,'tail_log')!==false) continue;
					if ($search && strpos($line, $search)==false) continue;
					$span = "span";
					if ($color_coding) foreach ($match as $type) foreach ($type['text'] as $text) if (preg_match("/$text/i",$line)) {$span = "span class='{$type['class']}'"; break 2;}
					$line = preg_replace("/ /i", "&nbsp;", $line);
					$line = ($color_coding) ? "<$span>".$line."</span>" : $line;
					$return .= $line;
				}

				fwrite($conn, $return, strlen($return));
				fclose($conn);
				$timer = time();
				if ($do_exit) {
					break;
				}
			}

			if (! proc_get_status($process)['running']) {
				$do_exit = true;
			}

			if ($timeout && isTimeout($timer, $timeout)) {
				break;
			}
		}
	}
	proc_close($process);
	fclose($socket);
	@unlink($socket_file);

/* Get command output from background. */
} else if ( $task == "command_get_output" ) {
	$socket_file = "/tmp/.".$socket_name.".sock";

	$socket = stream_socket_client("unix://".$socket_file, $errno, $errstr);
	if (! $socket) {
			echo json_encode(["error" =>"$errstr\n", "error_code" =>"$errno"]);
	} else {
		stream_set_blocking($socket, true);
		$output = [];
		$line = "";
		$line_ending = false;
		while (($char = fgetc($socket)) !== false) {
			$eol = (str_replace(["\n", "\r", "\b"], '', $char) != $char);

			if (! $eol && $line_ending) {
				$output[] = $line;
				$line = "";
				$line_ending = false;
			} else if ($eol && ! $line_ending) {
				$line_ending = true;
			}
			$line .= $char;
		}
		$output[] = "{$line}";

		echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		fclose($socket);
		@unlink($socket_file);
	}

/* Get new file content. */
} else if ( $task == "file_get_lines" ) {
	$lines = readLastLines($file, 30, $file_position, $search, true);
	$output = array_merge(["file_position" => $file_position], $lines);
	echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);


/* Download file content. */
} else if ( $task == "file_download" ) {
		$contents = file($file, FILE_IGNORE_NEW_LINES);
	
		$file_name = pathinfo($file, PATHINFO_FILENAME);
	
		if (strlen($search)) {
			$contents = preg_grep("/".$search."/i", $contents);
			$file_name = $search;
		}
	
		$tmpfile = "/tmp/{$file_name}.txt";
		file_put_contents($tmpfile, implode("\r\n", $contents));

		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename='.basename($tmpfile));
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($tmpfile));
		readfile($tmpfile);
		unlink($tmpfile);


/* Display the logger page. */
} else if ( $task == "show_logger_html" ) {
	$lines = [];
	if (!isset($var)) {
		if (!is_file("$docroot/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(lsof -nPc emhttp | grep -Po 'TCP[^\d]*\K\d+')");
		$var = @parse_ini_file("$docroot/state/var.ini");
	}

	if (isset($file)) {
		// $lines = readLastLines($file, 30, $file_position, $search, true);
	} else if (isset($command)) {
		$socket_name = mt_rand();
		exec("php ".__FILE__." ".escapeshellarg($command)." ".escapeshellarg($socket_name)." ".escapeshellarg($search)." 1>/dev/null 2>&1 &");
	}
?>
<!DOCTYPE html>
<html>
<head>
	<title>
		<?=$title;?>
	</title>
	<style>
		@font-face{
		font-family:'clear-sans';font-weight:normal;font-style:normal;
		src:url('/webGui/styles/clear-sans.eot');src:url('/webGui/styles/clear-sans.eot?#iefix') format('embedded-opentype'),url('/webGui/styles/clear-sans.woff') format('woff'),url('/webGui/styles/clear-sans.ttf') format('truetype'),url('/webGui/styles/clear-sans.svg#clear-sans') format('svg');
		}
		@font-face{
		font-family:'clear-sans';font-weight:bold;font-style:normal;
		src:url('/webGui/styles/clear-sans-bold.eot');src:url('/webGui/styles/clear-sans-bold.eot?#iefix') format('embedded-opentype'),url('/webGui/styles/clear-sans-bold.woff') format('woff'),url('/webGui/styles/clear-sans-bold.ttf') format('truetype'),url('/webGui/styles/clear-sans-bold.svg#clear-sans-bold') format('svg');
		}
		@font-face{
		font-family:'clear-sans';font-weight:normal;font-style:italic;
		src:url('/webGui/styles/clear-sans-italic.eot');src:url('/webGui/styles/clear-sans-italic.eot?#iefix') format('embedded-opentype'),url('/webGui/styles/clear-sans-italic.woff') format('woff'),url('/webGui/styles/clear-sans-italic.ttf') format('truetype'),url('/webGui/styles/clear-sans-italic.svg#clear-sans-italic') format('svg');
		}
		@font-face{
		font-family:'clear-sans';font-weight:bold;font-style:italic;
		src:url('/webGui/styles/clear-sans-bold-italic.eot');src:url('/webGui/styles/clear-sans-bold-italic.eot?#iefix') format('embedded-opentype'),url('/webGui/styles/clear-sans-bold-italic.woff') format('woff'),url('/webGui/styles/clear-sans-bold-italic.ttf') format('truetype'),url('/webGui/styles/clear-sans-bold-italic.svg#clear-sans-bold-italic') format('svg');
		}
		@font-face{
		font-family:'bitstream';font-weight:normal;font-style:normal;
		src:url('/webGui/styles/bitstream.eot');src:url('/webGui/styles/bitstream.eot?#iefix') format('embedded-opentype'),url('/webGui/styles/bitstream.woff') format('woff'),url('/webGui/styles/bitstream.ttf') format('truetype'),url('/webGui/styles/bitstream.svg#bitstream') format('svg');
		}
		html{font-family:clear-sans;font-size:62.5%;height:100%}
		body{font-size:1.2rem;color:#1c1c1c;background:#f2f2f2;padding:0;margin:0;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
		.logLine{font-family:bitstream;font-size:1.2rem;margin:0 8px;padding:0}
		.logLine.spacing{margin:10px}
		input[type=button],input[type=reset],input[type=submit],button,button[type=button],a.button{font-family:clear-sans;font-size:1.1rem;font-weight:bold;letter-spacing:2px;text-transform:uppercase;margin:10px 12px 10px 0;padding:9px 18px;text-decoration:none;white-space:nowrap;cursor:pointer;outline:none;border-radius:4px;border:0;color:#ff8c2f;background:-webkit-gradient(linear,left top,right top,from(#e22828),to(#ff8c2f)) 0 0 no-repeat,-webkit-gradient(linear,left top,right top,from(#e22828),to(#ff8c2f)) 0 100% no-repeat,-webkit-gradient(linear,left bottom,left top,from(#e22828),to(#e22828)) 0 100% no-repeat,-webkit-gradient(linear,left bottom,left top,from(#ff8c2f),to(#ff8c2f)) 100% 100% no-repeat;background:linear-gradient(90deg,#e22828 0,#ff8c2f) 0 0 no-repeat,linear-gradient(90deg,#e22828 0,#ff8c2f) 0 100% no-repeat,linear-gradient(0deg,#e22828 0,#e22828) 0 100% no-repeat,linear-gradient(0deg,#ff8c2f 0,#ff8c2f) 100% 100% no-repeat;background-size:100% 2px,100% 2px,2px 100%,2px 100%}
		input:hover[type=button],input:hover[type=reset],input:hover[type=submit],button:hover,button:hover[type=button],a.button:hover{color:#f2f2f2;background:-webkit-gradient(linear,left top,right top,from(#e22828),to(#ff8c2f));background:linear-gradient(90deg,#e22828 0,#ff8c2f)}
		input[type=button][disabled],input[type=reset][disabled],input[type=submit][disabled],button[disabled],button[type=button][disabled],a.button[disabled]
		input:hover[type=button][disabled],input:hover[type=reset][disabled],input:hover[type=submit][disabled],button:hover[disabled],button:hover[type=button][disabled],a.button:hover[disabled]
		input:active[type=button][disabled],input:active[type=reset][disabled],input:active[type=submit][disabled],button:active[disabled],button:active[type=button][disabled],a.button:active[disabled]{cursor:default;color:#808080;background:-webkit-gradient(linear,left top,right top,from(#404040),to(#808080)) 0 0 no-repeat,-webkit-gradient(linear,left top,right top,from(#404040),to(#808080)) 0 100% no-repeat,-webkit-gradient(linear,left bottom,left top,from(#404040),to(#404040)) 0 100% no-repeat,-webkit-gradient(linear,left bottom,left top,from(#808080),to(#808080)) 100% 100% no-repeat;background:linear-gradient(90deg,#404040 0,#808080) 0 0 no-repeat,linear-gradient(90deg,#404040 0,#808080) 0 100% no-repeat,linear-gradient(0deg,#404040 0,#404040) 0 100% no-repeat,linear-gradient(0deg,#808080 0,#808080) 100% 100% no-repeat;background-size:100% 2px,100% 2px,2px 100%,2px 100%}
		.centered{text-align:center}
		span.normal{display:block;width:100%}
		span.error{color:#F0000C;background-color:#FF9E9E;display:block;width:100%}
		span.warn{color:#E68A00;background-color:#FEEFB3;display:block;width:100%}
		span.system{color:#00529B;background-color:#BDE5F8;display:block;width:100%}
		span.array{color:#4F8A10;background-color:#DFF2BF;display:block;width:100%}
		span.login{color:#D63301;background-color:#FFDDD1;display:block;width:100%}
		span.label{padding:4px 8px;margin-right:10px;border-radius:4px;display:inline;width:auto}
		#button_receiver {position: fixed;left: 0;bottom: 0;width: 100%;text-align: center;background: #f2f2f2;}
		div.spinner{margin:48px auto;text-align:center}
		div.spinner.fixed{display:none;position:fixed;top:50%;left:50%;margin-top:-16px;margin-left:-64px;z-index:10000;}
		div.spinner .unraid_mark{height:64px}
		div.spinner .unraid_mark_2,div .unraid_mark_4{animation:mark_2 1.5s ease infinite}
		div.spinner .unraid_mark_3{animation:mark_3 1.5s ease infinite}
		div.spinner .unraid_mark_6,div .unraid_mark_8{animation:mark_6 1.5s ease infinite}
		div.spinner .unraid_mark_7{animation:mark_7 1.5s ease infinite}
		@keyframes mark_2{50% {transform:translateY(-40px)} 100% {transform:translateY(0px)}}
		@keyframes mark_3{50% {transform:translateY(-62px)} 100% {transform:translateY(0px)}}
		@keyframes mark_6{50% {transform:translateY(40px)} 100% {transform:translateY(0px)}}
		@keyframes mark_7{50% {transform:translateY(62px)} 100% {transform: translateY(0px)}}

	</style>
	<link type="text/css" rel="stylesheet" href="/webGui/styles/default-fonts.css?v=1607102280">
	<script src="/webGui/javascript/dynamix.js"></script>
	<script type="text/javascript">
		var unraid_logo = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 133.52 76.97" class="unraid_mark"><defs><linearGradient id="unraid_logo" x1="23.76" y1="81.49" x2="109.76" y2="-4.51" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#e32929"/><stop offset="1" stop-color="#ff8d30"/></linearGradient></defs><path d="m70,19.24zm57,0l6.54,0l0,38.49l-6.54,0l0,-38.49z" fill="url(#unraid_logo)" class="unraid_mark_9"/><path d="m70,19.24zm47.65,11.9l-6.55,0l0,-23.79l6.55,0l0,23.79z" fill="url(#unraid_logo)" class="unraid_mark_8"/><path d="m70,19.24zm31.77,-4.54l-6.54,0l0,-14.7l6.54,0l0,14.7z" fill="url(#unraid_logo)" class="unraid_mark_7"/><path d="m70,19.24zm15.9,11.9l-6.54,0l0,-23.79l6.54,0l0,23.79z" fill="url(#unraid_logo)" class="unraid_mark_6"/><path d="m63.49,19.24l6.51,0l0,38.49l-6.51,0l0,-38.49z" fill="url(#unraid_logo)" class="unraid_mark_5"/><path d="m70,19.24zm-22.38,26.6l6.54,0l0,23.78l-6.54,0l0,-23.78z" fill="url(#unraid_logo)" class="unraid_mark_4"/><path d="m70,19.24zm-38.26,43.03l6.55,0l0,14.73l-6.55,0l0,-14.73z" fill="url(#unraid_logo)" class="unraid_mark_3"/><path d="m70,19.24zm-54.13,26.6l6.54,0l0,23.78l-6.54,0l0,-23.78z" fill="url(#unraid_logo)" class="unraid_mark_2"/><path d="m70,19.24zm-63.46,38.49l-6.54,0l0,-38.49l6.54,0l0,38.49z" fill="url(#unraid_logo)" class="unraid_mark_1"/></svg>';

		var lastLine = 0;
		var cursor;
		var file_position = "<?=$file_position;?>";
		var timers = {};
		var log_file =	 "<?=$file;?>";
		var log_search = "<?=$search;?>";
		var csrf_token = "<?=$var['csrf_token'];?>";
		<?if(isset($command)):?>
		var command		 = "<?=$command;?>"; 
		var socket_name = "<?=$socket_name;?>";
		<?endif;?>

		function addLog(logLine) {
			var scrollTop = (window.pageYOffset !== undefined) ? window.pageYOffset : (document.documentElement || document.body.parentNode).scrollTop;
			var clientHeight = (document.documentElement || document.body.parentNode).clientHeight;
			var scrollHeight = (document.documentElement || document.body.parentNode).scrollHeight;
			var isScrolledToBottom = scrollHeight - clientHeight <= scrollTop + 1;
			var receiver = window.document.getElementById( 'log_receiver');
			if (lastLine == 0) {
				lastLine = receiver.innerHTML.length;

				cursor = lastLine;
			}
			if (logLine.slice(-1) == "\n") {
				receiver.innerHTML = receiver.innerHTML.slice(0,cursor) + logLine.slice(0,-1) + "<br />";
				lastLine = receiver.innerHTML.length;
				cursor = lastLine;
				console.log("new line");
			}
			else if (logLine.slice(-1) == "\r" || logLine.slice(-2) == "\r\n") {
				receiver.innerHTML = receiver.innerHTML.slice(0,cursor) + logLine.slice(0,-1);
				cursor = lastLine;
				console.log("carriage return");
			}
			else if (logLine.slice(-1) == "\b") {
				if (logLine.length > 1)
					receiver.innerHTML = receiver.innerHTML.slice(0,cursor) + logLine.slice(0,-1);
					cursor += logLine.length-2;
			}
			else {
				receiver.innerHTML += logLine;
				cursor += logLine.length;
			}
			if (isScrolledToBottom) {
				window.scrollTo(0,receiver.scrollHeight);
			}
		}
		function addCloseButton() {
			var done = location.search.split('&').pop().split('=')[1];
			window.document.getElementById( 'button_receiver').innerHTML += "<button class='logLine' type='button' onclick='" + (inIframe() ? "top.Shadowbox" : "window") + ".close()'><?=$done_button;?></button>";
		}

		function getLogContent()
		{
			clearTimeout(timers.getLogContent);
			timers.logo = setTimeout(function(){$('div.spinner.fixed').show('slow');},500);
			<?if(isset($command)):?>
			$.post("<?=$relative_path;?>",{action:'get_command', command:command, csrf_token:csrf_token, socket_name:socket_name, search:log_search},function(data)
			<?else:?>
			$.post("<?=$relative_path;?>",{action:'get_log', file:log_file, csrf_token:csrf_token, file_position:file_position, search:log_search},function(data)
			<?endif;?>
			{
				clearTimeout(timers.logo);
				if (data.error) {
					if (data.error_code !== "2" ) {
						addLog("Error: " + data.error);
					}
					addCloseButton();
				} else {
					file_position = data.file_position;
					$.each(data, function(k,v) { 
						if(v.length) {
							addLog(v);
						}
					});
					timers.getLogContent = setTimeout(getLogContent, 100);
				}
				$('div.spinner.fixed').hide('slow');
			},'json');
		}

		function inIframe () {
			try { return window.self !== window.top; } 
			catch (e) { return true; }
		 }

		 function download() {
			var form = $("<form />", { action: "<?=$relative_path;?>", method:"POST" });
			form.append('<input type="hidden" name="file" value="'+log_file+'" />');
			form.append('<input type="hidden" name="action" value="download" />');
			form.append('<input type="hidden" name="search" value="'+log_search+'" />');
			form.append('<input type="hidden" name="csrf_token" value="'+csrf_token+'" />');
			form.appendTo( document.body ).submit();
			form.remove();			 
		 }

		$(function() { 
			$('div.spinner.fixed').html(unraid_logo);
			getLogContent();
		});
	</script>
</head>

<body class="logLine" onload="">
	<div class="spinner fixed"></div>
	<div id="log_receiver" style="padding-bottom: 60px;">
		<?if ($color_coding):?>
		<p style='text-align:center'><span class='error label'>Error</span><span class='warn label'>Warning</span><span class='system label'>System</span><span class='array label'>Array</span><span class='login label'>Login</span></p>
		<?endif;?>
		<?foreach ($lines as $line) echo "$line\n";?>
	</div>
	<div id="button_receiver">
		<?if(isset($file)):?>
			<button onclick="download();">Download</button>	
		<?endif;?>
	</div>
</body>
</html>
<?}?>