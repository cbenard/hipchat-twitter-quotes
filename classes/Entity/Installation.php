<?php

namespace Entity;

class Installation extends \Spot\Entity {
    protected static $table = "installations";
    
    public static function fields() {
        return [
            'oauth_id' => [ 'type' => 'string', 'primary' => true, 'length' => 255 ],
            'raw_json' => [ 'type' => 'text', 'required' => true ],
            'token_url' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'api_url' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'access_token' => [ 'type' => 'string', 'required' => false, 'length' => 255 ],
            'access_token_expiration' => [ 'type' => 'datetime', 'required' => false ],
        ];
    }
}