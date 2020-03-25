<?php

require_once "loxberry_web.php";
require_once LBPBINDIR . "/defines.php";

$navbar[1]['active'] = True;
$navbar[2]['active'] = null;
$navbar[99]['active'] = null;


$L = LBSystem::readlanguage("language.ini");
$template_title = "Zigbee2Mqtt Plugin";
$helplink = "https://www.loxwiki.eu/";
$helptemplate = "help.html";

LBWeb::lbheader($template_title, $helplink, $helptemplate);


// Check if MQTT Gateway is installed
$mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;


?>

<style>
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

<!-- Form SETTINGS -->
<form id="form" onsubmit="return false;">

	<div class="wide">Zigbee2Mqtt</div>
	<p><i><?= $L["SERVICE.DESC"] ?></i></p>

	<!-- Service -->
	<div class="lb_flex-container">
		<div class="lb_flex-item-label">
			<label class="control-label" for="PermitJoin"><?= $L["SERVICE.LABEL_PermitJoin"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input type="checkbox" name="SERVICE.permitJoin" id="PermitJoin">
			</fieldset>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["SERVICE.HINT_PermitJoin"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<div class="lb_flex-container">
		<div class="lb_flex-item-label">
			<label class="control-label" for="SerivcePort"><?= $L["SERVICE.LABEL_Port"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="ServicePort" name="SERVICE.port" type="text" class="textfield">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["SERVICE.HINT_Port"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>



	<div class="wide">MQTT</div>
	<p><i>Du kannst auswählen, ob das MQTT Gateway Plugin verwendet werden soll, oder du einen eigenen MQTT Broker angeben möchtest.</i></p>

	<!-- MQTT -->
	<div class="lb_flex-container">
		<div class="lb_flex-item-label">
			<label class="control-label" for="MQTTTopic"><?= $L["MQTT.LABEL_MQTTTOPIC"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="MQTTTopic" name="MQTT.topic" type="text" class="textfield" data-validation-rule="^(?!.*//.*)[^\+#]+$" data-validation-error-msg="<?= $L["MQTT.MSG_VALINVALID_MQTTTOPIC"] ?>">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["MQTT.HINT_MQTTTOPIC"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<?php

	if ($mqtt_installed) {

	?>
		<div class="lb_flex-container">
			<div class="lb_flex-item-label">
				<label class="control-label" for="MQTTUseMQTTGateway"><?= $L["MQTT.LABEL_MQTTUseMQTTGateway"] ?></label>
			</div>
			<div class="lb_flex-item-spacer"></div>
			<div class="lb_flex-item">
				<input type="checkbox" name="MQTT.usemqttgateway" id="MQTTUseMQTTGateway">
				</fieldset>
			</div>
			<div class="lb_flex-item-spacer"></div>
			<div class="lb_flex-item-help hint">
				<?= $L["MQTT.HINT_MQTTUseMQTTGateway"] ?>
			</div>
			<div class="lb_flex-item-spacer"></div>
		</div>

		<div class="lb_flex-container MQTT">
			<div class="lb_flex-item-label">
			</div>
			<div class="lb_flex-item-spacer"></div>
			<div class="lb_flex-item">
				<span id="mqtt_onverviewlink"><a href="/admin/plugins/mqttgateway/index.cgi?form=topics" target="_blank">MQTT Gateway Incoming Overview</a></span>
			</div>
			<div class="lb_flex-item-spacer"></div>
			<div class="lb_flex-item-help hint">
			</div>
			<div class="lb_flex-item-spacer"></div>
		</div>

	<?php
	} else {
	?>

		<div class="lb_flex-container">
			Das MQTT Gateway ist nicht installiert.
		</div>

	<?php

	}

	?>

	<div class="lb_flex-container MQTT ownbroker" style="display:none">
		<div class="lb_flex-item-label">
			<label class="control-label" for="BrokerUsername"><?= $L["MQTT.LABEL_BROKERSERVER"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="BrokerServer" name="MQTT.server" type="text" class="textfield" data-validation-rule="special:hostname_or_ipaddr" data-validation-error-msg="<?= $L["MQTT.MSG_VALINVALID_BROKERSERVER"] ?>">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["MQTT.HINT_BROKERSERVER"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<div class="lb_flex-container MQTT ownbroker" style="display:none">
		<div class="lb_flex-item-label">
			<label class="control-label" for="BrokerUsername"><?= $L["MQTT.LABEL_BROKERPORT"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="BrokerPort" name="MQTT.port" type="text" class="textfield" data-validation-rule="special:number-min-max-value:1:65000" data-validation-error-msg="<?= $L["MQTT.MSG_VALINVALID_BROKERPORT"] ?>">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["MQTT.HINT_BROKERPORT"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<div class="lb_flex-container MQTT ownbroker" style="display:none">
		<div class="lb_flex-item-label">
			<label class="control-label" for="BrokerUsername"><?= $L["MQTT.LABEL_BROKERUSERNAME"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="BrokerUsername" name="MQTT.username" type="text" class="textfield">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["MQTT.HINT_BROKERUSERNAME"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<div class="lb_flex-container MQTT ownbroker" style="display:none">
		<div class="lb_flex-item-label">
			<label class="control-label" for="BrokerPassword"><?= $L["MQTT.LABEL_BROKERPASSWORD"] ?></label>
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item">
			<input width="100%" value="" id="BrokerPassword" name="MQTT.password" type="text" class="textfield">
		</div>
		<div class="lb_flex-item-spacer"></div>
		<div class="lb_flex-item-help hint">
			<?= $L["MQTT.HINT_BROKERPASSWORD"] ?>
		</div>
		<div class="lb_flex-item-spacer"></div>
	</div>

	<div style="padding: 0px 0px 20px 0px;"></div>


	<!-- MQTT End -->



</form>
<!-- End of form -->
<hr>

<div style="display:flex;align-items:center;justify-content:center;height:16px;min-height:16px">
	<span id="savemessages"></span>
</div>
<div style="display:flex;align-items:center;justify-content:center;">
	<button class="ui-btn ui-btn-icon-right" id="saveapply" data-inline="true"><?= $L["COMMON.SAVEAPPLY"] ?></button>
</div>

<div id="jsonconfig" style="display:none">
	<?php
	$configjson = file_get_contents($configfile);
	if (!empty($configjson) or !empty(json_decode($configjson))) {
		echo $configjson;
	} else {
		echo "{}";
	}
	?>
</div>
<div id="mqttconfig" style="display:none">
	<?php
	$configmqtt = file_get_contents($mqttconfigfile);
	if (!empty($configmqtt) or !empty(json_decode($configmqtt))) {
		echo $configmqtt;
	} else {
		echo "{}";
	}
	?>
</div>


<?php
LBWeb::lbfooter();
?>

<!-- JAVASCRIPT -->

<script>
	var config;

	$(document).ready(function() {

		config = JSON.parse($("#jsonconfig").text());
		mqttconfig = JSON.parse($("#mqttconfig").text());

		formFill();
		viewhide();


		$("#MQTTUseMQTTGateway").click(function() {
			viewhide();
		});
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


	function viewhide() {
		if ($("#MQTTUseMQTTGateway").is(":checked")) {
			$(".ownbroker").fadeOut();
		} else {
			$(".ownbroker").fadeIn();
		}

	}

	function formFill() {

		if (typeof mqttconfig !== 'undefined') {
			if (typeof mqttconfig.usemqttgateway !== 'undefined') {
				if (mqttconfig.usemqttgateway == 'true' || mqttconfig.usemqttgateway == 'on')
					$("#MQTTUseMQTTGateway").prop('checked', mqttconfig.usemqttgateway).checkboxradio('refresh');
			}
			if (typeof mqttconfig.topic !== 'undefined') $("#MQTTTopic").val(mqttconfig.topic);
			if (typeof mqttconfig.server !== 'undefined') $("#BrokerServer").val(mqttconfig.server);
			if (typeof mqttconfig.port !== 'undefined') $("#BrokerPort").val(mqttconfig.port);
			if (typeof mqttconfig.username !== 'undefined') $("#BrokerUsername").val(mqttconfig.username);
			if (typeof mqttconfig.password !== 'undefined') $("#BrokerPassword").val(mqttconfig.password);


		}

		if (typeof config != 'undefined') {
			if (typeof config.permitJoin !== 'undefined') {
				if (config.permitJoin == true || config.permitJoin == 'on')
					$("#PermitJoin").prop('checked', config.permitJoin).checkboxradio('refresh');
			}
			if (typeof config.port !== 'undefined') $("#ServicePort").val(config.port);
		}


	}

	function saveapply(action = "save", template = "") {
		$("#savemessages").html("Submitting...");
		$("#savemessages").css("color", "grey");

		//
		values = $("#form").serializeArray();

		/* Because serializeArray() ignores unset checkboxes and radio buttons: */
		values = values.concat(
			$('#form input[type=checkbox]:not(:checked)').map(
				function() {
					// console.log("Checkbox", this.name, "Value", this.value);
					return {
						"name": this.name,
						"value": "off"
					}
				}).get()
		);

		//$.post( "ajax-handler.php?action=saveconfig", $( "#form" ).serialize() )
		$.post("ajax-handler.php?action=saveconfig", values)
			.done(function(data) {
				console.log("Done:", data);
				$("#savemessages").html("Saved successfully");
				$("#savemessages").css("color", "green");

				console.log("Response data", data);
				config = data.CONFIG;
				mqttconfig = data.MQTT;

				formFill();

			})
			.fail(function(error, textStatus, errorThrown) {
				console.log("Fail:", error, textStatus, errorThrown);
				$("#savemessages").html("Error " + error.status + ": " + error.responseJSON.error);
				$("#savemessages").css("color", "red");

			});
	}
</script>