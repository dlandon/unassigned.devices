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

const PreclearURL		= '/plugins/' + preclear_plugin + '/include/Preclear.php'
const PreclearLoggerURL	= '/plugins/' + preclear_plugin + '/include/logger.php'

var PreclearData	= {};

/*
	Utility function to replace placeholders in a string with corresponding values from an object or arguments list.
	@param template: The template string containing placeholders like {key}.
	@param values: An object with key-value pairs or multiple arguments.
	@return: The formatted string.
*/
function formatString(template, values) {
	"use strict";

	/* Ensure the input is a string */
	let str = template.toString();

	/* Determine the type of the values parameter */
	const args = typeof values === "object" ? values : Array.from(arguments).slice(1);

	/* Replace placeholders with corresponding values */
	for (const key in args) {
		if (Object.prototype.hasOwnProperty.call(args, key)) {
			const pattern = new RegExp(`\\{${key}\\}`, "gi");
			str = str.replace(pattern, args[key]);
		}
	}

	return str;
}

/*
	Event handler for tooltips to initialize and display them on mouseenter.
*/
$('body').on('mouseenter', '.tooltip, .tooltip-toggle', function () {
	/* Configure tooltip close triggers. */
	const onClose = {
		click: !$(this).hasClass("tooltip-toggle"),
		scroll: true,
		mouseleave: true,
		tap: true
	};

	/* Initialize tooltip if not already initialized. */
	if (!$(this).hasClass("tooltipstered")) {
		$(this).tooltipster({
			delay: 100,
			zIndex: 999,
			trigger: 'custom',
			triggerOpen: { mouseenter: true, touchstart: true },
			triggerClose: onClose
		}).tooltipster('open');
	}
});

/*
	Function to fetch and update the preclear content dynamically.
*/
function getPreclearContent() {
	/* Clear any existing timeout for the periodic content fetch */
	clearTimeout(timers.getPreclearContent);

	/* Fetch preclear data via AJAX POST */
	$.post(
		PreclearURL,
		{ action: 'get_preclear_content', display: preclear_display },
		function (data) {
			PreclearData = data;

			/* Get the IDs of currently hovered tooltips */
			const hovered = $(".tooltip:hover")
				.map(function () {
					return this.id;
				})
				.get();

			/* Update the preclear table if it exists */
			if ($('#preclear-table-body').length) {
				const target = $('#preclear-table-body');
				const currentScroll = $(window).scrollTop();

				/* Retrieve the toggled reports state from localStorage */
				const currentToggled = getToggledReports();

				/* Clear existing table content and repopulate with sorted data */
				target.empty();
				$.each(data.sort, function (i, v) {
					target.append(data.disks[v]);
				});

				/* Reapply the toggled state after loading new content */
				toggleReports(currentToggled);

				/* Restore the previous scroll position */
				$(window).scrollTop(currentScroll);
			}

			/* Manage leftover icons in the footer */
			let leftoverIcons = $("[id^=preclear_footer_]");
			$.each(data.status, function (id, statusData) {
				const target = $(`#preclear_${id}`);
				const iconId = `preclear_footer_${id}`;

				/* Filter out the current icon from leftover icons */
				leftoverIcons = $.grep(leftoverIcons, function (el) {
					return $(el).attr('id') !== iconId;
				});

				/* Update the status icon and tooltip content */
				target.html(
					`<i style='margin-left: -10px;' class='icon-preclear'></i><span style='margin-left: 4px;'></span>${statusData.status}`
				);

				/* Add and initialize a tooltip for the icon if it doesn't already exist */
				if (!$(`#${iconId}`).length) {
					const iconElement = $(
						`<span class='exec' title='' id='${iconId}'>${preclear_footer_icon}</span> &nbsp;`
					)
						.prependTo('#preclear-footer')
						.css('margin-right', '6px')
						.tooltipster({
							delay: 100,
							zIndex: 100,
							trigger: 'custom',
							triggerOpen: { mouseenter: true, touchstart: true },
							triggerClose: {
								click: false,
								scroll: true,
								mouseleave: true,
								tap: true,
							},
							contentAsHTML: true,
							interactive: true,
							updateAnimation: false,
							functionBefore: function (instance, helper) {
								instance.content($(helper.origin).attr('data'));
							},
						});
				}

				/* Update the tooltip content for the current icon */
				const content = $('<div>').append(statusData.footer);
				content.find("a[id^='preclear_rm_']").attr('id', `preclear_footer_rm_${id}`);
				content.find("a[id^='preclear_open_']").attr('id', `preclear_footer_open_${id}`);
				$(`#${iconId}`).tooltipster('content', content.html());
			});

			/* Remove leftover icons that are no longer relevant */
			$.each(leftoverIcons, function (_, icon) {
				$(icon).remove();
			});

			/* Remove footer icons for disks no longer in the status list */
			$("a[id^='preclear_footer_']").each(function () {
				const id = $(this).attr('id').split('_').pop();
				if (!(id in data.status)) {
					$(this).remove();
				}
			});

			/* Re-trigger mouseenter for hovered tooltips */
			$.each(hovered, function (_, id) {
				if (id.length) {
					$(`#${id}`).trigger('mouseenter');
				}
			});

			/* Update the queue color based on its status */
			$('.preclear-queue').css('color', data.queue ? '#00BE37' : '');

			/* Update the global disksInfo object */
			window.disksInfo = JSON.parse(data.info);

			/* Automatically start preclear for a disk if specified */
			if (typeof startDisk !== 'undefined') {
				startPreclear(startDisk);
				delete window.startDisk;
			}
		},
		'json'
	).always(function (jqXHR) {
		/* Handle periodic retry logic for errors or failures */
		if (jqXHR.status === 200) {
			clearTimeout(timers.getPreclearContent);
		} else if (jqXHR.status === 404) {
			setTimeout(clearTimeout, 300, timers.getPreclearContent);
		} else {
			const retryDelay = jqXHR.status ? 5000 : 15000;
			timers.getPreclearContent = setTimeout(getPreclearContent, retryDelay);
		}
	});
}

