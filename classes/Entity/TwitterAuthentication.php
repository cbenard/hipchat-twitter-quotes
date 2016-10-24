<?php

namespace Entity;

class TwitterAuthentication extends \Spot\Entity {
    protected static $table = "twitter_authentications";

    public static function fields() {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true ],
            'request_token' => [ 'type' => 'string', 'length' => 255 ],
            'request_token_secret' => [ 'type' => 'string', 'length' => 255 ],
            'access_token' => [ 'type' => 'string', 'length' => 255 ],
            'access_token_secret' => [ 'type' => 'string', 'length' => 255 ],
            'is_completed' => [ 'type' => 'boolean', 'required' => true, 'value' => false ],
            'user_id' => [ 'type' => 'string', 'length' => 255 ],
            'screen_name' => [ 'type' => 'string', 'length' => 255 ],
            'profile_image_url_https' => [ 'type' => 'string', 'length' => 500 ],
            'name' => [ 'type' => 'string', 'length' => 255 ],
            'created_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
            'updated_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
            'verified_on' => [ 'type' => 'datetime' ],
            'error_status' => [ 'type' => 'integer' ],
        ];
    }

    public static function events(\Spot\EventEmitter $eventEmitter)
    {
        $eventEmitter->on('beforeSave', function (\Spot\Entity $entity, \Spot\Mapper $mapper) {
            $entity->updated_on = new \DateTime;
        });
    }
}