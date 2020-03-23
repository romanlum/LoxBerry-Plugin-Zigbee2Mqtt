<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPCONFIGDIR . "/configuration.yaml";

// The Navigation Bar
$navbar[1]['Name'] = "Einstellungen";
$navbar[1]['URL'] = 'index.php';
 
$navbar[99]['Name'] = "Logfiles";
$navbar[99]['URL'] = '/admin/system/logmanager.cgi?package='.LBPPLUGINDIR;
$navbar[99]['target'] = '_blank';
 