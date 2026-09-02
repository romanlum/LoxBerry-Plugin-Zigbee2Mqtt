<?php
require_once "loxberry_system.php";
require_once "include/Z2mProxy.php";

// Dedicated endpoint for Zigbee2MQTT frontend assets.  Keeping this separate
// from ui.php avoids Apache/PATH_INFO handling and makes the browser request
// an existing PHP file for every Vite bundle.
$_GET['_z2m_path'] = isset($_GET['path']) ? $_GET['path'] : '/';
unset($_GET['path']);

Z2mProxy::handleRequest();
