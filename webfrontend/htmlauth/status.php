<?php
require_once 'include/plugin.php';
require_once 'include/Z2mBridge.php';

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(4);

echo $twig->render('status.html', array("rows" => statusRows(), "time" => date("H:i:s")));

//creates the footer
LBWeb::lbfooter();

/**
 * One line of the status table.
 * $state: ok (green), fail (red), hint (orange), info (grey)
 */
function statusRow($label, $state, $detail = "")
{
    return array("label" => $label, "state" => $state, "detail" => $detail);
}

/**
 * Collects the status. Read only: nothing is changed and no request is sent
 * to zigbee2mqtt, only its retained bridge topics are read.
 */
function statusRows()
{
    global $L, $serviceConfigFile, $mqttGatewaySubscriptionFile;
    $rows = array();

    // service
    $pid = (int) trim((string) shell_exec("systemctl show --property MainPID --value zigbee2mqtt"));
    if ($pid > 0) {
        $since = trim((string) shell_exec("systemctl show --property ActiveEnterTimestamp --value zigbee2mqtt"));
        $rows[] = statusRow("Status.Service", "ok", "PID $pid" . ($since !== "" ? ", " . sprintf($L["Status.Since"], $since) : ""));
    } else {
        $rows[] = statusRow("Status.Service", "fail", $L["Status.ServiceStopped"]);
    }

    // broker and retained bridge topics
    $bridge = new Z2mBridge();
    $data = array();
    if ($bridge->connect()) {
        $rows[] = statusRow("Status.Broker", "ok", $bridge->address());
        $data = $bridge->retained(array("bridge/state", "bridge/info", "bridge/devices"), 3.0);
        $bridge->close();
    } else {
        $rows[] = statusRow("Status.Broker", "fail", sprintf($L["Status.BrokerFail"], $bridge->address()));
    }

    $state = isset($data["bridge/state"]) ? $data["bridge/state"] : null;
    if (is_array($state) && isset($state["state"])) {
        $state = $state["state"];
    }
    if ($state === "online") {
        $rows[] = statusRow("Status.Online", "ok", $bridge->topic . "/bridge/state = online");
    } else {
        $rows[] = statusRow("Status.Online", "fail", $state === null ? $L["Status.OnlineNoAnswer"] : $bridge->topic . "/bridge/state = " . $state);
    }

    $info = isset($data["bridge/info"]) && is_array($data["bridge/info"]) ? $data["bridge/info"] : null;
    $devices = isset($data["bridge/devices"]) && is_array($data["bridge/devices"]) ? $data["bridge/devices"] : null;
    if ($info === null) {
        $rows[] = statusRow("Status.NoData", "info", $L["Status.NoDataDetail"]);
    } else {
        $rows = array_merge($rows, infoRows($info, $state === "online"));
    }
    if ($devices !== null) {
        $rows = array_merge($rows, deviceRows($devices, dirname($serviceConfigFile) . "/state.json"));
    }
    $rows[] = gatewayRow($bridge->topic, $mqttGatewaySubscriptionFile);
    return $rows;
}

/**
 * Version, coordinator, adapter and pairing from bridge/info.
 */
function infoRows($info, $online)
{
    global $L;
    $rows = array();

    $version = isset($info["version"]) ? $info["version"] : "?";
    $parts = array();
    if (isset($info["zigbee_herdsman"]["version"])) {
        $parts[] = "herdsman " . $info["zigbee_herdsman"]["version"];
    }
    if (isset($info["zigbee_herdsman_converters"]["version"])) {
        $parts[] = "converters " . $info["zigbee_herdsman_converters"]["version"];
    }
    $rows[] = statusRow("Status.Version", "info", $version . ($parts ? " (" . implode(", ", $parts) . ")" : ""));
    if (!empty($info["restart_required"])) {
        $rows[] = statusRow("Status.RestartRequired", "hint", $L["Status.RestartRequiredDetail"]);
    }

    $coordinator = isset($info["coordinator"]) ? $info["coordinator"] : array();
    $text = isset($coordinator["type"]) ? $coordinator["type"] : "?";
    if (isset($coordinator["meta"]["revision"])) {
        $text .= " " . $coordinator["meta"]["revision"];
    }
    if (isset($coordinator["ieee_address"])) {
        $text .= ", IEEE " . $coordinator["ieee_address"];
    }
    if (isset($info["network"]["channel"])) {
        $text .= ", " . sprintf($L["Status.Channel"], $info["network"]["channel"]);
    }
    $rows[] = statusRow("Status.Coordinator", "info", $text);

    $port = isset($info["config"]["serial"]["port"]) ? (string) $info["config"]["serial"]["port"] : "";
    $rows[] = adapterRow($port, $online);

    if (!empty($info["permit_join"])) {
        $end = isset($info["permit_join_end"]) ? date("H:i:s", (int) ($info["permit_join_end"] / 1000)) : "?";
        $rows[] = statusRow("Status.Pairing", "hint", sprintf($L["Status.PairingOpen"], $end));
    } else {
        $rows[] = statusRow("Status.Pairing", "ok", $L["Status.PairingClosed"]);
    }
    return $rows;
}

/**
 * Is the adapter port zigbee2mqtt uses reachable? While zigbee2mqtt is
 * online it is evidently connected, and the port is not touched: some
 * serial-over-TCP bridges accept only one client. Otherwise tcp://host:port
 * gets a TCP connect (2 s) and /dev/... is checked for presence.
 */
