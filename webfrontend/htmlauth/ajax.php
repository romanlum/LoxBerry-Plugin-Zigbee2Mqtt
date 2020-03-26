<?php

require_once "loxberry_system.php";
require_once "model/ServiceConfig.php";
require_once "model/MqttConfig.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/formHelper.php";


if (isset($_GET["action"])) {
    $action = $_GET["action"];
    if ($action == "getFormData") {
        if (isset($_GET["form"])) {
            sendresponse(200, "application/json", getFormData($_GET["form"]));
        }
    } else if ($action == "setFormData") {
        if (isset($_GET["form"])) {
            sendresponse(200, "application/json", setFormData($_GET["form"], $_POST));
        }
    } else if ($action == "applyChanges") {
        sendresponse(200, "application/json", applyChanges());
    }
}

/**
 * apply the changes
 */
function applyChanges()
{
    shell_exec("php " . LBPBINDIR . "/update-config.php");
    shell_exec("sudo systemctl restart zigbee2mqtt -q");
    return '{"result":true}';
}

/**
 * Retrievs the form data for the given form
 */
function getFormData($form)
{

    switch ($form) {
        case "ServiceConfig":
            $data = ServiceConfig::load();
            return $data->toJson();
            break;
        case "MqttConfig":
            $data = MqttConfig::load();
            return $data->toJson();
            break;
    }
    return "{}";
}

/**
 * Sets the form data
 */
function setFormData($form, $formData)
{
    $class = null;
    switch ($form) {
        case "ServiceConfig":
            $class = new ReflectionClass(ServiceConfig::class);
            break;
        case "MqttConfig":
            $class = new ReflectionClass(MqttConfig::class);
            break;

        default:
            sendresponse(400, "application/json", '{"result":false}');
            exit(1);
    }

    foreach ($formData[$class->getName()] as $name => $value) {
        if ($value == "on" || $value == "true")
            $formData[$class->getName()][$name] = true;
        if ($value == "off" || $value == "false")
            $formData[$class->getName()][$name] = false;
    }

    $data = MakeObjectFromArray($class, $formData[$class->getName()]);
    $data->save();
    return '{"result": true}';
}

function sendresponse($httpstatus, $contenttype, $response = null)
{

    $codes = array(
        200 => "OK",
        204 => "NO CONTENT",
        304 => "NOT MODIFIED",
        400 => "BAD REQUEST",
        404 => "NOT FOUND",
        405 => "METHOD NOT ALLOWED",
        500 => "INTERNAL SERVER ERROR",
        501 => "NOT IMPLEMENTED"
    );
    if (isset($_SERVER["SERVER_PROTOCOL"])) {
        header($_SERVER["SERVER_PROTOCOL"] . " $httpstatus " . $codes[$httpstatus]);
        header("Content-Type: $contenttype");
    }

    if ($response) {
        echo $response . "\n";
    }
    exit(0);
}
