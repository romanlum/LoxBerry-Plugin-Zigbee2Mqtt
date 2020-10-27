<?PHP
require_once 'model/MqttConfig.php';
require_once "include/phpMQTT.php";
require_once "loxberry_system.php";
require_once "loxberry_io.php";

/***
 * Zigbee2mqtt service 
 */
class ZigbeeService
{
    private $mqttcfg;
    private $mqtt;

    /***
     * Creates a new instance using the default credentials
     */
    function __construct() {

        $this->mqttcfg = MqttConfig::Load();
        //MQTT parameter
        if (is_enabled($this->mqttcfg->usemqttgateway)) {
            $creds = mqtt_connectiondetails();
            $this->mqttcfg->server = $creds["brokerhost"];
            $this->mqttcfg->port = $creds["brokerport"];
            $this->mqttcfg->username = $creds["brokeruser"];
            $this->mqttcfg->password = $creds["brokerpass"];
        } 
          
        $client_id = uniqid(gethostname()."_client");
        $this->mqtt = new Bluerhinos\phpMQTT($this->mqttcfg->server,  $this->mqttcfg->port, $client_id);
        $this->mqtt->connect(true, NULL, $this->mqttcfg->username, $this->mqttcfg->password);
    }


    /**
     * Gets the zigbee2mqtt version number
     */
    public function getVersion() 
    {
        //publish an empty message to get the result
        $this->publish("bridge/config",'');
        $topic = $this->mqttcfg->topic . "/bridge/config";
        $result =  json_decode($this->mqtt->subscribeAndWaitForMessage($topic,0));
        return $result->version;
               

    }

    /***
     * Publishes a message to the mqtt server
     */
    private function publish($topic,$data){
        $topic = $this->mqttcfg->topic . "/" . $topic;
        $this->mqtt->publish($topic,$data);
    }


}