<?php

namespace Entity;

class Installation extends \Spot\Entity {
    protected static $table = "installations";
    
    public static function fields() {
        return [
            'oauth_id' => [ 'type' => 'string', 'primary' => true, 'length' => 255 ],
            'oauth_secret' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'room_id' => [ 'type' => 'integer', 'required' => true],
            'group_id' => [ 'type' => 'integer', 'required' => true],
            'raw_json' => [ 'type' => 'text', 'required' => true ],
            'token_url' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'api_url' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'access_token' => [ 'type' => 'string', 'required' => false, 'length' => 255 ],
            'access_token_expiration' => [ 'type' => 'datetime', 'required' => false ],
            'created_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
            'updated_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
            'twitter_screenname' => [ 'type' => 'string', 'required' => false, 'length' => 255 ],
            'webhook_trigger' => [ 'type' => 'string', 'required' => false, 'length' => 25 ],
        ];
    }

    public static function events(\Spot\EventEmitter $eventEmitter)
    {
        $eventEmitter->on('beforeSave', function (\Spot\Entity $entity, \Spot\Mapper $mapper) {
            $entity->updated_on = new \DateTime;
        });
    }
}