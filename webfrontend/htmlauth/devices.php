<?php

require_once "loxberry_web.php";
require_once LBPBINDIR . "/defines.php";

$navbar[1]['active'] = null;
$navbar[2]['active'] = True;
$navbar[99]['active'] = null;


$L = LBSystem::readlanguage("language.ini");
$template_title = "Zigbee2Mqtt Plugin";
$helplink = "https://www.loxwiki.eu/";
$helptemplate = "help.html";

$htmlhead = "<script src='https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.7/ace.js'></script>";
LBWeb::lbheader($template_title, $helplink, $helptemplate);
$deviceData = file_get_contents($deviceDataFile);


?>

<style>
	#editor {
		/** Setting height is also important, otherwise editor wont showup**/
		height: 300px;
	}

	.mono {
		font-family: monospace;
		font-size: 110%;
		font-weight: bold;
		color: green;

	}

	#overlay {
		display: none !important;
	}


	/* Custom indentations are needed because the length of custom labels differs from
   the length of the standard labels */
	.custom-size-flipswitch.ui-flipswitch .ui-btn.ui-flipswitch-on {
		text-indent: -5.9em;
	}

	.custom-size-flipswitch.ui-flipswitch .ui-flipswitch-off {
		text-indent: 0.5em;
	}

	/* Custom widths are needed because the length of custom labels differs from
   the length of the standard labels */
	.custom-size-flipswitch.ui-flipswitch {
		width: 8.875em;
	}

	.custom-size-flipswitch.ui-flipswitch.ui-flipswitch-active {
		padding-left: 7em;
		width: 1.875em;
	}

	@media (min-width: 28em) {

		/*Repeated from rule .ui-flipswitch above*/
		.ui-field-contain>label+.custom-size-flipswitch.ui-flipswitch {
			width: 1.875em;
		}
	}
</style>

<!-- Form devices -->
<form id="form" onsubmit="return false;">
	<div class="wide">Zigbee2Mqtt</div>
	<p><i><?= $L["DEVICES.DESC"] ?></i></p>
	<div data-role="fieldcontain">
		<div id="editor"><?= $deviceData ?></div>
	</div>
</form>
<!-- End of form -->
<hr>

<div style="display:flex;align-items:center;justify-content:center;height:16px;min-height:16px">
	<span id="savemessages"></span>
</div>
<div style="display:flex;align-items:center;justify-content:center;">
	<button class="ui-btn ui-btn-icon-right" id="saveapply" data-inline="true"><?= $L["COMMON.SAVEAPPLY"] ?></button>
</div>

<?php
LBWeb::lbfooter();
?>

<!-- JAVASCRIPT -->

<script>
	$(document).ready(function() {

		var editor = ace.edit("editor");
		editor.setTheme("ace/theme/chrome");
		editor.getSession().setMode("ace/mode/yaml");

		$("#saveapply").click(function() {
			saveapply();
		});
		$(".button_templates").click(function() {
			template = $(this).data("vitemplate");
			console.log("VI Template", template);
			saveapply(action = "template", template);
		});

		$("#saveapply").blur(function() {
			$("#savemessages").html("");
		});


	});



	function saveapply(action = "save", template = "") {
		$("#savemessages").html("Submitting...");
		$("#savemessages").css("color", "grey");

		var editor = ace.edit("editor");
		data = editor.getValue();

		$.ajax({
			type: "POST",
			contentType: "text/plain",
			url: "ajax-handler.php?action=savedevicedata",
			dataType: "text",
			data: data,

			success: function(responseData, text) {
				console.log("Done:", responseData);
				$("#savemessages").html("Saved successfully");
				$("#savemessages").css("color", "green");

			},
			error: function(error, textStatus, errorThrown) {
				console.log("Fail:", error, textStatus, errorThrown);
				$("#savemessages").html("Error " + error.status + ": " + JSON.parse(error.responseText).error);
				$("#savemessages").css("color", "red");

			}


		});

	}
</script>