/*
	Function to open the preclear window for a given serial.
	@param serial: The serial number of the drive to preclear.
*/
function openPreclear(serial) {
	const width = 1000;
	const height = 730;
	const top = (screen.height - height) / 2;
	const left = (screen.width - width) / 2;
	const options = [
		'resizeable=yes',
		'scrollbars=yes',
		`height=${height}`,
		`width=${width}`,
		`top=${top}`,
		`left=${left}`
	].join(',');

	const url = `${PreclearURL}?action=show_preclear&serial=${serial}`;
	window.open(url, '_blank', options);
}

/*
	Function to open the preclear log in a new window.
	@param search: Optional. A search string to filter the log entries.
*/
function openPreclearLog(search) {
	/* Set the default title for the log */
	let title = _("Preclear Log");

	/* Create a hidden form to submit the request */
	const form = $("<form />", {
		action: PreclearLoggerURL,
		target: "_blank",
		method: "POST"
	});

	/* Add required hidden fields to the form */
	form.append('<input type="hidden" name="file" value="/var/log/preclear/preclear.log" />');
	form.append('<input type="hidden" name="csrf_token" value="' + preclear_vars.csrf_token + '" />');

	/* If a search parameter is provided, include it and update the title */
	if (typeof search !== "undefined") {
		form.append('<input type="hidden" name="search" value="' + search + '" />');
		title += " of disk " + search.split("_")[2];
	}

	/* Add the updated title to the form */
	form.append('<input type="hidden" name="title" value="' + title + '" />');

	/* Append the form to the document, submit it, and then remove it */
	form.appendTo(document.body).submit();
	form.remove();
}

/*
	Function to toggle the preclear script scope and start the preclear process.
	@param el: The HTML element that triggered the event (e.g., a dropdown or button).
	@param serial: The serial number of the disk.
	@param multiple: Indicates if multiple disks are selected ("yes" or "no").
*/
function toggleScript(el, serial, multiple) {
	/* Update the global preclear scope based on the selected value */
	window.preclear_scope = $(el).val();

	/* Initiate the preclear process with the provided serial and multiple status */
	startPreclear(serial, multiple);
}

