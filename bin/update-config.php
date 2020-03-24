<?php
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once LBPBINDIR . "/defines.php";

$mqttcfg = json_decode(file_get_contents($mqttconfigfile));
$serviceCfg = json_decode(file_get_contents($configfile));

$zigbee2mqttConfig = yaml_parse_file($serviceConfigFile);

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt_%TIMESTAMP%.log";
$zigbee2mqttConfig["advanced"]["log_output"][0] = "console";
$zigbee2mqttConfig["advanced"]["log_output"][1] = "file";
$zigbee2mqttConfig["experimental"]["output"] = "json";
$zigbee2mqttConfig["devices"] = "devices.yaml";
$zigbee2mqttConfig["groups"] = "groups.yaml";


//MQTT parameter
if (is_enabled($mqttcfg->usemqttgateway)) {
    $creds = mqtt_connectiondetails();
} else {
    $creds['brokerhost'] = $mqttcfg->server;
    $creds['brokerport'] = $mqttcfg->port;
    $creds['brokeruser'] = $mqttcfg->username;
    $creds['brokerpass'] = $mqttcfg->password;
}

$zigbee2mqttConfig["mqtt"]["base_topic"] = $mqttcfg->topic;
$zigbee2mqttConfig["mqtt"]["server"] = "mqtt://" . $creds['brokerhost'] . ":" . $creds['brokerport'];
$zigbee2mqttConfig["mqtt"]["user"] = $creds['brokeruser'];
$zigbee2mqttConfig["mqtt"]["password"] = $creds['brokerpass'];


//customizable parameters
if ($serviceCfg->port != "") {
    $zigbee2mqttConfig["serial"]["port"] = $serviceCfg->port;
} else {
    $zigbee2mqttConfig["serial"]["port"] = null;
}
$zigbee2mqttConfig["permit_join"] = $serviceCfg->permitJoin;


yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);
