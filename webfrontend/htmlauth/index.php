<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once '/usr/share/php/Twig/autoload.php';
require_once LBPBINDIR . "/formHelper.php";
require_once 'model/ServiceConfig.php';

$loader = new \Twig\Loader\FilesystemLoader($lbptemplatedir);
$twig = new \Twig\Environment($loader, [
    'cache' => "$lbptemplatedir/cache",
]);

$L = LBSystem::readlanguage("language.ini");
$filter = new \Twig\TwigFilter('trans', function ($string) use ($L) {
    return $L[$string];
});
$twig->addFilter($filter);
$filter = new \Twig\TwigFilter('form', function ($object) {
    return MakeForm($object);
});
$twig->addFilter($filter);

$template_title = "Zigbee2Mqtt Plugin";
$helplink = "https://www.loxwiki.eu/";
$helptemplate = "help.html";

$htmlhead = "<script src='js/index.js'></script>";
LBWeb::lbheader($template_title, $helplink, $helptemplate);
echo $twig->render('index.html', array('serviceConfig'=> new ServiceConfig));
LBWeb::lbfooter();