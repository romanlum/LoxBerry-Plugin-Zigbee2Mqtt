<?php
/**
 * MQTT Configuration class
 */
class MqttConfig {
    
    /** 
     * Use the mqtt-gateway mqtt server instead of a custom mqtt server
     * @var bool 
    */
    
    public $usemqttgateway = false;
    /** 
     * The mqtt topic
     * @var string 
    */
    public $topic = '';

    /**
     * The mqtt server username
     *  @var string */
    public $username = '';

    /** 
     * The mqtt server password
     * @var string */
    public $password = '';

    /**
     * The mqtt server url
     *  @var string */
    public $server = '';

    /** 
     * The mqtt server port
     * @var string */
    public $port = '';

    /**
     * Creats a new instance
     */
    public function __construct() {}

    /***
     * Loads the configuration and creates a new instance of the class
     */
    public static function load() {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        $data = json_decode(file_get_contents($mqttconfigfile), true);
        $class = new MqttConfig();
        foreach ($data as $key => $value) $class->{$key} = $value;
        return $class;
    }

    /**
     * Saves the instance to the configuration file
     */
    public function save() {
        $mqttconfigfile = LBPCONFIGDIR . "/mqtt.json";
        file_put_contents($mqttconfigfile, $this->toJson());
    }
    
    /**
     * Creates a json string out of the class
     */
    public function toJson() {
         return json_encode($this,JSON_PRETTY_PRINT);
     }

}