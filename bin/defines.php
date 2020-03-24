<?php

$mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
$configfile = LBPCONFIGDIR . "/service.json";
$serviceConfigFile = LBPDATADIR . "/configuration.yaml";
$deviceDataFile = LBPDATADIR . "/devices.yaml";
// The Navigation Bar
$navbar[1]['Name'] = "Einstellungen";
$navbar[1]['URL'] = 'index.php';

$navbar[2]['Name'] = "Geräte";
$navbar[2]['URL'] = 'devices.php';

$navbar[99]['Name'] = "Logfiles";
$navbar[99]['URL'] = '/admin/system/logmanager.cgi?package=' . LBPPLUGINDIR;
$navbar[99]['target'] = '_blank';
