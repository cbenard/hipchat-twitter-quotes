<?php
namespace cbenard;

use \cbenard\Codebird;

class TwitterService {

    private $key;
    private $secret;
    private $bearer_token;
    private $client;
    private $container;
    private $utc;
    
    public function __construct($container) {
        $this->container = $container;
        $this->key = $container->config['twitter']['key'];
        $this->secret = $container->config['twitter']['secret'];
        Codebird::setConsumerKey($this->key, $this->secret);
        $this->client = Codebird::getInstance();
        $this->utc = new \DateTimeZone("UTC");
    }
    
    public function getUserInfoByName($screen_name) {
        return $this->getUserInfo($screen_name, null);
    }

    public function getUserInfoByID($id_str) {
        return $this->getUserInfo(null, $id_str);
    }

    private function getUserInfo($screen_name, $id_str) {
        $this->runPreflightChecks();
        
        $params = [
                'include_entities' => 'false'
        ];

        if ($id_str) {
            $params['user_id'] = $id_str;
        }
        elseif ($screen_name) {
            $params['screen_name'] = $screen_name;
        }
        else {
            throw new \Exception("Unable to find user id or screenname in input attempting to get user info.");
        }

        $response = $this->client->users_show($params, true);
        
        if (!isset($response->screen_name)) {
            throw new \Exception("Unable to find screen name: " . $screen_name);
        }
        $result = (object)array(
            'created_at' => new \DateTime($response->created_at, $this->utc),
            'id' => $response->id_str,
            'profile_image_url_https' => $response->profile_image_url_https,
            'name' => $response->name,
            'screen_name' => $response->screen_name
        );
        
        return $result;
    }
    
    public function getTweetsSince($user_id, $since_id = null) {
        $this->runPreflightChecks();
        
        $params = [
                'user_id' => $user_id,
                'count' => 200,
                // We now want this to update in one step
                // 'trim_user' => 'true',
                'exclude_replies' => 'true',
                'contributor_details' => 'false',
                'include_rts' => 'false'
        ];
        
        if ($since_id) {
            $params['since_id'] = $since_id;
        }
        
        $response = $this->client->statuses_userTimeline($params, true);

        $o = $this->getTweetsFromResponse($response);
        $result = array_map(function($item) {
            return (object)array(
                'created_at' => new \DateTime($item->created_at),
                'id' => $item->id_str,
                'text' => $item->text,
                'user' => $item->user,
            );
        }, $o);
        
        return $result;
    }
    
    public function getTweetsBefore($user_id, $before_id = null) {
        $this->runPreflightChecks();
        
        $params = [
                'user_id' => $user_id,
                'count' => 200,
                // We now want this to update in one step
                // 'trim_user' => 'true',
                'exclude_replies' => 'true',
                'contributor_details' => 'false',
                'include_rts' => 'false',
                'max_id' => $before_id
        ];
        
        $response = $this->client->statuses_userTimeline($params, true);
        
        $o = $this->getTweetsFromResponse($response);
        $result = array_map(function($item) {
            return (object)array(
                'created_at' => new \DateTime($item->created_at),
                'id' => $item->id_str,
                'text' => $item->text,
                'user' => $item->user,
            );
        }, $o);
        
        $result = array_filter($result, function ($item) use ($before_id) { return $item->id != $before_id; });
        
        return $result;
    }
    
    private function runPreflightChecks() {
        if ($this->bearer_token) {
            Codebird::setBearerToken($this->bearer_token);
        }
        else {
            $this->client->oauth2_token();
            $this->bearer_token = Codebird::getBearerToken();
        }
    }
    
    public function getBearerToken() {
        return $this->bearer_token;
    }
    
    public function setBearerToken($bearer_token) {
        $this->bearer_token = $bearer_token;
    }

    private function getTweetsFromResponse($response) {
        $arr = (array)$response;
        $keys = array_keys($arr);
        foreach ($keys as $key) {
            if (!is_numeric($key)) {
                unset($arr[$key]);
            }
        }

        return $arr;
    }
}