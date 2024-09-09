<?php
require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "loxberry_io.php";
require_once LBPBINDIR . "/defines.php";


$log = LBLog::newLog(["name" => "Service"]);

LOGSTART("Update configuration");


$mqttcfg = json_decode(file_get_contents($mqttconfigfile));
$serviceCfg = json_decode(file_get_contents($configfile));

$zigbee2mqttConfig = yaml_parse_file($serviceConfigFile);

############ handle upgrade from previous version  ##################

//registerMqttTopic added in 0.8.0 ==> defaults to true to be backwards compatible
if (!property_exists($mqttcfg, 'registerMqttTopic')) {
    $mqttcfg->registerMqttTopic = true;
    file_put_contents($mqttconfigfile, json_encode($mqttcfg));
}

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt.log";
$zigbee2mqttConfig["advanced"]["log_output"][0] = "console";
$zigbee2mqttConfig["advanced"]["log_output"][1] = "file";
$zigbee2mqttConfig["experimental"]["output"] = "json";
$zigbee2mqttConfig["devices"] = "devices.yaml";
$zigbee2mqttConfig["groups"] = "groups.yaml";



//MQTT parameter
if (is_enabled($mqttcfg->usemqttgateway)) {
    $creds = mqtt_connectiondetails();

    if (is_enabled($mqttcfg->registerMqttTopic)) {
        file_put_contents($mqttGatewaySubscriptionFile, $mqttcfg->topic . "/#");
    } else {
        file_put_contents($mqttGatewaySubscriptionFile, "");
    }
} else {
    $creds['brokerhost'] = $mqttcfg->server;
    $creds['brokerport'] = $mqttcfg->port;
    $creds['brokeruser'] = $mqttcfg->username;
    $creds['brokerpass'] = $mqttcfg->password;
    file_put_contents($mqttGatewaySubscriptionFile, "");
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

if (is_enabled($serviceCfg->enableUI)) {
    $zigbee2mqttConfig["frontend"]["port"] = 8881;
    $zigbee2mqttConfig["experimental"]["new_api"] = true;
} else {
    $zigbee2mqttConfig["frontend"] = false;
    $zigbee2mqttConfig["experimental"]["new_api"] = false;
}

if ($serviceCfg->adapter != "") {
    $zigbee2mqttConfig["serial"]["adapter"] = $serviceCfg->adapter;
}

//save zigbee2mqtt config
yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);

// if the adapter is empty, use the current value from the zigbee2mqtt config
if ($serviceCfg->adapter == "") {
    $serviceCfg->adapter = $zigbee2mqttConfig["serial"]["adapter"];
    $serviceCfg->save();
}

LOGOK("Update successful");
LOGEND("Update configuration finished");;
