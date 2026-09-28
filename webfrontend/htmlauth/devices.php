<?php
require_once 'include/plugin.php';
require_once 'include/Z2mBridge.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(2);

$deviceData = file_get_contents($deviceDataFile);
$list = deviceList(dirname($serviceConfigFile) . "/state.json");
echo $twig->render('devices.html', array(
    "deviceData" => $deviceData,
    "deviceList" => $list["devices"],
    "deviceListError" => $list["error"],
    "stateTime" => $list["stateTime"],
    "showLastSeen" => $list["showLastSeen"]
));

//creates the footer
LBWeb::lbfooter();

/**
 * All paired devices, from the retained bridge/devices of zigbee2mqtt, with
 * the last values from its state cache (state.json). Read only.
 * error: "" / "nobroker" / "noanswer"
 */
function deviceList($stateFile)
{
    $result = array("devices" => array(), "error" => "", "stateTime" => "", "showLastSeen" => false);
    $bridge = new Z2mBridge();
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

    $cache = array();
    if (is_readable($stateFile)) {
        $cache = json_decode((string) file_get_contents($stateFile), true);
        $cache = is_array($cache) ? $cache : array();
        $result["stateTime"] = date("d.m.Y H:i", filemtime($stateFile));
    }

    foreach ($data["bridge/devices"] as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator") {
            continue;
        }
        $definition = isset($device["definition"]) && is_array($device["definition"]) ? $device["definition"] : array();
        $units = array();
        foreach (exposedFeatures(isset($definition["exposes"]) ? $definition["exposes"] : array()) as $feature) {
            if (isset($feature["property"])) {
                $units[$feature["property"]] = isset($feature["unit"]) ? $feature["unit"] : "";
            }
        }
        $state = isset($cache[$device["ieee_address"]]) && is_array($cache[$device["ieee_address"]]) ? $cache[$device["ieee_address"]] : array();
        $values = array();
        foreach ($state as $property => $value) {
            if ($property === "last_seen" || is_array($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? "true" : "false";
            }
            $values[] = $property . " " . $value . (isset($units[$property]) && $units[$property] !== "" ? " " . $units[$property] : "");
        }
        if (isset($state["last_seen"])) {
            $result["showLastSeen"] = true;
        }
        $result["devices"][] = array(
            "name" => isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"],
            "ieee" => $device["ieee_address"],
            "model" => trim((isset($definition["vendor"]) ? $definition["vendor"] . " " : "") . (isset($definition["model"]) ? $definition["model"] : (isset($device["model_id"]) ? $device["model_id"] : ""))),
            "description" => isset($definition["description"]) ? $definition["description"] : "",
            "type" => $device["type"],
            "power" => isset($device["power_source"]) ? $device["power_source"] : "",
            "interview" => isset($device["interview_state"]) ? $device["interview_state"] : (!empty($device["interview_completed"]) ? "SUCCESSFUL" : ""),
            "supported" => !isset($device["supported"]) || $device["supported"],
            "disabled" => !empty($device["disabled"]),
            "values" => implode(", ", $values),
            "lastSeen" => isset($state["last_seen"]) ? (string) $state["last_seen"] : ""
        );
    }
    usort($result["devices"], function ($a, $b) {
        return strcasecmp($a["name"], $b["name"]);
    });
    return $result;
}

/**
 * Flattens the exposes of a definition: composite features (e.g. "color")
 * and specific ones (e.g. "light") carry their properties in "features".
 */
function exposedFeatures($exposes)
{
    $list = array();
    foreach ($exposes as $expose) {
        if (isset($expose["features"]) && is_array($expose["features"])) {
            $list = array_merge($list, exposedFeatures($expose["features"]));
        } else {
            $list[] = $expose;
        }
    }
    return $list;
}
