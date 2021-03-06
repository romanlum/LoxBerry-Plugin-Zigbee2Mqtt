<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(1);

$mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;
echo $twig->render('index.html', array("mqtt_installed" => $mqtt_installed));

//creates the footer
LBWeb::lbfooter();
