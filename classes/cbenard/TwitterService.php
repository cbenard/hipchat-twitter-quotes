<?php
namespace cbenard;

use \cbenard\Codebird;
use \cbenard\TwitterErrorStatus;
use \cbenard\TwitterException;

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
    
    public function getUserInfoByName($screen_name, $access_token = null, $access_token_secret = null) {
        return $this->getUserInfo($screen_name, null, $access_token, $access_token_secret);
    }

    public function getUserInfoByID($id_str, $access_token = null, $access_token_secret = null) {
        return $this->getUserInfo(null, $id_str);
    }

    public function verifyCredentials($access_token, $access_token_secret) {
        $this->runPreflightChecks($access_token, $access_token_secret);

        $response = $this->client->account_verifyCredentials();

        $result = (object)array(
            'created_at' => new \DateTime($response->created_at, $this->utc),
            'id' => $response->id_str,
            'profile_image_url_https' => $response->profile_image_url_https,
            'name' => $response->name,
            'screen_name' => $response->screen_name
        );

        return $result;
    }

    private function getUserInfo($screen_name, $id_str, $access_token = null, $access_token_secret = null) {
        $this->runPreflightChecks($access_token, $access_token_secret);
        
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

        $response = $this->client->users_show($params, $this->isAppAuth($access_token, $access_token_secret));
        
        if ($response->httpstatus == 401 && isset($response->error)) {
            throw new TwitterException("Unable to follow user with current credentials.", TwitterErrorStatus::UNABLE_TO_FOLLOW);
        }
        if ($response->httpstatus != 200 && isset($response->code)) {
            if ($response->code == 89) {
                throw new TwitterException("Invalid token. Perhaps revoked?", TwitterErrorStatus::INVALID_TOKEN);
            }
            if ($response->code == 34) {
                throw new TwitterException("User not found", TwitterErrorStatus::INVALID_USER);
            }
        }
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
    
    public function getTweetsSince($user_id, $since_id = null, $access_token = null, $access_token_secret = null) {
        $this->runPreflightChecks($access_token, $access_token_secret);
        
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
        
        $response = $this->client->statuses_userTimeline($params, $this->isAppAuth($access_token, $access_token_secret));

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
    
    public function getTweetsBefore($user_id, $before_id = null, $access_token = null, $access_token_secret = null) {
        $this->runPreflightChecks($access_token, $access_token_secret);
        
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
        
        $response = $this->client->statuses_userTimeline($params, $this->isAppAuth($access_token, $access_token_secret));
        
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
    
    private function runPreflightChecks($access_token = null, $access_token_secret = null) {
        if ($this->bearer_token) {
            Codebird::setBearerToken($this->bearer_token);
        }
        else {
            $this->client->oauth2_token();
            $this->bearer_token = Codebird::getBearerToken();
        }

        if ($access_token && $access_token_secret) {
            $this->setToken($access_token, $access_token_secret);
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

    public function oauthRequestToken($callbackUrl) {
        return $this->client->oauth_requestToken([
            'oauth_callback' => $callbackUrl
        ]);
    }

    public function oauthAccessToken($request_token, $request_token_secret, $oauth_verifier) {
        $this->setToken($request_token, $request_token_secret);

        return $this->client->oauth_accessToken([
            'oauth_verifier' => $oauth_verifier
        ]);
    }

    public function setToken($token, $token_secret) {
        return $this->client->setToken($token, $token_secret);
    }

    public function logout() {
        return $this->client->logout();
    }

    public function oauthAuthorizationUrl($request_token, $request_token_secret) {
        $this->setToken($request_token, $request_token_secret);
        return $this->client->oauth_authorize();
    }

    private function isAppAuth($access_token, $access_token_secret) {
        return !$access_token || !$access_token_secret;
    }
}