<?php
require_once 'include/plugin.php';
require_once 'include/Z2mBridge.php';

/**
 * Downloads a backup of the zigbee2mqtt data folder as zip. zigbee2mqtt
 * builds it itself on bridge/request/backup: configuration.yaml, devices,
 * database.db, coordinator_backup.json, state.json. It contains the
 * network key and the MQTT credentials.
 * Nothing is changed. On failure a short plain text says why.
 */
$bridge = new Z2mBridge();
$message = "";
if (!$bridge->connect()) {
    $message = $L["Backup.NoBroker"];
} else {
    $answer = $bridge->request("backup", array(), 30.0);
    $bridge->close();
    if ($answer === null) {
        $message = $L["Backup.NoAnswer"];
    } elseif (!isset($answer["status"]) || $answer["status"] !== "ok" || !isset($answer["data"]["zip"])) {
        $message = $L["Backup.Refused"] . " " . (isset($answer["error"]) ? $answer["error"] : "");
    } else {
        $zip = base64_decode($answer["data"]["zip"], true);
        if ($zip === false || substr($zip, 0, 2) !== "PK") {
            $message = $L["Backup.Refused"];
        } else {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="zigbee2mqtt_backup_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . strlen($zip));
            echo $zip;
            exit(0);
        }
    }
}
header('Content-Type: text/plain; charset=utf-8', true, 503);
echo $message . "\n";
