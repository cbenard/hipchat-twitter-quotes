<?php

namespace Entity;

class TwitterUser extends \Spot\Entity {
    protected static $table = "twitter_users";
    
    public static function fields() {
        return [
            'id' => [ 'type' => 'string', 'primary' => true, 'length' => 255 ],
            'profile_image_url_https' => [ 'type' => 'string', 'required' => true, 'length' => 500 ],
            'name' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'screen_name' => [ 'type' => 'string', 'required' => true, 'length' => 255 ]
        ];
    }
}