<?php
require_once 'include/plugin.php';
require_once 'include/Z2mBridge.php';

$info = loxoneInputs(dirname($serviceConfigFile) . "/state.json");

// ?download=1: import template for Loxone Config, answered before the LoxBerry header
if (isset($_GET["download"]) && $info["error"] === "" && $info["inputs"]) {
    $xml = loxoneTemplate($info["inputs"]);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="VI_zigbee2mqtt.xml"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit(0);
}

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(6);

echo $twig->render('loxone.html', array(
    "gateway" => gatewayInfo(),
    "topic" => $info["topic"],
    "inputs" => $info["inputs"],
    "skipped" => $info["skipped"],
    "noState" => $info["noState"],
    "error" => $info["error"]
));

//creates the footer
LBWeb::lbfooter();

/**
 * Version and mode of the MQTT gateway. Version 1 forwards subscribed topics
 * and names the Miniserver inputs itself; version 2 has its own subscription
 * page where data points are ticked.
 */
function gatewayInfo()
{
    global $mqttGatewaySubscriptionFile;
    $mqttcfg = MqttConfig::load();
    $general = json_decode((string) @file_get_contents(LBSCONFIGDIR . "/general.json"), true);
    $gateway = json_decode((string) @file_get_contents(LBSCONFIGDIR . "/mqttgateway.json"), true);
    $main = isset($gateway["Main"]) && is_array($gateway["Main"]) ? $gateway["Main"] : array();
    $subscriptions = array();
    if (is_readable($mqttGatewaySubscriptionFile)) {
        foreach (preg_split('/\r?\n/', (string) file_get_contents($mqttGatewaySubscriptionFile)) as $line) {
            if (trim($line) !== "") {
                $subscriptions[] = trim($line);
            }
        }
    }
    return array(
        "used" => is_enabled($mqttcfg->usemqttgateway),
        "version" => isset($general["Mqtt"]["Gatewayversion"]) ? (int) $general["Mqtt"]["Gatewayversion"] : 1,
        "udp" => isset($main["use_udp"]) && is_enabled($main["use_udp"]) && !(isset($main["use_http"]) && is_enabled($main["use_http"])),
        "register" => is_enabled($mqttcfg->registerMqttTopic),
        "subscriptions" => $subscriptions
    );
}

/**
 * The virtual inputs the MQTT gateway (version 1, HTTP) feeds: with
 * expand_json the payload of <topic>/<device> is split per property and the
 * input is called <topic>_<device>_<property>, with "/" and "%" turned
 * into "_". Only numeric properties and true/false switches get an input;
 * a text on an analog input would always show 0.
 * Which properties: those the device has sent (state cache) plus
 * linkquality, which every message carries but zigbee2mqtt does not cache.
 * A device that has never sent anything gets all its properties.
 */
function loxoneInputs($stateFile)
{
    $result = array("topic" => "", "inputs" => array(), "skipped" => 0, "noState" => array(), "error" => "");
    $bridge = new Z2mBridge();
    $result["topic"] = $bridge->topic;
    if (!$bridge->connect()) {
        $result["error"] = "nobroker";
        return $result;
    }
    $data = $bridge->retained(array("bridge/devices"), 3.0);
    $bridge->close();
    if (!isset($data["bridge/devices"]) || !is_array($data["bridge/devices"])) {
        $result["error"] = "noanswer";
        return $result;
    }
    $cache = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : array();
    $cache = is_array($cache) ? $cache : array();

    foreach ($data["bridge/devices"] as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator" || !empty($device["disabled"])) {
            continue;
        }
        $name = isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"];
        $exposes = isset($device["definition"]["exposes"]) ? topLevelFeatures($device["definition"]["exposes"]) : array();
        $state = isset($cache[$device["ieee_address"]]) && is_array($cache[$device["ieee_address"]]) ? $cache[$device["ieee_address"]] : null;
        if ($state === null) {
            $result["noState"][] = $name;
        }
        foreach ($exposes as $feature) {
            $property = $feature["property"];
            if ($state !== null && !array_key_exists($property, $state) && $property !== "linkquality") {
                continue;
            }
            $numeric = $feature["type"] === "numeric";
            $binary = $feature["type"] === "binary" && isset($feature["value_on"]) && $feature["value_on"] === true;
            if (!$numeric && !$binary) {
                $result["skipped"]++;
                continue;
            }
            $result["inputs"][] = array(
                "device" => $name,
                "property" => $property,
                "title" => str_replace(array("/", "%"), "_", $bridge->topic . "/" . $name . "/" . $property),
                "unit" => isset($feature["unit"]) ? (string) $feature["unit"] : "",
                "description" => isset($feature["description"]) ? (string) $feature["description"] : "",
                "min" => $binary ? 0 : (isset($feature["value_min"]) && is_numeric($feature["value_min"]) ? $feature["value_min"] : -1000000),
                "max" => $binary ? 1 : (isset($feature["value_max"]) && is_numeric($feature["value_max"]) ? $feature["value_max"] : 1000000)
            );
        }
    }
    return $result;
}

/**
 * Top level features with a property of their own. Composite features
 * (e.g. color_xy) arrive as nested JSON and are left out; the features of
 * a specific expose (e.g. "light", "climate") are published at top level
 * and are taken.
 */
function topLevelFeatures($exposes)
{
    $list = array();
    foreach ($exposes as $expose) {
        if (isset($expose["type"]) && $expose["type"] === "composite") {
            continue;
        }
        if (isset($expose["features"]) && is_array($expose["features"])) {
            $list = array_merge($list, topLevelFeatures($expose["features"]));
        } elseif (isset($expose["property"], $expose["type"])) {
            $list[] = $expose;
        }
    }
    return $list;
}

function xmlEscape($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Import template for Loxone Config. The MQTT gateway addresses plain
 * virtual inputs by their name; Loxone Config has no template format for
 * them, so the usual workaround is a virtual HTTP input with a dummy address
 * and a polling time of a week, whose commands carry the gateway names as
 * titles and an empty check text. Format as exported by Loxone Config
 * (templateType 2, CRLF, tab before the child elements).
 */
function loxoneTemplate($inputs)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="zigbee2mqtt" Comment="Inputs for the LoxBerry MQTT gateway. Importing twice creates duplicates." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($inputs as $input) {
        $unit = $input["unit"] === "" ? "<v.1>" : "<v.1> " . $input["unit"];
        $o .= "\t" . '<VirtualInHttpCmd '
            . 'Title="' . xmlEscape($input["title"]) . '" '
            . 'Comment="' . xmlEscape($input["device"] . " " . $input["property"]) . '" '
            . 'Check=" " '
            . 'Signed="true" '
            . 'Analog="true" '
            . 'SourceValLow="0" '
            . 'DestValLow="0" '
            . 'SourceValHigh="100" '
            . 'DestValHigh="100" '
            . 'DefVal="0" '
            . 'MinVal="' . xmlEscape($input["min"]) . '" '
            . 'MaxVal="' . xmlEscape($input["max"]) . '" '
            . 'Unit="' . xmlEscape($unit) . '" '
            . 'HintText=""'
            . '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}
