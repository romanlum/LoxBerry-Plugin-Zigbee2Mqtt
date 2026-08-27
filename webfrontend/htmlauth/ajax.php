<?php

require_once "loxberry_system.php";
require_once "loxberry_log.php";
require_once "model/ServiceConfig.php";
require_once "model/MqttConfig.php";
require_once LBPBINDIR . "/defines.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/z2m-version.php";

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
    } else if ($action == "getVersionInfo") {
        sendresponse(200, "application/json", getVersionInfo());
    } else if ($action == "startUpgrade") {
        if (isset($_POST["version"])) {
            startUpgrade($_POST["version"]);
        }
    } else if ($action == "getUpgradeStatus") {
        sendresponse(200, "application/json", json_encode(z2mGetUpgradeState()));
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
 * Returns installed/pinned zigbee2mqtt version, the list of available
 * releases and the current upgrade job state, for the Version tab.
 */
function getVersionInfo()
{
    $info = [
        "installedVersion" => z2mGetInstalledVersion(),
        "pinnedVersion" => z2mGetPinnedVersion(),
        "releases" => z2mGetAvailableReleases(),
        "upgrade" => z2mGetUpgradeState(0),
    ];
    return json_encode($info);
}

/**
 * Starts a zigbee2mqtt upgrade to the given version in the background and
 * returns immediately - the build can take several minutes, far longer than
 * a single PHP request/response cycle should block for. Progress is polled
 * separately via getUpgradeStatus.
 */
function startUpgrade($version)
{
    LOGSTART("Start zigbee2mqtt version upgrade");

    if (!z2mIsValidVersionString($version)) {
        LOGERR("Rejected invalid version string");
        LOGEND("Upgrade not started");
        sendresponse(400, "application/json", '{"error":"Invalid version."}');
        exit(1);
    }

    $releases = z2mGetAvailableReleases();
    if (!in_array($version, $releases, true)) {
        LOGERR("Rejected version not in known release list: $version");
        LOGEND("Upgrade not started");
        sendresponse(400, "application/json", '{"error":"Unknown zigbee2mqtt version."}');
        exit(1);
    }

    $state = z2mGetUpgradeState(0);
    if ($state["status"] === "running") {
        LOGERR("An upgrade is already running");
        LOGEND("Upgrade not started");
        sendresponse(409, "application/json", '{"error":"An upgrade is already running."}');
        exit(1);
    }

    z2mResetLog();

    $cmd = "sudo " . LBPSBINDIR . "/install-zigbee2mqtt.sh " . escapeshellarg($version);
    exec("nohup setsid $cmd >> " . escapeshellarg(Z2M_UPGRADE_LOG) . " 2>&1 &");

    LOGOK("Upgrade to $version started");
    LOGEND("Upgrade dispatched");
    sendresponse(200, "application/json", '{"result":true}');
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
