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

var PreclearURL = '/plugins/'+preclear_plugin+'/Preclear.php'
var PreclearData = {};

if (typeof " ".formatUnicorn !== "function")
{
	String.prototype.formatUnicorn = String.prototype.formatUnicorn ||
	function () {
		"use strict";
		var str = this.toString();
		if (arguments.length) {
				var t = typeof arguments[0];
				var key;
				var args = ("string" === t || "number" === t) ?
						Array.prototype.slice.call(arguments)
						: arguments[0];

				for (key in args) {
						str = str.replace(new RegExp("\\{" + key + "\\}", "gi"), args[key]);
				}
		}

		return str;
	};
}

$('body').on('mouseenter', '.tooltip, .tooltip-toggle', function()
{
	onClose = {click:true, scroll:true, mouseleave:true, tap:true};
	if ( $(this).hasClass("tooltip-toggle") )
	{
		onClose.click = false;
	}
	if (!$(this).hasClass("tooltipstered")) {
		$(this).tooltipster(
		{
			delay:100,
			zIndex:999,
			trigger:'custom',
			triggerOpen:{mouseenter:true, touchstart:true},
			triggerClose:onClose,
		}).tooltipster('open');
	}
});


function getPreclearContent()
{
	clearTimeout(timers.getPreclearContent);
	$.post(PreclearURL,{action:'get_content', display:preclear_display}, function(data)
	{
		PreclearData = data;
		var hovered = $( ".tooltip:hover" ).map(function(){return this.id;}).get();
		if ( $('#preclear-table-body').length ) {
			var target		= $( '#preclear-table-body' );
			currentScroll	= $(window).scrollTop();
			currentToggled	= getToggledReports();
			target.empty();
			$.each(data.sort, function(i,v)
			{
				target.append(data.disks[v]);
			});
			toggleReports(currentToggled);
			$(window).scrollTop(currentScroll);
		}

		leftover_icons = $("[id^=preclear_footer_]");

		$.each(data.status, function(i,v)
		{
			var target = $("#preclear_"+i);
			var icon = "preclear_footer_" + i;

			leftover_icons = $.grep(leftover_icons, function (el, i) { return $(el).attr('id') != icon });

			$("#preclear_"+i).html("<i style='margin-left: -10px;' class='icon-preclear'></i><span style='margin-left: 4px;'></span>"+v.status);

			if (! $("#"+icon).length) {
				el	= "<span class='exec' title='' id='"+icon+"'>"+preclear_footer_icon+"</span> &nbsp;";
				el	= $(el).prependTo("#preclear-footer").css("margin-right", "6px");
				el.tooltipster(
				{
					delay:100,
					zIndex:100,
					trigger:'custom',
					triggerOpen:{mouseenter:true, touchstart:true},
					triggerClose:{click:false, scroll:true, mouseleave:true, tap:true},
					contentAsHTML: true,
					interactive: true,
					updateAnimation: false,
					functionBefore: function(instance, helper)
					{
						instance.content($(helper.origin).attr("data"));
					}
				});
			}
			content = $("<div>").append(v.footer);
			content.find("a[id^='preclear_rm_']").attr("id", "preclear_footer_rm_" + i);
			content.find("a[id^='preclear_open_']").attr("id", "preclear_footer_open_" + i);
			$("#"+icon).tooltipster('content', content.html());
		});

		$.each(leftover_icons, function(i,v){ $(v).remove(); });

		$("a[id^='preclear_footer_']").each(function(i,v)
		{
			id = $(v).attr("id").split("_").pop();
			if (! (id in data.status)) {
				$(v).remove();
			}
		});

		$.each(hovered, function(k,v){ if(v.length) { $("#"+v).trigger("mouseenter");} });

		$(".preclear-queue").css("color",(data.queue) ? "#00BE37" : "");

		window.disksInfo = JSON.parse(data.info);

		if (typeof(startDisk) !== 'undefined') {
			startPreclear(startDisk);
			delete window.startDisk;
		}
	},'json').always(function(jqXHR, textStatus, error){
		if (jqXHR.status == 200) {
			clearTimeout(timers.getPreclearContent);
		} else if (jqXHR.status == 404) {	
			setTimeout( clearTimeout, 300, timers.getPreclearContent);
		} else {
			timers.getPreclearContent = setTimeout(getPreclearContent, ($(jqXHR.status).length > 0) ? 5000 : 15000);
		}
	});
}

