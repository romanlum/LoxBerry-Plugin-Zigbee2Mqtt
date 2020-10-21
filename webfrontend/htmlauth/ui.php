<?php
require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(3);
echo $twig->render('ui.html');
//creates the footer
LBWeb::lbfooter();
