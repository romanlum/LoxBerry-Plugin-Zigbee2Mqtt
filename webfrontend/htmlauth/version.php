<?php
require_once 'include/plugin.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(4);

echo $twig->render('version.html', array());

//creates the footer
LBWeb::lbfooter();
