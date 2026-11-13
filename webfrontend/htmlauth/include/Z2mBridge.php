<?php
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once __DIR__ . "/../model/MqttConfig.php";

/**
 * Talks to the running zigbee2mqtt over MQTT, with the same broker
 * credentials that bin/update-config.php writes into configuration.yaml
 * (MQTT gateway credentials or the user's own broker).
 *
 * This file is identical in every branch that uses it, so it merges cleanly
 * whichever of those branches is merged first.
 */
class Z2mBridge
{
    /** @var string base topic of zigbee2mqtt, e.g. "zigbee2mqtt" */
    public $topic = "";

    /** @var Bluerhinos\phpMQTT|null */
    private $mqtt = null;

    /** @var array received payloads by sub topic */
    private $messages = [];

    /** @var array broker host, port, user, password */
    private $creds = [];

    public function __construct()
    {
        $mqttcfg = MqttConfig::load();
        $this->topic = (string) $mqttcfg->topic;
        if (is_enabled($mqttcfg->usemqttgateway)) {
            $this->creds = mqtt_connectiondetails();
        } else {
            $this->creds = [
                "brokerhost" => $mqttcfg->server,
                "brokerport" => $mqttcfg->port,
                "brokeruser" => $mqttcfg->username,
                "brokerpass" => $mqttcfg->password
            ];
        }
    }

    /** host:port of the broker, for messages */
    public function address(): string
    {
        return (string) $this->creds["brokerhost"] . ":" . (string) $this->creds["brokerport"];
    }

    /**
     * Opens the connection. Returns false if no broker is configured or the
     * broker refuses the connection.
     */
    public function connect(): bool
    {
        if (empty($this->creds["brokerhost"]) || $this->topic === "") {
            return false;
        }
        $this->mqtt = new Bluerhinos\phpMQTT($this->creds["brokerhost"], (int) $this->creds["brokerport"], "zigbee2mqtt-plugin-" . bin2hex(random_bytes(4)));
        if (!@$this->mqtt->connect(true, null, $this->creds["brokeruser"], $this->creds["brokerpass"])) {
            $this->mqtt = null;
            return false;
        }
        return true;
    }

    /**
     * Reads retained messages below the base topic, e.g. ["bridge/info",
     * "bridge/devices"]. Waits until all have arrived or the timeout is over.
     * Returns [sub topic => decoded JSON (or the raw string if it is no JSON)];
     * a topic that did not arrive is missing from the result.
     */
    public function retained(array $subTopics, float $timeout = 3.0): array
    {
        if ($this->mqtt === null) {
            return [];
        }
        $topics = [];
        foreach ($subTopics as $sub) {
            $topics[$this->topic . "/" . $sub] = ["qos" => 0, "function" => [$this, "onMessage"]];
        }
        $this->mqtt->subscribe($topics, 0);
        $until = microtime(true) + $timeout;
        while (count(array_intersect_key($this->messages, array_flip($subTopics))) < count($subTopics) && microtime(true) < $until) {
            $this->mqtt->proc();
        }
        return array_intersect_key($this->messages, array_flip($subTopics));
    }

    /**
     * Sends a bridge request (e.g. "networkmap" with ["type" => "raw"]) and
     * returns the matching bridge/response as array, or null on timeout.
     */
    public function request(string $name, array $payload, float $timeout = 5.0)
    {
        if ($this->mqtt === null) {
            return null;
        }
        $transaction = bin2hex(random_bytes(6));
        $payload["transaction"] = $transaction;
        $answer = null;
        $this->mqtt->subscribe([
            $this->topic . "/bridge/response/" . $name => [
                "qos" => 0,
                "function" => function ($topic, $msg) use (&$answer, $transaction) {
                    $data = json_decode($msg, true);
                    if (is_array($data) && isset($data["transaction"]) && $data["transaction"] === $transaction) {
                        $answer = $data;
                    }
                }
            ]
        ], 0);
        $this->mqtt->publish($this->topic . "/bridge/request/" . $name, json_encode($payload), 0, false);
        $until = microtime(true) + $timeout;
        while ($answer === null && microtime(true) < $until) {
            $this->mqtt->proc();
        }
        return $answer;
    }

    /** @internal callback for retained() */
    public function onMessage($topic, $msg)
    {
        $sub = substr($topic, strlen($this->topic) + 1);
        $data = json_decode($msg, true);
        $this->messages[$sub] = ($data === null && $msg !== "null") ? $msg : $data;
    }

    public function close()
    {
        if ($this->mqtt !== null) {
            $this->mqtt->close();
            $this->mqtt = null;
        }
    }
}
