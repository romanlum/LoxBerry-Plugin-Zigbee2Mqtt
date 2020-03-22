<?php
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once LBPBINDIR . "/defines.php";

$mqttcfg = json_decode( file_get_contents($mqttconfigfile) );
$serviceConfig = yaml_parse_file($serviceConfigFile);


if(is_enabled($mqttcfg->usemqttgateway)) {
    $creds = mqtt_connectiondetails();
} else {
    $creds['brokerhost'] = $mqttcfg->server;
    $creds['brokerport'] = $mqttcfg->port;
    $creds['brokeruser'] = $mqttcfg->username;
    $creds['brokerpass'] = $mqttcfg->password;
}

$serviceConfig["mqtt"]["base_topic"] = $mqttcfg->topic;
$serviceConfig["mqtt"]["server"] = "mqtt://" . $creds['brokerhost'] . ":" . $creds['brokerport'];
$serviceConfig["mqtt"]["user"] = $creds['brokeruser'];
$serviceConfig["mqtt"]["password"] = $creds['brokerpass'];

yaml_emit_file($serviceConfigFile,$serviceConfig);