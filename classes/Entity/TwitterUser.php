<?php

namespace Entity;

class TwitterUser extends \Spot\Entity {
    protected static $table = "twitter_users";
    
    public static function fields() {
        return [
            'screen_name' => [ 'type' => 'string', 'primary' => true, 'length' => 255 ],
            'user_id' => [ 'type' => 'string', 'required' => true, 'unique' => true, 'length' => 255 ],
            'profile_image_url_https' => [ 'type' => 'string', 'required' => true, 'length' => 500 ],
            'name' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'created_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
            'updated_on' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
        ];
    }

    public static function events(\Spot\EventEmitter $eventEmitter)
    {
        $eventEmitter->on('beforeSave', function (\Spot\Entity $entity, \Spot\Mapper $mapper) {
            $entity->updated_on = new \DateTime;
        });
    }
}