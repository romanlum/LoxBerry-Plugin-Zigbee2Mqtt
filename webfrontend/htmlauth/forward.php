<?php
require_once "loxberry_system.php";
require_once LBPBINDIR . "/defines.php";

$zigbee2mqttConfig = yaml_parse_file($serviceConfigFile);
if (!is_array($zigbee2mqttConfig)) {
    http_response_code(503);
    echo "Zigbee2MQTT configuration is not available.";
    exit;
}

$frontendConfig = $zigbee2mqttConfig["frontend"] ?? [];
$frontendPort = isset($frontendConfig["port"]) ? (int)$frontendConfig["port"] : 8881;
$frontendToken = $frontendConfig["auth_token"] ?? "";

if (is_string($frontendToken) && preg_match('/^!secret\.yaml\s+([A-Za-z0-9_.-]+)$/', trim($frontendToken), $matches)) {
    $secretConfig = yaml_parse_file($secretConfigFile);
    if (is_array($secretConfig) && isset($secretConfig[$matches[1]]) && is_string($secretConfig[$matches[1]])) {
        $frontendToken = $secretConfig[$matches[1]];
    } else {
        $frontendToken = "";
    }
}

$isHttps = !empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off";
$scheme = $isHttps ? "https" : "http";
$host = $_SERVER["HTTP_HOST"] ?? $_SERVER["SERVER_NAME"] ?? "localhost";
$host = preg_replace("/:\d+$/", "", $host);

$targetUrl = $scheme . "://" . $host . ":" . $frontendPort . "/";
if (is_string($frontendToken) && trim($frontendToken) !== "") {
    $targetUrl .= "?token=" . rawurlencode($frontendToken);
}

header("Location: " . $targetUrl, true, 302);
exit;
