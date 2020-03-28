<?php
require_once 'include/plugin.php';
require_once "loxberry_log.php";
require_once "loxberry_web.php";

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(99);

$loglist_html = file_get_contents("http://localhost:" . lbwebserverport() . "/admin/system/logmanager.cgi?package=" .  urlencode($lbpplugindir) . "&header=none");
echo $twig->render('log.html', array("loglist" => $loglist_html));

//creates the footer
LBWeb::lbfooter();