function openPreclear(serial)
{
	var width	= 1000;
	var height	= 730;
	var top		= (screen.height-height)/2;
	var left	= (screen.width-width)/2;
	var options	= 'resizeable=yes,scrollbars=yes,height='+height+',width='+width+',top='+top+',left='+left;
	window.open('/plugins/'+preclear_plugin+'/Preclear.php?action=show_preclear&serial='+serial, '_blank', options);
}

function openPreclearLog(search)
{
	var title = "Preclear Log";
	var form = $("<form />", { action: "/plugins/"+preclear_plugin+"/logger.php", target:"_blank", method:"POST" });
	form.append('<input type="hidden" name="file" value="/var/log/preclear/preclear.log" />');
	form.append('<input type="hidden" name="csrf_token" value="'+preclear_vars.csrf_token+'" />');
	if (typeof(search) !== 'undefined') {
		form.append('<input type="hidden" name="search" value="'+search+'" />');
		title = title + " of disk " + search.split("_")[2];
	}
	form.append('<input type="hidden" name="title" value="'+title+'" />');
	form.appendTo( document.body ).submit();
	form.remove();
}

function toggleScript(el, serial, multiple)
{
	window.preclear_scope = $(el).val();

	startPreclear( serial, multiple );
}

function startPreclear(serial, multiple = "no")
{
	if (typeof(serial) === 'undefined') {
		return false;
	}

	preclear_dialog = $( "#preclear-dialog" );

	if (multiple == "no") {
		var opts = {
			model:			getDiskInfo(serial, 'MODEL'),
			serial_short:	getDiskInfo(serial, 'SERIAL_SHORT'),
			firmware:		getDiskInfo(serial, 'FIRMWARE'),
			size_h:			getDiskInfo(serial, 'SIZE_H'),
			device:			getDiskInfo(serial, 'NAME_H')
		};

		var header = $("#dialog-header-defaults").html();

		preclear_dialog.html( header.formatUnicorn(opts) );
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	} else {
		var header = $("#dialog-multiple-defaults").html();
		var options = "";

		for (key in disksInfo) {
			disk = disksInfo[key];
			if (disk.hasOwnProperty('SERIAL_SHORT')) {
				var disk_serial = disk['SERIAL_SHORT'];
				var opts = {
					device:			getDiskInfo(disk_serial, 'DEVICE'),
					model:			getDiskInfo(disk_serial, 'MODEL'),
					name:			getDiskInfo(disk_serial, 'NAME'),
					serial_short:	disk_serial,
					size_h:			getDiskInfo(disk_serial, 'SIZE_H'),
					disabled:		(disk['PRECLEARING']) ? "disabled" : ""
				};
				option	= "<option value='{serial_short}' {disabled}>{name} - {serial_short} ({size_h})</option>";
				options	+= option.formatUnicorn(opts);
			}
		}

		preclear_dialog.html( header.formatUnicorn(options) );
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	}

	if (typeof(preclear_scripts) !== 'undefined') {
		size = Object.keys(preclear_scripts).length;

		if (size) {
			var options = "<dl class='dl-dialog'><dt>Script:<st><dd><select onchange='toggleScript(this,\""+serial+"\",\""+multiple+"\");'>";
			$.each( preclear_scripts, function( key, value )
			{
				var sel = ( key == preclear_scope ) ? "selected" : "";
				options += "<option value='"+key+"' "+sel+">"+preclear_authors[key]+"</option>";
			}
			);
			preclear_dialog.append(options+"</select></dd></dl>");
		}
	}

	preclear_dialog.append($("#"+preclear_scope+"-start-defaults").html());

	swal2({
		title: "Start Preclear",
		content:{ element: "div", attributes:{ innerHTML: preclear_dialog.html()}},
		icon: "info",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Start", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer) {
			var opts		= new Object();
			opts["device"]	= [];
			if(serial) {
				opts["device"].push(getDiskInfo(serial, 'DEVICE'));
			}
			popup			= $(".swal-content");
			opts["action"]	= "start_preclear";
			opts["op"]		= getVal(popup, "op");
			opts["scope"]	= preclear_scope;

			if (popup.find('#multiple_preclear :selected').length) {
				opts["device"] = [];
				popup.find('#multiple_preclear :selected').each( function(){
					opts["device"].push(getDiskInfo(this.value, 'DEVICE'));
				});
			}

			if (preclear_scope == "gfjardim") {
				opts["--cycles"]		= getVal(popup, "--cycles");
				opts["--notify"]		= getVal(popup, "preclear_notify") == "on" ? 4 : 0;
				opts["--frequency"]		= getVal(popup, "--frequency");
				opts["--skip-preread"]	= getVal(popup, "--skip-preread");
				opts["--skip-postread"]	= getVal(popup, "--skip-postread");			
				opts["--test"]			= getVal(popup, "--test");			
			} else {
				opts["-c"]	= getVal(popup, "-c");
				opts["-o"]	= getVal(popup, "preclear_notify") == "on" ? 1 : 0;
				opts["-M"]	= getVal(popup, "-M");
				opts["-r"]	= getVal(popup, "-r");
				opts["-w"]	= getVal(popup, "-w");
				opts["-W"]	= getVal(popup, "-W");
				opts["-f"]	= getVal(popup, "-f");
				opts["-s"]	= getVal(popup, "-s");
			}


			$.post(PreclearURL, opts, function(data)
			{
				preclearShowResult(data);
			},'json').always(function(data)	{
				preclearUpdateContent();
			},'json').fail(function() {preclearShowResult(false);});
		}
	});

	/* Allow dropdown overflow. */
	$('.swal-modal').css('overflow', 'visible');
	$("#multiple_preclear_chosen > .chosen-choices").css("min-height", "27px");
}

