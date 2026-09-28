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
    } else if ($action == "permitJoin") {
        sendresponse(200, "application/json", permitJoin(isset($_POST["time"]) ? $_POST["time"] : ""));
    } else if ($action == "applyChanges") {
        sendresponse(200, "application/json", applyChanges());
    } else if ($action == "getPid") {
        sendresponse(200, "application/json", getPid());
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
 * Opens or closes pairing at runtime.
 * zigbee2mqtt 2.x ignores permit_join in configuration.yaml, pairing is only
 * possible through the MQTT request <topic>/bridge/request/permit_join with
 * {"time": 1..254} (seconds) or {"time": 0} to close it again. The request is
 * sent with the same broker credentials that update-config.php writes into
 * configuration.yaml, and the answer of zigbee2mqtt is passed back.
 */
function permitJoin($time)
{
    $time = trim((string) $time);
    if (!preg_match('/^[0-9]{1,3}$/', $time) || (int) $time > 254) {
        return json_encode(["result" => false, "message" => "invalid"]);
    }
    $time = (int) $time;

    require_once "loxberry_io.php";
    require_once "phpMQTT/phpMQTT.php";
    $mqttcfg = MqttConfig::load();
    if (is_enabled($mqttcfg->usemqttgateway)) {
        $creds = mqtt_connectiondetails();
    } else {
        $creds = [
            "brokerhost" => $mqttcfg->server,
            "brokerport" => $mqttcfg->port,
            "brokeruser" => $mqttcfg->username,
            "brokerpass" => $mqttcfg->password
        ];
    }
    if (empty($creds["brokerhost"]) || $mqttcfg->topic === "") {
        return json_encode(["result" => false, "message" => "nobroker"]);
    }

    $mqtt = new Bluerhinos\phpMQTT($creds["brokerhost"], (int) $creds["brokerport"], "zigbee2mqtt-plugin-" . bin2hex(random_bytes(4)));
    if (!@$mqtt->connect(true, null, $creds["brokeruser"], $creds["brokerpass"])) {
        return json_encode(["result" => false, "message" => "nobroker"]);
    }

    // the transaction id lets zigbee2mqtt tag its answer, so an answer to
    // someone else's request is not taken for ours
    $transaction = bin2hex(random_bytes(6));
    $answer = null;
    $mqtt->subscribe([
        $mqttcfg->topic . "/bridge/response/permit_join" => [
            "qos" => 0,
            "function" => function ($topic, $msg) use (&$answer, $transaction) {
                $data = json_decode($msg, true);
                if (is_array($data) && isset($data["transaction"]) && $data["transaction"] === $transaction) {
                    $answer = $data;
                }
            }
        ]
    ], 0);
    $mqtt->publish($mqttcfg->topic . "/bridge/request/permit_join", json_encode(["time" => $time, "transaction" => $transaction]), 0, false);

    $until = microtime(true) + 5;
    while ($answer === null && microtime(true) < $until) {
        $mqtt->proc();
    }
    $mqtt->close();

    if ($answer === null) {
        LOGWARN("permit_join: no answer from zigbee2mqtt within 5 s");
        return json_encode(["result" => false, "message" => "noanswer"]);
    }
    if (!isset($answer["status"]) || $answer["status"] !== "ok") {
        $error = isset($answer["error"]) ? (string) $answer["error"] : "";
        LOGWARN("permit_join refused by zigbee2mqtt: " . $error);
        return json_encode(["result" => false, "message" => "refused", "error" => $error]);
    }
    LOGINF("permit_join set to $time s");
    return json_encode(["result" => true, "message" => $time > 0 ? "open" : "closed", "time" => $time]);
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
