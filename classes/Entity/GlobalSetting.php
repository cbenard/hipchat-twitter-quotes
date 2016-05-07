<?php

namespace Entity;

class GlobalSetting extends \Spot\Entity {
    protected static $table = "global_settings";
    
    public static function fields() {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'twitter_token' => [ 'type' => 'string', 'required' => false, 'length' => 255 ],
            'last_twitter_update' => [ 'type' => 'datetime', 'required' => true, 'value' => new \DateTime ],
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