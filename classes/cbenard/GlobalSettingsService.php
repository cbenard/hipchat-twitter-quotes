<?php

namespace cbenard;

class GlobalSettingsService {
    private $container;
    private $mapper;
    
    public function __construct($container) {
        $this->container = $container;
        $this->mapper = $container->db->mapper('\Entity\GlobalSetting');
    }
    
    private function getSettings() {
        $setting = $this->mapper->first();
        if (!$setting) {
            $setting = $this->mapper->create([]);
        }
        
        return $setting;
    }
    
    public function getTwitterToken() {
        return $this->getSettings()->twitter_token;
    }
    
    public function setTwitterToken($twitter_token) {
        $settings = $this->getSettings();
        $currentValue = $settings->twitter_token;
        
        if ($currentValue !== $twitter_token) {
            $settings->twitter_token = $twitter_token;
            $this->mapper->save($settings);
        }
    }
}