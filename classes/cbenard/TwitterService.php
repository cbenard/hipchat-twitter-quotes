<?php
namespace cbenard;

use Freebird\Services\freebird\Client;

class TwitterService {

    private $key;
    private $secret;
    private $bearer_token;
    private $client;
    
    public function __construct($key, $secret) {
        $this->key = $key;
        $this->secret = $secret;
        $this->client = new Client();
    }
    
    public function setBearerToken($bearer_token) {
        $this->bearer_token = $bearer_token;
    }
    
    public function fetchTweetsSince($screen_name, $since_id) {
        $this->runPreflightChecks();
        
        $response = $this->client->api_request(
            'statuses/user_timeline.json',
            array
            (
                'screen_name' => $screen_name,
                'count' => 200,
                'trim_user' => 'true',
                'exclude_replies' => 'true',
                'contributor_details' => 'false',
                'include_rts' => 'false'
            )
        );
        
        $o = json_decode($response);
        $result = array_map(function($item) {
            return array(
                'created_at' => $item->created_at,
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
}