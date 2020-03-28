<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPDATADIR . "/configuration.yaml";
$deviceDataFile = LBPDATADIR . "/devices.yaml";
$mqttGatewaySubscriptionFile = LBPCONFIGDIR . "/mqtt_subscriptions.cfg";

$L = LBSystem::readlanguage("language.ini");
$navbar = array();
$htmlhead = 'htmlhead';
