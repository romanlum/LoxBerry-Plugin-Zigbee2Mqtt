<?php
require_once '/usr/share/php/Twig/autoload.php';

require_once 'include/plugin.php';
require_once 'model/ServiceConfig.php';
require_once 'model/MqttConfig.php';

$loader = new \Twig\Loader\FilesystemLoader($lbptemplatedir);
$twig = new \Twig\Environment($loader, [
    'cache' => "$lbptemplatedir/cache",
]);

$filter = new \Twig\TwigFilter('trans', function ($string) use ($L) {
    return $L[$string];
});
$twig->addFilter($filter);

// Include header and set page as active
Plugin::createHeader(1);

$mqtt_installed = LBSystem::plugindata('mqttgateway') ? true : false;
echo $twig->render('index.html', array("mqtt_installed" => $mqtt_installed));

//creates the footer
LBWeb::lbfooter();