/*
	Function to initiate the preclear process for a given disk or multiple disks.
	@param serial: The serial number of the disk.
	@param multiple: Indicates if multiple disks are selected ("yes" or "no").
*/
function startPreclear(serial, multiple = "no") {
	if (typeof serial === "undefined") {
		return false;
	}

	const preclear_dialog = $("#preclear-dialog");

	if (multiple === "no") {
		const opts = {
			model: getDiskInfo(serial, "MODEL"),
			serial_short: getDiskInfo(serial, "SERIAL_SHORT"),
			firmware: getDiskInfo(serial, "FIRMWARE"),
			size_h: getDiskInfo(serial, "SIZE_H"),
			device: getDiskInfo(serial, "NAME_H"),
		};

		const header = $("#dialog-header-defaults").html();
		preclear_dialog.html(formatString(header, opts));
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	} else {
		const header = $("#dialog-multiple-defaults").html();
		let options = "";

		for (const key in disksInfo) {
			const disk = disksInfo[key];
			if (disk.hasOwnProperty("SERIAL_SHORT")) {
				const disk_serial = disk["SERIAL_SHORT"];
				const opts = {
					device: getDiskInfo(disk_serial, "DEVICE"),
					model: getDiskInfo(disk_serial, "MODEL"),
					name: getDiskInfo(disk_serial, "NAME"),
					serial_short: disk_serial,
					size_h: getDiskInfo(disk_serial, "SIZE_H"),
					disabled: disk["PRECLEARING"] ? "disabled" : "",
				};
				const option = "<option value='{serial_short}' {disabled}>{name} - {serial_short} ({size_h})</option>";
				options += formatString(option, opts);
			}
		}

		preclear_dialog.html(formatString(header, { 0: options }));
		preclear_dialog.append("<hr style='margin-left:12px;'>");
	}

	if (typeof preclear_scripts !== "undefined") {
		const size = Object.keys(preclear_scripts).length;

		if (size) {
			let scriptOptions = "<dl class='dl-dialog'><dt>Script:<st><dd><select onchange='toggleScript(this,\"" + serial + "\",\"" + multiple + "\");'>";
			$.each(preclear_scripts, (key, value) => {
				const sel = key === preclear_scope ? "selected" : "";
				scriptOptions += `<option value='${key}' ${sel}>${preclear_authors[key]}</option>`;
			});
			preclear_dialog.append(scriptOptions + "</select></dd></dl>");
		}
	}

	preclear_dialog.append($("#" + preclear_scope + "-start-defaults").html());

	swal2({
		title: _("Start Preclear"),
		content: { element: "div", attributes: { innerHTML: preclear_dialog.html() } },
		icon: "info",
		buttons: {
			confirm: { text: _("Start"), value: true, visible: true, className: "", closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, className: "", closeModal: true },
		},
	}).then((answer) => {
		if (answer) {
			const opts = {};
			opts["device"] = [];

			if (serial) {
				opts["device"].push(getDiskInfo(serial, "DEVICE"));
			}

			const popup = $(".swal-content");
			opts["action"] = "start_preclear";
			opts["op"] = getVal(popup, "op");
			opts["scope"] = preclear_scope;

			if (popup.find("#multiple_preclear :selected").length) {
				opts["device"] = [];
				popup.find("#multiple_preclear :selected").each(function () {
					opts["device"].push(getDiskInfo(this.value, "DEVICE"));
				});
			}

			if (preclear_scope === "gfjardim") {
				opts["--cycles"] = getVal(popup, "--cycles");
				opts["--notify"] = getVal(popup, "preclear_notify") === "on" ? 4 : 0;
				opts["--frequency"] = getVal(popup, "--frequency");
				opts["--skip-preread"] = getVal(popup, "--skip-preread");
				opts["--skip-postread"] = getVal(popup, "--skip-postread");
				opts["--test"] = getVal(popup, "--test");
			} else {
				opts["-c"] = getVal(popup, "-c");
				opts["-o"] = getVal(popup, "preclear_notify") === "on" ? 1 : 0;
				opts["-M"] = getVal(popup, "-M");
				opts["-r"] = getVal(popup, "-r");
				opts["-w"] = getVal(popup, "-w");
				opts["-W"] = getVal(popup, "-W");
				opts["-f"] = getVal(popup, "-f");
				opts["-s"] = getVal(popup, "-s");
			}

			$.post(PreclearURL, opts, function (data) {
				preclearShowResult(data);
			}, "json").always(() => {
				preclearUpdateContent();
			}).fail(() => {
				preclearShowResult(false);
			});
		}
	});
}

