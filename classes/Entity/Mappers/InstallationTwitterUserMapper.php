<?php

namespace Entity\Mappers;
use \Spot\Mapper;

class InstallationTwitterUserMapper extends Mapper {
    public function addNew($oauth_id, $screen_name, $webhook_trigger, $notify_new_tweets) {
        if ($this
            ->where([
                'webhook_trigger' => $webhook_trigger,
                'installations_oauth_id' => $oauth_id,
                'is_active' => true
            ])
            ->first()) {
        
            throw new \Exception("Another trigger is in place with the same name. Trigger: {$webhook_trigger}, OAuth ID: {$oauth_id}.");        
        }
        
        if ($this
            ->where([
                'screen_name' => $screen_name,
                'installations_oauth_id' => $oauth_id,
                'is_active' => true
            ])
            ->first()) {
        
            throw new \Exception("Another trigger is in place with the same Twitter screen name. Trigger: {$screen_name}, OAuth ID: {$oauth_id}.");        
        }
        
        return $this->create([
            'installations_oauth_id' => $oauth_id,
            'screen_name' => $screen_name,
            'webhook_trigger' => $webhook_trigger,
            'notify_new_tweets' => $notify_new_tweets,
        ]);
    }
    
    public function updateExisting($id, $oauth_id, $screen_name, $webhook_trigger, $notify_new_tweets) {
        if ($this
            ->where([
                'webhook_trigger' => $webhook_trigger,
                'installations_oauth_id' => $oauth_id,
                'id !=' => $id,
                'is_active' => true
            ])
            ->active()
            ->first()) {
        
            throw new \Exception("Another trigger is in place with the same name. Trigger: {$webhook_trigger}, OAuth ID: {$oauth_id}.");        
        }
        
        if ($this
            ->where([
                'screen_name' => $screen_name,
                'installations_oauth_id' => $oauth_id,
                'id !=' => $id,
                'is_active' => true
            ])
            ->active()
            ->first()) {
        
            throw new \Exception("Another trigger is in place with the same Twitter screen name. Trigger: {$screen_name}, OAuth ID: {$oauth_id}.");        
        }
        
        $record = $this->get($id);
        $record->screen_name = $screen_name;
        $record->webhook_trigger = $webhook_trigger;
        $record->notify_new_tweets = $notify_new_tweets;
        
        $this->save($record);
        
        return $record;
    }
    
    public function tombstone($id) {
        $record = $this->get($id);
        $record->is_active = false;
        
        $this->save($record);
        
        return $record;
    }
    
    public function scopes()
    {
        return [
            'active' => function ($query) {
                return $query->where([ "is_active" => true ]);
            },
            'inactive' => function ($query) {
                return $query->where([ "is_active" => false ]);
            }
        ];
    }
}