function stopPreclear(serial, ask, multiple = 'no')
{
	var title = 'Stop Preclear';

	if (ask != "ask")
	{
		$.post(PreclearURL,{action:"stop_preclear",'serial':serial}, function(data){
			preclearShowResult(data);
			preclearUpdateContent();
		},'json').fail(function() {preclearShowResult(false);});

		return true;
	}

	preclear_dialog = $( "#preclear-dialog" );

	if (multiple == "no")
	{
		var opts = {
			model:			getDiskInfo(serial, 'MODEL'),
			serial_short:	getDiskInfo(serial, 'SERIAL_SHORT'),
			firmware:		getDiskInfo(serial, 'FIRMWARE'),
			size_h:			getDiskInfo(serial, 'SIZE_H'),
			device:			getDiskInfo(serial, 'NAME_H')
		};
	
		var header = $("#dialog-header-defaults").html();
	
		preclear_dialog.html( header.formatUnicorn(opts) );
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	} else {
		var header = $("#dialog-multiple-defaults").html();
		var options = "";

		for(key in disksInfo) {
			disk = disksInfo[key];
			if(disk.hasOwnProperty('SERIAL_SHORT')) {
				var disk_serial = disk['SERIAL_SHORT'];
				var opts = {
					device:			getDiskInfo(disk_serial, 'DEVICE'),
					model:			getDiskInfo(disk_serial, 'MODEL'),
					name:			getDiskInfo(disk_serial, 'NAME'),
					serial_short:	disk_serial,
					size_h:			getDiskInfo(disk_serial, 'SIZE_H'),
					disabled:		( ! disk['PRECLEARING']) ? "disabled" : ""
				};
			option = "<option value='{serial_short}' {disabled}>{name} - {serial_short} ({size_h})</option>";
			options += option.formatUnicorn(opts);
			}
		}

		preclear_dialog.html( header.formatUnicorn(options) );
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	}

	swal2({
		title: "Stop Preclear",
		content:{ element: "div", attributes:{ innerHTML: preclear_dialog.html()}},
		icon: "warning",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Stop", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer) {
			var opts		= new Object();
			opts["serial"]	= [];
			if (serial) {
				opts["serial"].push(serial, 'DEVICE');
			}
			popup = $(".swal-content");
			opts["action"] = "stop_preclear";

			if (popup.find('#multiple_preclear :selected').length) {
				opts["serial"] = [];
				popup.find('#multiple_preclear :selected').each( function(){
					opts["serial"].push(this.value);
				});
			}

			if (opts.serial.length > 0) {
				$.post(PreclearURL, opts, function(data)
				{
					preclearShowResult(data);
					preclearUpdateContent();
				},'json').fail(function() {preclearShowResult(false);});
			}
		}
	});
}


