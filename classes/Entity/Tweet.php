<?php

namespace Entity;

class Tweet extends \Spot\Entity {
    protected static $table = "tweets";
    
    public static function fields() {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'tweet_id' => [ 'type' => 'string', 'required' => true, 'unique' => true, 'length' => 255 ],
            'created_at' => [ 'type' => 'datetime', 'required' => true ],
            'text' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'user_id' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'screen_name' => [ 'type' => 'string', 'required' => true, 'index' => true, 'length' => 255 ],
        ];
    }
}