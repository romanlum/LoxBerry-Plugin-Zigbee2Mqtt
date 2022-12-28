<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(1);

$mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;
//mqtt is not a plugin anymore in lb >=3
if(str_starts_with(LBSystem::lbversion,"3")){
    $mqtt_installed = true;
}
echo $twig->render('index.html', array("mqtt_installed" => $mqtt_installed));

//creates the footer
LBWeb::lbfooter();