function preclearClear()
{
	swal2({
		title: "Fix Preclear",
		content:{ element: "div", attributes:{ innerHTML:	"This will stop all running sessions, halt all processes and remove all related files.<br /><br /><span class='red-text'><b>Do you want to proceed?</b></span>"}},
		icon: "warning",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Fix", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer)
		{
			$.post(PreclearURL, {'action':'clear_all_preclear'}, function(data)
			{
				preclearShowResult(data);
				getPreclearContent();
			},'json').fail(function() {preclearShowResult(false);});
		}
	});
}


function getVal(el, name)
{
	el = $(el).find("*[name="+name+"]");
	return value = ( $(el).attr('type') == 'checkbox' ) ? ($(el).is(':checked') ? "on" : "off") : $(el).val();
}


function toggleSettings(el) {
	var value = $(el).val();
	switch(value)
	{
		case '0':
		case '--erase-clear':
			$(el).parent().siblings('.read_options').css('display', 'block');
			$(el).parent().siblings('.write_options').css('display', 'block');
			$(el).parent().siblings('.postread_options').css('display', 'block');
			$(el).parent().siblings('.notify_options').css('display', 'block');
			break;

		case '--verify':
		case '--signature':
		case '-V':
			$(el).parent().siblings('.write_options').css('display', 'none');
			$(el).parent().siblings('.read_options').css('display', 'block');
			$(el).parent().siblings('.postread_options').css('display', 'block');
			$(el).parent().siblings('.notify_options').css('display', 'block');
			break;

		case '--erase':
			$(el).parent().siblings('.write_options').css('display', 'none');
			$(el).parent().siblings('.read_options').css('display', 'block');
			$(el).parent().siblings('.postread_options').css('display', 'block');
			$(el).parent().siblings('.notify_options').css('display', 'block');
			$(el).parent().siblings('.cycles_options').css('display', 'block');
			break;

		case '-t':
		case '-z':
			$(el).parent().siblings('.read_options').css('display', 'none');
			$(el).parent().siblings('.write_options').css('display', 'none');
			$(el).parent().siblings('.postread_options').css('display', 'none');
			$(el).parent().siblings('.notify_options').css('display', 'none');
			break;

		default:
			$(el).parent().siblings('.read_options').css('display', 'block');
			$(el).parent().siblings('.write_options').css('display', 'block');
			$(el).parent().siblings('.postread_options').css('display', 'block');
			$(el).parent().siblings('.notify_options').css('display', 'block');
			break;
	}
}

function toggleFrequency(el, name) {
	var disabled = true;
	var sel			= $(el).parent().parent().find("select[name='"+name+"']");
	$(el).siblings("*[type='checkbox']").addBack().each(function(v, e)
		{
			if ($(e).is(':checked'))
			{
				disabled = false;
			}
		}
	);

	if (disabled) {
		sel.attr('disabled', 'disabled');
	} else {
		sel.removeAttr('disabled');
	}
}

function toggleNotification(el) {
	if (el.selectedIndex > 0 ) {
		$(el).parent().siblings('.notification_options').css('display','block');
	} else 	{
		$(el).parent().siblings('.notification_options').css('display','none');
	}
}

function getDiskInfo(serial, info){
	for(key in disksInfo)
	{
		disk = disksInfo[key];
		if (disk.hasOwnProperty('SERIAL_SHORT') && disk['SERIAL_SHORT'] == serial)
		{
			return disk[info];
		}
	}
}

