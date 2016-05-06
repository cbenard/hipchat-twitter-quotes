<?php

namespace Entity;

class InstallationTwitterUser extends \Spot\Entity {
    protected static $table = "installations_twitter_users";
    protected static $mapper = "\Entity\Mappers\InstallationTwitterUserMapper";
    
    public static function fields() {
        return [
            'id' => [ 'type' => 'integer', 'primary' => true, 'autoincrement' => true ],
            'installations_oauth_id' => [ 'type' => 'string', 'length' => 255, 'onUpdate' => 'CASCADE', 'onDelete' => 'SET NULL' ],
            'screen_name' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'webhook_trigger' => [ 'type' => 'string', 'required' => true, 'length' => 25 ],
            'notify_new_tweets' => [ 'type' => 'boolean', 'required' => true, 'default' => false, 'value' => false ],
            'is_active' => [ 'type' => 'boolean', 'required' => true, 'default' => true, 'value' => true ],
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

    public static function relations(\Spot\MapperInterface $mapper, \Spot\EntityInterface $entity)
    {
        return [
            'user' => $mapper->belongsTo($entity, '\Entity\TwitterUser', 'screen_name'),
            'installation' => $mapper->belongsTo($entity, '\Entity\Installation', 'installations_oauth_id'),
        ];
    }
}