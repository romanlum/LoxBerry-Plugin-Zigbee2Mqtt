<?php
require_once 'include/plugin.php';
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "model/MqttConfig.php";
require_once "phpMQTT/phpMQTT.php";

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(3);

#load mqtt settings
$mqttcfg = MqttConfig::load();
//MQTT parameter
if (is_enabled($mqttcfg->usemqttgateway)) {
    $creds = mqtt_connectiondetails();
} else {
    $creds['brokerhost'] = $mqttcfg->server;
    $creds['brokerport'] = $mqttcfg->port;
    $creds['brokeruser'] = $mqttcfg->username;
    $creds['brokerpass'] = $mqttcfg->password;
}

# Load device map

$deviceMapData = null;
$error = false;
$client_id = uniqid(gethostname()."_client");
$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
if( $mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'] ) ) {

    $topics["$mqttcfg->topic/bridge/networkmap/graphviz"] = array('qos' => 0, 'function' => 'messageHandler');
    $mqtt->subscribe($topics, 0);
    $mqtt->publish("$mqttcfg->topic/bridge/networkmap/routes", "graphviz", 0, 1);
    
    while($mqtt->proc()) {
        if($deviceMapData != null)
            break;
    }
    $mqtt->close();

    
} else {
    $error = true;
}

//Message handlder
function messageHandler($topic, $msg){
    global $deviceMapData;

    $deviceMapData = $msg;
}

echo $twig->render('map.html', array("deviceMapData" => $deviceMapData,"error" => $error));

//creates the footer
LBWeb::lbfooter();
