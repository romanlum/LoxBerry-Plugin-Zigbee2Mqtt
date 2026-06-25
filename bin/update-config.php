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
if (!is_array($zigbee2mqttConfig)) {
    $zigbee2mqttConfig = [];
}

foreach (["homeassistant", "advanced", "device_options", "mqtt", "serial", "frontend"] as $section) {
    if (!isset($zigbee2mqttConfig[$section]) || !is_array($zigbee2mqttConfig[$section])) {
        $zigbee2mqttConfig[$section] = [];
    }
}

function generateFrontendAuthToken()
{
    return bin2hex(random_bytes(16));
}

function loadSecretConfig($secretConfigFile)
{
    $secretConfig = yaml_parse_file($secretConfigFile);
    if (!is_array($secretConfig)) {
        $secretConfig = [];
    }

    return $secretConfig;
}

function saveSecretConfig($secretConfigFile, $secretConfig)
{
    yaml_emit_file($secretConfigFile, $secretConfig);
}

############ handle upgrade from previous version  ##################

//registerMqttTopic added in 0.8.0 ==> defaults to true to be backwards compatible
if (!property_exists($mqttcfg, 'registerMqttTopic')) {
    $mqttcfg->registerMqttTopic = true;
    file_put_contents($mqttconfigfile, json_encode($mqttcfg));
}

if (!property_exists($serviceCfg, 'enableUISecurity')) {
    $serviceCfg->enableUISecurity = false;
    file_put_contents($configfile, json_encode($serviceCfg, JSON_PRETTY_PRINT));
}

//fixed values used by plugin
$zigbee2mqttConfig["homeassistant"]["enabled"] = false;
$zigbee2mqttConfig["advanced"]["log_directory"] = "log";
$zigbee2mqttConfig["advanced"]["log_file"] = "zigbee2mqtt.log";
$zigbee2mqttConfig["advanced"]["log_output"][0] = "console";
$zigbee2mqttConfig["advanced"]["log_output"][1] = "file";
$zigbee2mqttConfig["advanced"]["output"] = "json";
$zigbee2mqttConfig["device_options"]["empty"] = false;
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
    $zigbee2mqttConfig["frontend"]["enabled"] = true;
    $zigbee2mqttConfig["frontend"]["port"] = 8881;
    if (is_enabled($serviceCfg->enableUISecurity)) {
        $secretConfig = loadSecretConfig($secretConfigFile);
        if (
            !isset($secretConfig["auth_token"]) ||
            !is_string($secretConfig["auth_token"]) ||
            trim($secretConfig["auth_token"]) === ""
        ) {
            $secretConfig["auth_token"] = generateFrontendAuthToken();
            saveSecretConfig($secretConfigFile, $secretConfig);
        }
        $zigbee2mqttConfig["frontend"]["auth_token"] = "!secret.yaml auth_token";
    } else {
        unset($zigbee2mqttConfig["frontend"]["auth_token"]);
    }
} else {
    $zigbee2mqttConfig["frontend"]["enabled"] = false;
    unset($zigbee2mqttConfig["frontend"]["auth_token"]);
}

if ($serviceCfg->adapter != "") {
    $zigbee2mqttConfig["serial"]["adapter"] = $serviceCfg->adapter;
}

//save zigbee2mqtt config
yaml_emit_file($serviceConfigFile, $zigbee2mqttConfig);

// if the adapter is empty, use the current value from the zigbee2mqtt config
if ($serviceCfg->adapter == "" && isset($zigbee2mqttConfig["serial"]["adapter"])) {
    $serviceCfg->adapter = $zigbee2mqttConfig["serial"]["adapter"];
    file_put_contents($configfile, json_encode($serviceCfg,JSON_PRETTY_PRINT));
}

LOGOK("Update successful");
LOGEND("Update configuration finished");;
