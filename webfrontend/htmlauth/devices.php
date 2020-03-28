<?php
require_once 'include/plugin.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(2);

$deviceData = file_get_contents($deviceDataFile);
echo $twig->render('devices.html', array("deviceData" => $deviceData));

//creates the footer
LBWeb::lbfooter();
