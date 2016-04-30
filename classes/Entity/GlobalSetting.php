<?php

namespace Entity;

class GlobalSetting extends \Spot\Entity {
    protected static $table = "global_settings";
    
    public static function fields() {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'twitter_token' => [ 'type' => 'string', 'required' => false, 'length' => 255 ],
        ];
    }
}