function toggleReports(opened)
{
	$(".toggle-reports").each(function()
	{
		var elem = $(this);
		var disk = elem.attr("hdd");
		elem.disableSelection();

		elem.click(function()
		{
			var elem = $(this);
			var disk = elem.attr("hdd");
			if ( $("div.toggle-"+disk+":first").is(":visible") ) {
				elem.find(".fa-append").removeClass("fa-minus-circle").addClass("fa-plus-circle");
			} else {
				elem.find(".fa-append").addClass("fa-minus-circle").removeClass("fa-plus-circle");
			}
			$(".toggle-"+disk).slideToggle(150);
		});

		if (typeof(opened) !== 'undefined') {
			if ( $.inArray(disk, opened) > -1 ) {
				$(".toggle-"+disk).css("display","block");
				elem.find(".fa-append").addClass("fa-minus-circle").removeClass("fa-plus-circle");
			}
		}
	});
}

function getToggledReports()
{ 
	var opened = [];
	$(".toggle-reports").each(function(e)
	{
		var elem = $(this);
		var disk = elem.attr("hdd");
		if ( $("div.toggle-"+disk+":first").is(":visible") ) {
			opened.push(disk);
		}
	});

	return opened;
}

function rmReport(file, el)
{
	$.post(PreclearURL, {action:"remove_report", file:file}, function(data)
	{
		if (data)
		{
			var remain = $(el).closest("div").siblings().length;
			if ( remain == "0")
			{
				$(el).closest("td").find(".fa-minus-circle, .fa-plus-circle").css("opacity", "0.0");
			}
			$(el).parent().remove();
			preclearShowResult(data)
		}
	}).fail(function() {preclearShowResult(false);});
}

function getResumablePreclear(serial)
{
	$.post(PreclearURL,{action:'get_resumable', serial:serial}, function(data)
	{
		if (data.resume)
		{
			swal2({
				title: "Resume Preclear",
				content:{ element: "div", attributes:{ innerHTML: "There's a previous preclear session available for this drive.<br />Do you want to resume it instead of starting a new one?"}},
				icon: "info",
				buttons:{
					cancel:{text: "Cancel", value: null, visible: true, className: "swal-button .swal-button-left", closeModal: true},
					confirm:{text: "Resume", value: 1, visible: true, className: "", closeModal: false},
					new:{text: "Start New", value: 2, visible: true, className: "", closeModal: false},
				}
			}).then((answer) => {
				if (answer == 1) {
					var opts		= new Object();
					opts["action"]	= "start_preclear";
					opts["serial"]	= serial;
					opts["device"]	= [];
					opts["device"].push(getDiskInfo(serial, 'DEVICE'));
					opts["op"]		= "resume";
					opts["file"]	= data.resume;
					opts["scope"]	= "gfjardim";

					$.post(PreclearURL, opts).done(function(data) {
						preclearShowResult(data);
						preclearUpdateContent();
					}).fail(function() {preclearShowResult(false);});
				} else if (answer == 2) {
					swal2.stopLoading();
					startPreclear(serial);
				}
			});
		} else {
			startPreclear(serial);
		}
	},'json').fail(function() {preclearShowResult(false);});
}

function setPreclearQueue()
{
	$.post(PreclearURL,{action:'get_queue'}, function(data)
	{
		preclear_dialog = $( "#preclear-dialog" );
		preclear_dialog.html("");
		newSelect = $("#preclear-set-queue-defaults").clone();
		newSelect.find("option[value='" + data + "']").attr('selected','selected');
		preclear_dialog.append(newSelect.html());

		swal2({
			title: "Set Queue Limit",
			content:{ element: "div", attributes:{ innerHTML: preclear_dialog.html()}},
			icon: "info",
			buttons:{
				cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
				confirm:{text: "Set", value: true, visible: true, className: "", closeModal: false},
			}
		}).then((answer) => {
			if (answer)
			{

				var opts = new Object();
				popup = $(".swal-modal");
				opts["action"] = "set_queue";
				opts["queue"] = getVal(popup, "queue");
				console.log(opts["queue"])

				$.post(PreclearURL, opts).always(function(data)
				{
					preclearShowResult(data);
					getPreclearContent();
				},'json');
			}
		});
	});
}

