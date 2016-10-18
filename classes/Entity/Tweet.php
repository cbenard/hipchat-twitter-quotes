<?php

namespace Entity;

class Tweet extends \Spot\Entity {
    protected static $table = "tweets";
    protected static $mapper = "\Entity\Mappers\TweetMapper";
    
    public static function fields() {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true ],
            'tweet_id' => [ 'type' => 'string', 'required' => true, 'unique' => true, 'length' => 255 ],
            'created_at' => [ 'type' => 'datetime', 'required' => true, 'index' => true ],
            'text' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
            'user_id' => [ 'type' => 'string', 'required' => true, 'length' => 255 ],
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
            'user' => $mapper->belongsTo($entity, '\Entity\TwitterUser', 'user_id'),
        ];
    }
}