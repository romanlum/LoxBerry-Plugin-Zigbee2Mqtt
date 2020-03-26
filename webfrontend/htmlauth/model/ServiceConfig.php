<?php

class ServiceConfig {

    public static function load() {
        $configfile = LBPCONFIGDIR . "/service.json";
        $data = json_decode(file_get_contents($configfile), true);
        $class = new ServiceConfig();
        foreach ($data as $key => $value) $class->{$key} = $value;
        return $class;
    }

    public function save() {
        $configfile = LBPCONFIGDIR . "/service.json";
        file_put_contents($configfile, $this->toJson());
    }

    /** @var bool */
    public $permitJoin = false;

    /** @var string */
    public $port = '';

    public function __construct() {
    }

     public function toJson() {
         return json_encode($this,JSON_PRETTY_PRINT);
     }

    

}