function resumePreclear(disk)
{
  $.post(PreclearURL,{action:'resume_preclear', disk:disk}, function(data)
  {
	preclearShowResult(data)
    getPreclearContent();
}).fail(function() {preclearShowResult(false);});
}


function preclearPauseAll()
{
	swal2({
		title: "Pause All Preclear Sessions",
		text:	"Do you want to pause all running preclear sessions?",
		icon: "warning",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Pause", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer) {
			$.post(PreclearURL,{action:'pause_all'}, function(data)
			{
				preclearShowResult(data);
				getPreclearContent();
			},'json').fail(function() {preclearShowResult(false);});
		}
	});
}


function preclearResumeAll()
{
	swal2({
		title: "Resume All Preclear Sessions",
		text:	"Do you want to resume all running preclear sessions?",
		icon: "warning",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Resume", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer) {
			$.post(PreclearURL,{action:'resume_all'}, function(data)
			{
				preclearShowResult(data);
				getPreclearContent();
			},'json').fail(function() {preclearShowResult(false);});
		}
	});
}

function preclearStopAll()
{
	swal2({
		title: "Stop All Preclear Sessions",
		text:	"Do you want to stop all running preclear sessions?",
		icon: "warning",
		buttons:{
			cancel:{text: "Cancel", value: null, visible: true, className: "", closeModal: true},
			confirm:{text: "Stop", value: true, visible: true, className: "", closeModal: false},
		}
	}).then((answer) => {
		if (answer) {
			$.post(PreclearURL,{action:'stop_all_preclear'}, function(data)
			{
				preclearShowResult(data);
				getPreclearContent();
			},'json').fail(function() {preclearShowResult(false);});
		}
	});
}

function preclearShowResult(success)
{
	if (success) {
		swal2({title:"Success!",text:" ",icon:"success",buttons:{confirm:{visible:false},cancel:{visible:false}},timer:1200});
	} else {
		swal2({title:"Fail!",text:" ",icon:"error",buttons:{confirm:{visible:false},cancel:{visible:false}},timer:1200});
	}
}

var preclearSortableHelper = function(e,i)
{
	i.toggleClass("even odd");
	i.children().each(function(){
		$(this).width($(this).width());
	});
	return i;
};

var preclearStartSorting = function(e,i)
{
	clearTimeout(timers.getPreclearContent);
	$(i.item).find("div[class*=toggle-]:visible").prev().addClass("sortable_toggled");
	$(i.item).find("div[class*=toggle-]:visible").prev().trigger("click");
};

var preclearStopSorting = function(e,i)
{
	timers.getPreclearContent = setTimeout('getPreclearContent()', 1500);
	$(i.item).find(".sortable_toggled").trigger("click").removeClass("sortable_toggled");
};

var preclearUpdateSorting = function(e,i)
{
	var devices = [];
	$('#preclear-table-body').find("tr").each(function()
	{
		devices.push($(this).attr("device"));
	});
	$.post(PreclearURL ,{'action':'save_sort', 'devices':devices}, function(data)
	{
		clearTimeout(timers.getPreclearContent);
		timers.preclear = setTimeout('getPreclearContent()', 1500);
	});
};

function preclearResetSorting()
{
	$.post(PreclearURL ,{'action':'reset_sort'}, function(data){getPreclearContent();});
}

function preclearSetSorting()
{
	$('#preclear-table-body').sortable({tolerance: "pointer",helper:preclearSortableHelper,items:'tr.sortable',cursor:'move',axis:'y',containment:'parent',cancel:'span.docker_readmore,input',delay:100,opacity:0.5,zIndex:9999,
	  update:preclearUpdateSorting,start:preclearStartSorting,stop:preclearStopSorting});
}

function preclearUpdateContent()
{
	if (typeof usb_disks === "function") {
		usb_disks();
	}
	getPreclearContent();
}
