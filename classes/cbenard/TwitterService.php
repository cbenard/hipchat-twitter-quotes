<?php
namespace cbenard;

use Freebird\Services\freebird\Client;

class TwitterService {

    private $key;
    private $secret;
    private $bearer_token;
    private $client;
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
        $this->key = $container->config['twitter']['key'];
        $this->secret = $container->config['twitter']['secret'];
        $this->client = new Client();
    }
    
    public function getUserInfo($screen_name) {
        $this->runPreflightChecks();
        
        $params = [
                'screen_name' => $screen_name,
                'include_entities' => 'false'
        ];
        
        $response = $this->client->api_request('users/show.json', $params);
        
        $item = json_decode($response);
        $result = (object)array(
            'created_at' => new \DateTime($item->created_at),
            'id' => $item->id_str,
            'profile_image_url_https' => $item->profile_image_url_https,
            'name' => $item->name,
            'screen_name' => $item->screen_name
        );
        
        return $result;
    }
    
    public function getTweetsSince($screen_name, $since_id = null) {
        $this->runPreflightChecks();
        
        $params = [
                'screen_name' => $screen_name,
                'count' => 200,
                'trim_user' => 'true',
                'exclude_replies' => 'true',
                'contributor_details' => 'false',
                'include_rts' => 'false'
        ];
        
        if ($since_id) {
            $params['since_id'] = $since_id;
        }
        
        $response = $this->client->api_request('statuses/user_timeline.json', $params);
        
        $o = json_decode($response);
        $result = array_map(function($item) {
            return (object)array(
                'created_at' => new \DateTime($item->created_at),
                'id' => $item->id_str,
                'text' => $item->text,
                'user_id' => $item->user->id_str
            );
        }, $o);
        
        return $result;
    }
    
    private function runPreflightChecks() {
        if ($this->bearer_token) {
            $this->client->set_bearer_token($this->bearer_token);
        }
        else {
            $this->client->init_bearer_token($this->key, $this->secret);
            $this->bearer_token = $this->client->get_bearer_token();
        }
    }
    
    public function getBearerToken() {
        return $this->bearer_token;
    }
    
    public function setBearerToken($bearer_token) {
        $this->bearer_token = $bearer_token;
    }
}