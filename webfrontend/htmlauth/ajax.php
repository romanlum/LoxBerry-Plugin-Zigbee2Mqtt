<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "model/ServiceConfig.php";
require_once "model/MqttConfig.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/formHelper.php";

$log = LBLog::newLog(["name" => "Service"]);

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
    } else if ($action == "setDevices") {
        sendresponse(200, "application/json", setDevices($_POST));
    } else if ($action == "applyChanges") {
        sendresponse(200, "application/json", applyChanges());
    } else if ($action == "getPid") {
        sendresponse(200, "application/json", getPid());
    } else if ($action == "testPort") {
        sendresponse(200, "application/json", testPort(isset($_POST["port"]) ? $_POST["port"] : ""));
    }
}

/**
 * apply the changes
 */
function applyChanges()
{

    LOGSTART("Restart zigbee2mqtt service");
    shell_exec("php " . LBPBINDIR . "/update-config.php");
    shell_exec("sudo systemctl restart zigbee2mqtt -q");
    LOGOK("Restart ok");
    LOGEND("Restarted zigbee2mqtt service");


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

/**
 * Sets the device data
 */
function setDevices($formData)
{
    global $deviceDataFile;

    LOGSTART("Update device configuration");

    $data = file_get_contents('php://input');
    if (yaml_parse($data) == FALSE) {
        LOGERR("Sent device configuration invalid was invalid");
        LOGEND("Update failed");
        sendresponse(400, "application/json", '{ "error" : "Configuration not valid." }');
        exit(1);
    }

    $file = fopen($deviceDataFile, "w");
    fwrite($file, $data);
    fclose($file);
    LOGOK("Update OK");
    LOGEND("Update finished");
    sendresponse(200, "text/plain", $data);
}

/**
 * Gets the pid of the zigbee2mqtt service
 */
function getPid()
{
    //fetches the pid or 0 if not running
    $pid = shell_exec("systemctl show --property MainPID --value zigbee2mqtt");
    return "{\"pid\":$pid }";
}

/**
 * Checks the coordinator port without touching the service:
 *   tcp://host:port  - resolves the name and opens a TCP connection (3 s)
 *   /dev/...         - checks that the device exists and is readable
 * Only these two forms are accepted; nothing is passed to a shell.
 */
function testPort($port)
{
    $port = trim((string) $port);
    if ($port === "") {
        return json_encode(["result" => false, "message" => "empty"]);
    }
    if (preg_match('#^tcp://([A-Za-z0-9.\-]+):([0-9]{1,5})$#', $port, $m)) {
        $tcpPort = (int) $m[2];
        if ($tcpPort < 1 || $tcpPort > 65535) {
            return json_encode(["result" => false, "message" => "invalid"]);
        }
        $ip = gethostbyname($m[1]);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return json_encode(["result" => false, "message" => "unresolved"]);
        }
        $fp = @fsockopen($ip, $tcpPort, $errno, $errstr, 3);
        if ($fp === false) {
            return json_encode(["result" => false, "message" => "unreachable", "ip" => $ip]);
        }
        fclose($fp);
        return json_encode(["result" => true, "message" => "reachable", "ip" => $ip]);
    }
    if (preg_match('#^/dev/[A-Za-z0-9/_.:\-]+$#', $port)) {
        if (!file_exists($port)) {
            return json_encode(["result" => false, "message" => "missing"]);
        }
        return json_encode(is_readable($port)
            ? ["result" => true, "message" => "present"]
            : ["result" => false, "message" => "noaccess"]);
    }
    return json_encode(["result" => false, "message" => "invalid"]);
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