function adapterRow($port, $online)
{
    global $L;
    if ($port === "") {
        return statusRow("Status.Adapter", "info", $L["Status.AdapterAuto"]);
    }
    if ($online) {
        return statusRow("Status.Adapter", "ok", sprintf($L["Status.AdapterInUse"], $port));
    }
    if (preg_match('#^tcp://([A-Za-z0-9.\-]+):([0-9]{1,5})$#', $port, $m)) {
        $fp = @fsockopen($m[1], (int) $m[2], $errno, $errstr, 2);
        if ($fp === false) {
            return statusRow("Status.Adapter", "fail", sprintf($L["Status.AdapterUnreachable"], $port));
        }
        fclose($fp);
        return statusRow("Status.Adapter", "ok", sprintf($L["Status.AdapterReachable"], $port));
    }
    return file_exists($port)
        ? statusRow("Status.Adapter", "ok", sprintf($L["Status.AdapterPresent"], $port))
        : statusRow("Status.Adapter", "fail", sprintf($L["Status.AdapterMissing"], $port));
}

/**
 * Device counts from bridge/devices, battery levels from the state cache of
 * zigbee2mqtt (state.json, written by zigbee2mqtt every few minutes).
 */
function deviceRows($devices, $stateFile)
{
    global $L;
    $rows = array();
    $names = array();
    $incomplete = array();
    $unsupported = array();
    $disabled = array();
    foreach ($devices as $device) {
        if (!isset($device["type"]) || $device["type"] === "Coordinator") {
            continue;
        }
        $name = isset($device["friendly_name"]) ? $device["friendly_name"] : $device["ieee_address"];
        $names[$device["ieee_address"]] = $name;
        $interview = isset($device["interview_state"]) ? $device["interview_state"] : (!empty($device["interview_completed"]) ? "SUCCESSFUL" : "");
        if ($interview !== "SUCCESSFUL") {
            $incomplete[] = $name;
        }
        if (isset($device["supported"]) && !$device["supported"]) {
            $unsupported[] = $name;
        }
        if (!empty($device["disabled"])) {
            $disabled[] = $name;
        }
    }
    $rows[] = statusRow("Status.Devices", count($names) > 0 ? "ok" : "info", sprintf($L["Status.DevicesCount"], count($names)));
    if ($incomplete) {
        $rows[] = statusRow("Status.Interview", "hint", implode(", ", $incomplete));
    }
    if ($unsupported) {
        $rows[] = statusRow("Status.Unsupported", "hint", implode(", ", $unsupported));
    }
    if ($disabled) {
        $rows[] = statusRow("Status.Disabled", "info", implode(", ", $disabled));
    }

    $cache = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    if (!is_array($cache)) {
        return $rows;
    }
    $low = array();
    $counted = 0;
    foreach ($names as $ieee => $name) {
        if (isset($cache[$ieee]["battery"]) && is_numeric($cache[$ieee]["battery"])) {
            $counted++;
            if ($cache[$ieee]["battery"] < 25) {
                $low[] = $name . " " . $cache[$ieee]["battery"] . " %";
            }
        }
    }
    if ($counted === 0) {
        return $rows;
    }
    $rows[] = $low
        ? statusRow("Status.Battery", "hint", sprintf($L["Status.BatteryLow"], implode(", ", $low)))
        : statusRow("Status.Battery", "ok", sprintf($L["Status.BatteryOk"], $counted));
    return $rows;
}

/**
 * What the MQTT gateway needs so that values reach the Miniserver.
 * Version 1 forwards only subscribed topics, version 2 lets the user tick
 * data points in its own subscription page.
 */
function gatewayRow($topic, $subscriptionFile)
{
    global $L;
    $mqttcfg = MqttConfig::load();
    if (!is_enabled($mqttcfg->usemqttgateway)) {
        return statusRow("Status.Gateway", "info", $L["Status.GatewayOwnBroker"]);
    }
    $general = json_decode((string) @file_get_contents(LBSCONFIGDIR . "/general.json"), true);
    $version = isset($general["Mqtt"]["Gatewayversion"]) ? (int) $general["Mqtt"]["Gatewayversion"] : 1;
    $autostart = !isset($general["Mqtt"]["Gatewayautostart"]) || is_enabled($general["Mqtt"]["Gatewayautostart"]);
    $suffix = $autostart ? "" : " " . $L["Status.GatewayAutostartOff"];
    if ($version === 2) {
        return statusRow("Status.Gateway", $autostart ? "info" : "hint", $L["Status.GatewayV2"] . $suffix);
    }
    $lines = array();
    if (is_readable($subscriptionFile)) {
        foreach (preg_split('/\r?\n/', (string) file_get_contents($subscriptionFile)) as $line) {
            if (trim($line) !== "" && strpos(trim($line), $topic . "/") === 0) {
                $lines[] = trim($line);
            }
        }
    }
    if (!$lines) {
        return statusRow("Status.Gateway", "fail", sprintf($L["Status.GatewayV1Missing"], $topic . "/...") . $suffix);
    }
    return statusRow("Status.Gateway", $autostart ? "ok" : "hint", sprintf($L["Status.GatewayV1Ok"], implode(", ", $lines)) . $suffix);
}
