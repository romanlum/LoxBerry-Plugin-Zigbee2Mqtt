<?php

/**
 * Zigbee2Mqtt service configuration
 */
class ServiceConfig {
    
    /**
     * Allow to add new devices
     *  @var bool */
    public $permitJoin = false;

    /**
     * Path to the zigbee device 
     * @var string */
    public $port = '';

    /**
     * Creates a new instance
     */
    public function __construct() {
    }


    /**
     * Loads the config
     */
    public static function load() {
        $configfile = LBPCONFIGDIR . "/service.json";
        $data = json_decode(file_get_contents($configfile), true);
        $class = new ServiceConfig();
        foreach ($data as $key => $value) $class->{$key} = $value;
        return $class;
    }

    /**
     * Saves the config
     */
    public function save() {
        $configfile = LBPCONFIGDIR . "/service.json";
        file_put_contents($configfile, $this->toJson());
    }

    /**
     * Creates a json string out of the config
     */
     public function toJson() {
         return json_encode($this,JSON_PRETTY_PRINT);
     }

    

}