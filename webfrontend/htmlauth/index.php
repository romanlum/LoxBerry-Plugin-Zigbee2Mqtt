<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once 'include/zigbeeService.php';


$twig = Plugin::initializeTwig();

$zigbeeService = new ZigbeeService();
$z2mVersion= $zigbeeService->getVersion();

// Include header and set page as active
Plugin::createHeader(1);

$mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;
echo $twig->render('index.html', array("mqtt_installed" => $mqtt_installed, "z2mVersion" => $z2mVersion));

//creates the footer
LBWeb::lbfooter();
