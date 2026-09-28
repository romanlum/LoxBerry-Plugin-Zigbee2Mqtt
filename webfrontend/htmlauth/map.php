<?php
require_once 'include/plugin.php';
require_once 'include/Z2mBridge.php';

// POST scan=1: ask zigbee2mqtt for a network map and return it as JSON.
// Answered before the LoxBerry header, so the response is plain JSON.
if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["scan"])) {
    header("Content-Type: application/json");
    echo scanNetwork();
    exit(0);
}

$twig = Plugin::initializeTwig();

// Include header and set page as active
Plugin::createHeader(5);

echo $twig->render('map.html');

//creates the footer
LBWeb::lbfooter();

/**
 * Sends bridge/request/networkmap (type raw, without routes) and returns
 * {"result": true, "nodes": [...], "links": [...]} or {"result": false,
 * "message": "nobroker" | "noanswer" | "refused", "error": "..."}.
 * zigbee2mqtt asks every router for its neighbour table, which takes a few
 * seconds in a small network and can take a minute or more in a large one.
 */
function scanNetwork()
{
    @set_time_limit(150);
    $bridge = new Z2mBridge();
    if (!$bridge->connect()) {
        return json_encode(array("result" => false, "message" => "nobroker"));
    }
    $answer = $bridge->request("networkmap", array("type" => "raw", "routes" => false), 120.0);
    $bridge->close();
    if ($answer === null) {
        return json_encode(array("result" => false, "message" => "noanswer"));
    }
    if (!isset($answer["status"]) || $answer["status"] !== "ok" || !isset($answer["data"]["value"]["nodes"])) {
        return json_encode(array("result" => false, "message" => "refused", "error" => isset($answer["error"]) ? (string) $answer["error"] : ""));
    }
    $nodes = array();
    foreach ($answer["data"]["value"]["nodes"] as $node) {
        $nodes[] = array(
            "id" => $node["ieeeAddr"],
            "name" => isset($node["friendlyName"]) ? $node["friendlyName"] : $node["ieeeAddr"],
            "type" => isset($node["type"]) ? $node["type"] : "",
            "failed" => isset($node["failed"]) && is_array($node["failed"]) ? $node["failed"] : array()
        );
    }
    $links = array();
    foreach ($answer["data"]["value"]["links"] as $link) {
        $links[] = array(
            "source" => isset($link["source"]["ieeeAddr"]) ? $link["source"]["ieeeAddr"] : $link["sourceIeeeAddr"],
            "target" => isset($link["target"]["ieeeAddr"]) ? $link["target"]["ieeeAddr"] : $link["targetIeeeAddr"],
            "lqi" => isset($link["lqi"]) ? (int) $link["lqi"] : (isset($link["linkquality"]) ? (int) $link["linkquality"] : 0),
            "relationship" => isset($link["relationship"]) ? (int) $link["relationship"] : -1
        );
    }
    return json_encode(array("result" => true, "nodes" => $nodes, "links" => $links, "time" => date("H:i:s")));
}