/*
	Function to stop the preclear process for a given disk or multiple disks.
	@param serial: The serial number of the disk to stop.
	@param ask: If "ask", displays a confirmation dialog before stopping.
	@param multiple: Indicates if multiple disks are selected ("yes" or "no").
*/
function stopPreclear(serial, ask, multiple = "no") {
	const title = _("Stop Preclear");

	/* If confirmation is not required, directly stop the preclear process */
	if (ask !== "ask") {
		$.post(PreclearURL, { action: "stop_preclear", serial: serial }, function (data) {
			preclearShowResult(data);
			preclearUpdateContent();
		}, 'json').fail(() => {
			preclearShowResult(false);
		});
		return true;
	}

	/* Initialize the preclear dialog */
	const preclearDialog = $("#preclear-dialog");

	/* Handle single disk stop */
	if (multiple === "no") {
		const opts = {
			model: getDiskInfo(serial, 'MODEL'),
			serial_short: getDiskInfo(serial, 'SERIAL_SHORT'),
			firmware: getDiskInfo(serial, 'FIRMWARE'),
			size_h: getDiskInfo(serial, 'SIZE_H'),
			device: getDiskInfo(serial, 'NAME_H')
		};

		/* Populate dialog with header and details for single disk */
		const header = $("#dialog-header-defaults").html();
		preclearDialog.html(formatString(header, opts));
		preclearDialog.append("<hr style='margin-left:12px;'>");

	/* Handle multiple disks stop */
	} else {
		const header = $("#dialog-multiple-defaults").html();
		let options = "";

		/* Generate dropdown options for each disk */
		for (const key in disksInfo) {
			const disk = disksInfo[key];
			if (disk.hasOwnProperty('SERIAL_SHORT')) {
				const diskSerial = disk['SERIAL_SHORT'];
				const opts = {
					device: getDiskInfo(diskSerial, 'DEVICE'),
					model: getDiskInfo(diskSerial, 'MODEL'),
					name: getDiskInfo(diskSerial, 'NAME'),
					serial_short: diskSerial,
					size_h: getDiskInfo(diskSerial, 'SIZE_H'),
					disabled: !disk['PRECLEARING'] ? "disabled" : ""
				};
				const optionTemplate = "<option value='{serial_short}' {disabled}>{name} - {serial_short} ({size_h})</option>";
				options += formatString(optionTemplate, opts);
			}
		}

		/* Populate dialog with header and options for multiple disks */
		preclearDialog.html(formatString(header, { 0: options }));
		preclearDialog.append("<hr style='margin-left:12px;'>");
	}

	/* Display the confirmation dialog using SweetAlert2 */
	swal2({
		title: _("Stop Preclear"),
		content: { element: "div", attributes: { innerHTML: preclearDialog.html() } },
		icon: "warning",
		buttons: {
			confirm: { text: _("Stop"), value: true, visible: true, closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
		}
	}).then((answer) => {
		if (answer) {
			/* Prepare options for the AJAX request */
			const opts = { serial: [], action: "stop_preclear" };
			const popup = $(".swal-content");

			/* Add serial if provided */
			if (serial) {
				opts.serial.push(serial, 'DEVICE');
			}

			/* Handle multiple device selection */
			if (popup.find('#multiple_preclear :selected').length) {
				opts.serial = [];
				popup.find('#multiple_preclear :selected').each(function () {
					opts.serial.push(this.value);
				});
			}

			/* Send the stop request if there are valid serials */
			if (opts.serial.length > 0) {
				$.post(PreclearURL, opts, function (data) {
					preclearShowResult(data);
					preclearUpdateContent();
				}, 'json').fail(() => {
					preclearShowResult(false);
				});
			}
		}
	});
}

/*
	Function to clear all running preclear sessions, halt all processes, and remove related files.
	Displays a confirmation dialog before performing the action.
*/
function preclearClear() {
	swal2({
		title: _("Fix Preclear"),
		content: {
			element: "div",
			attributes: {
				innerHTML: `
					<p>${_("This will stop all running sessions, halt all processes and remove all related files")}</p>
					<p><span class='red-text'><b>${_("Do you want to proceed")}?</b></span></p>
				`
			}
		},
		icon: "warning",
		buttons: {
			confirm: { text: _("Fix"), value: true, visible: true, closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
		}
	}).then((answer) => {
		/* If the user confirms, send the request to clear all preclear processes */
		if (answer) {
			$.post(
				PreclearURL,
				{ action: "clear_all_preclear" },
				function (data) {
					preclearShowResult(data);
					getPreclearContent();
				},
				'json'
			).fail(function () {
				preclearShowResult(false);
			});
		}
	});
}

/*
	Function to retrieve the value of a form element by its name.
	@param el: The parent element containing the target input field.
	@param name: The name attribute of the input field to retrieve.
	@return: The value of the input field, or "on"/"off" for checkboxes.
*/
function getVal(el, name) {
	/* Locate the input field by name within the provided element */
	const target = $(el).find(`*[name=${name}]`);

	/* Handle checkbox inputs separately; otherwise, return the value */
	if (target.attr('type') === 'checkbox') {
		return target.is(':checked') ? "on" : "off";
	} else {
		return target.val();
	}
}

/*
	Function to toggle the visibility of options based on the selected value.
	@param el: The HTML element (e.g., a dropdown or input) triggering the change.
*/
function toggleSettings(el) {
	const value = $(el).val();
	const parent = $(el).parent();
	const siblings = {
		read: parent.siblings('.read_options'),
		write: parent.siblings('.write_options'),
		postread: parent.siblings('.postread_options'),
		notify: parent.siblings('.notify_options'),
		cycles: parent.siblings('.cycles_options')
	};

	/* Helper to show or hide specific options */
	const showOptions = (options) => {
		Object.keys(siblings).forEach((key) => {
			siblings[key].css('display', options.includes(key) ? 'block' : 'none');
		});
	};

	/* Determine which options to show based on the value */
	switch (value) {
		case '0':
		case '--erase-clear':
			showOptions(['read', 'write', 'postread', 'notify']);
			break;

		case '--verify':
		case '--signature':
		case '-V':
			showOptions(['read', 'postread', 'notify']);
			break;

		case '--erase':
			showOptions(['read', 'postread', 'notify', 'cycles']);
			break;

		case '-t':
		case '-z':
			showOptions([]);
			break;

		default:
			showOptions(['read', 'write', 'postread', 'notify']);
			break;
	}
}

/*
	Function to toggle the enabled state of a select element based on checkbox states.
	@param el: The triggering element (e.g., a checkbox or its parent).
	@param name: The name attribute of the select element to toggle.
*/
function toggleFrequency(el, name) {
	/* Find the target select element based on the given name */
	const selectElement = $(el).parent().parent().find(`select[name='${name}']`);

	/* Check if any sibling checkboxes or the triggering element itself are checked */
	const anyChecked = $(el)
		.siblings("*[type='checkbox']")
		.addBack()
		.is(':checked');

	/* Enable or disable the select element based on the checkbox state */
	if (anyChecked) {
		selectElement.removeAttr('disabled');
	} else {
		selectElement.attr('disabled', 'disabled');
	}
}

/*
	Function to toggle the visibility of notification options based on the selected index of an element.
	@param el: The HTML select element whose selected index determines the toggle state.
*/
function toggleNotification(el) {
	const notificationOptions = $(el).parent().siblings('.notification_options');
	const isVisible = el.selectedIndex > 0;

	/* Toggle visibility of notification options */
	notificationOptions.css('display', isVisible ? 'block' : 'none');
}

/*
	Function to retrieve specific information about a disk based on its serial number.
	@param serial: The serial number of the disk to search for.
	@param info: The specific information key to retrieve from the disk object.
	@return: The value corresponding to the requested info key, or undefined if not found.
*/
function getDiskInfo(serial, info) {
	for (const key in disksInfo) {
		const disk = disksInfo[key];

		/* Check if the disk has the matching serial number */
		if (disk?.SERIAL_SHORT === serial) {
			return disk[info];
		}
	}
}


/*
	Function to handle toggling of report rows and their icons.
	@param opened: An optional array of disk IDs that should be initially expanded.
*/
function toggleReports(opened) {
	$(".toggle-reports").each(function () {
		const elem = $(this);
		const disk = elem.attr("hdd");

		/* Disable text selection for this element */
		elem.disableSelection();

		/* Remove any existing click handlers and attach a new one */
		elem.off("click").on("click", function () {
			const icon = $(this).find(".fa-append");
			const disk = $(this).attr("hdd");

			/* Toggle the corresponding row's visibility */
			$(`.toggle-${disk}`).closest("tr.report-row").slideToggle(150, function () {
				const isVisible = $(this).css("display") !== "none";

				/* Update the icon and toggled state */
				if (isVisible) {
					icon.removeClass("fa-plus-square").addClass("fa-minus-square");
					addToggledReport(disk);
				} else {
					icon.removeClass("fa-minus-square").addClass("fa-plus-square");
					removeToggledReport(disk);
				}
			});
		});

		/* Initialize state for rows in the opened list */
		if (Array.isArray(opened) && opened.includes(disk)) {
			$(`.toggle-${disk}`).closest("tr.report-row").css("display", "table-row");
			elem.find(".fa-append").removeClass("fa-plus-square").addClass("fa-minus-square");
		}
	});
}

/*
	Function to retrieve the list of toggled reports from localStorage.
	@return: An array of toggled report disk identifiers.
*/
function getToggledReports() {
	return JSON.parse(localStorage.getItem("toggledReports") || "[]");
}

/*
	Function to add a disk to the toggled reports list in localStorage.
	@param disk: The identifier of the disk to add.
*/
function addToggledReport(disk) {
	const opened = getToggledReports();
	if (!opened.includes(disk)) {
		opened.push(disk);
		localStorage.setItem("toggledReports", JSON.stringify(opened));
	}
}

/*
	Function to remove a disk from the toggled reports list in localStorage.
	@param disk: The identifier of the disk to remove.
*/
function removeToggledReport(disk) {
	const opened = getToggledReports().filter(item => item !== disk);
	localStorage.setItem("toggledReports", JSON.stringify(opened));
}

/*
	Function to remove a report and update the UI accordingly.
	@param file: The file identifier for the report to be removed.
	@param el: The DOM element associated with the report.
*/
function rmReport(file, el) {
	$.post(PreclearURL, { action: "remove_report", file: file }, function (data) {
		if (data) {
			/* Check if there are remaining siblings */
			const remainingSiblings = $(el).closest("div").siblings().length;

			/* If no siblings remain, update the icon opacity */
			if (remainingSiblings === 0) {
				$(el).closest("td").find(".fa-minus-circle, .fa-plus-circle").css("opacity", "0.0");
			}

			/* Remove the report's parent element */
			$(el).parent().remove();

			/* Show the result of the removal */
			preclearShowResult(data);
		}
	}).fail(function () {
		preclearShowResult(false);
	});
}

/*
	Function to check for a resumable preclear session for a given disk serial.
	@param serial: The serial number of the disk.
*/
function getResumablePreclear(serial) {
	$.post(PreclearURL, { action: "get_resumable", serial: serial }, function (data) {
		if (data.resume) {
			/* Display a prompt to resume or start a new preclear session */
			swal2({
				title: _("Resume Preclear"),
				content: {
					element: "div",
					attributes: {
						innerHTML: `
							<p>${_("There is a previous preclear session available for this drive.")}</p>
							<p>${_("Do you want to resume it instead of starting a new one?")}</p>
						`
					}
				},
				icon: "info",
				buttons: {
					confirm: { text: _("Resume"), value: 1, visible: true, closeModal: false },
					new: { text: _("Start New"), value: 2, visible: true, closeModal: false },
					cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
				}
			}).then((answer) => {
				if (answer == 1) {
					/* Resume preclear with saved session details */
					const opts = {
						action: "start_preclear",
						serial: serial,
						device: [getDiskInfo(serial, "DEVICE")],
						op: "resume",
						file: data.resume,
						scope: "gfjardim"
					};

					$.post(PreclearURL, opts)
						.done(function (response) {
							preclearShowResult(response);
							preclearUpdateContent();
						})
						.fail(function () {
							preclearShowResult(false);
						});
				} else if (answer == 2) {
					/* Start a new preclear session */
					swal2.stopLoading();
					startPreclear(serial);
				}
			});
		} else {
			/* No resumable session; start a new preclear session */
			startPreclear(serial);
		}
	}, "json").fail(function () {
		preclearShowResult(false);
	});
}

/*
	Function to set the queue limit for preclear sessions.
*/
function setPreclearQueue() {
	$.post(PreclearURL, { action: "get_queue" }, function (data) {
		const preclearDialog = $("#preclear-dialog");
		preclearDialog.html("");

		const newSelect = $("#preclear-set-queue-defaults").clone();
		newSelect.find(`option[value='${data}']`).attr("selected", "selected");
		preclearDialog.append(newSelect.html());

		swal2({
			title: _("Set Queue Limit"),
			content: { element: "div", attributes: { innerHTML: preclearDialog.html() } },
			icon: "info",
			buttons: {
				confirm: { text: _("Set"), value: true, visible: true, closeModal: false },
				cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
			}
		}).then((answer) => {
			if (answer) {
				const popup = $(".swal-modal");
				const opts = {
					action: "set_queue",
					queue: getVal(popup, "queue")
				};

				console.log(opts.queue);

				$.post(PreclearURL, opts, function (data) {
					preclearShowResult(data);
					getPreclearContent();
				}, "json");
			}
		});
	});
}

/*
	Function to resume a preclear session for a specific disk.
	@param disk: The identifier of the disk to resume preclear.
*/
function resumePreclear(disk) {
	$.post(PreclearURL, { action: "resume_preclear", disk: disk }, function (data) {
		preclearShowResult(data);
		getPreclearContent();
	}).fail(function () {
		preclearShowResult(false);
	});
}

/*
	Function to pause all running preclear sessions.
*/
function preclearPauseAll() {
	swal2({
		title: _("Pause All Preclear Sessions"),
		content: {
			element: "div",
			attributes: { innerHTML: `<p>${_("Do you want to pause all running preclear sessions?")}</p>` }
		},
		icon: "warning",
		buttons: {
			confirm: { text: _("Pause"), value: true, visible: true, closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
		}
	}).then((answer) => {
		if (answer) {
			$.post(PreclearURL, { action: "pause_all" }, function (data) {
				preclearShowResult(data);
				getPreclearContent();
			}, "json").fail(function () {
				preclearShowResult(false);
			});
		}
	});
}

/*
	Function to resume all paused preclear sessions.
*/
function preclearResumeAll() {
	swal2({
		title: _("Resume All Preclear Sessions"),
		content: {
			element: "div",
			attributes: { innerHTML: `<p>${_("Do you want to resume all running preclear sessions?")}</p>` }
		},
		icon: "warning",
		buttons: {
			confirm: { text: _("Resume"), value: true, visible: true, closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
		}
	}).then((answer) => {
		if (answer) {
			$.post(PreclearURL, { action: "resume_all" }, function (data) {
				preclearShowResult(data);
				getPreclearContent();
			}, "json").fail(function () {
				preclearShowResult(false);
			});
		}
	});
}

/*
	Function to display a SweetAlert2 notification based on the result of an operation.
	@param success: A boolean indicating whether the operation was successful.
*/
function preclearShowResult(success) {
	const options = success
		? createSwalOptions(_('Success') + "!", "success", " ", 2000)
		: createSwalOptions(_('Fail') + "!", "error", " ", 2000);

	swal2(options);
}

/*
	Helper function to create SweetAlert2 options.
	@param title: The title of the alert.
	@param icon: The icon type (e.g., "success" or "error").
	@param content: The content (HTML or plain text) for the alert.
	@param timer: The duration in milliseconds before the alert auto-closes.
	@return: An object with the configured SweetAlert2 options.
*/
function createSwalOptions(title, icon, content, timer) {
	return {
		title,
		icon,
		content: {
			element: "div",
			attributes: { innerHTML: content }
		},
		buttons: {
			confirm: { visible: false },
			cancel: { visible: false }
		},
		timer
	};
}

/*
	Function to stop all running preclear sessions with user confirmation.
*/
function preclearStopAll() {
	swal2({
		title: _("Stop All Preclear Sessions"),
		content: {
			element: "div",
			attributes: {
				innerHTML: `<p>${_("Do you want to stop all running preclear sessions?")}</p>`
			}
		},
		icon: "warning",
		buttons: {
			confirm: { text: _("Stop"), value: true, visible: true, closeModal: false },
			cancel: { text: _("Cancel"), value: null, visible: true, closeModal: true }
		}
	}).then((answer) => {
		if (answer) {
			/* Send request to stop all preclear sessions */
			$.post(PreclearURL, { action: "stop_all_preclear" }, function (data) {
				preclearShowResult(data);
				getPreclearContent();
			}, "json").fail(function () {
				preclearShowResult(false);
			});
		}
	});
}

/*
	Helper function for sortable rows to toggle CSS classes and set child element widths.
	@param e: The event object.
	@param i: The item being sorted.
	@return: The modified item.
*/
const preclearSortableHelper = function (e, i) {
	i.toggleClass("even odd");
	i.children().each(function () {
		$(this).width($(this).width());
	});
	return i;
};

/*
	Function to handle actions when sorting starts.
	@param e: The event object.
	@param i: The item being sorted.
*/
const preclearStartSorting = function (e, i) {
	clearTimeout(timers.getPreclearContent);

	/* Mark visible toggles as sortable and trigger click */
	$(i.item).find("div[class*=toggle-]:visible").prev().addClass("sortable_toggled");
	$(i.item).find("div[class*=toggle-]:visible").prev().trigger("click");
};

/*
	Function to handle actions when sorting stops.
	@param e: The event object.
	@param i: The item being sorted.
*/
const preclearStopSorting = function (e, i) {
	timers.getPreclearContent = setTimeout(() => getPreclearContent(), 1500);

	/* Remove the sortable toggled class and trigger click */
	$(i.item).find(".sortable_toggled").trigger("click").removeClass("sortable_toggled");
};

/*
	Function to update the sorting order and save it on the server.
	@param e: The event object.
	@param i: The item being sorted.
*/
const preclearUpdateSorting = function (e, i) {
	const devices = [];

	/* Collect the device attributes for each sortable row */
	$("#preclear-table-body").find("tr").each(function () {
		devices.push($(this).attr("device"));
	});

	/* Send the updated sort order to the server */
	$.post(PreclearURL, { action: "save_sort", devices: devices }, function (data) {
		clearTimeout(timers.getPreclearContent);
		timers.preclear = setTimeout(() => getPreclearContent(), 1500);
	});
};

/*
	Function to reset the sorting order.
*/
function preclearResetSorting() {
	$.post(PreclearURL, { action: "reset_sort" }, function (data) {
		getPreclearContent();
	});
}

/*
	Function to initialize sortable functionality on the table body.
*/
function preclearSetSorting() {
	$("#preclear-table-body").sortable({
		tolerance: "pointer",
		helper: preclearSortableHelper,
		items: "tr.sortable",
		cursor: "move",
		axis: "y",
		containment: "parent",
		cancel: "span.docker_readmore,input",
		delay: 100,
		opacity: 0.5,
		zIndex: 9999,
		update: preclearUpdateSorting,
		start: preclearStartSorting,
		stop: preclearStopSorting
	});
}

/*
	Function to update preclear content and refresh data.
*/
function preclearUpdateContent() {
	if (typeof usb_disks === "function") {
		usb_disks();
	}
	getPreclearContent();
}
