<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \Ramsey\Uuid\Uuid;

class WebHookController {
    private $container;
    private $jwt;
    private $installationMapper;
    private $tweetMapper;
    private $userMapper;
    private $db;
    
    public function __construct($container) {
        $this->container = $container;
        $this->jwt = $container->jwt;
        $this->installationMapper = $container->db->mapper('\Entity\Installation');
        $this->userMapper = $container->db->mapper('\Entity\TwitterUser');
        $this->tweetMapper = $container->db->mapper('\Entity\Tweet');
        $this->db = $container->db;
    }
    
    public function trigger(Request $request, Response $response, $args) {
        $this->container->logger->info("Web Hook Triggered", [
            "request" => $request,
            "body" => $request->getBody(),
            "queryParams" => $request->getQueryParams(),
            "headers" => $request->getHeaders(),
            "oauth_client_id" => $request->getParsedBody()['oauth_client_id'],
            "message" => $request->getParsedBody()['item']['message']['message']
        ]);
        
        // HipChat doesn't appear to be sending the JWT with the web hook.
        // I believe this is a bug.
        
        // $jwt = $this->jwt->validateRequest($request);
        
        // $this->container->logger->info("Web Hook JWT validated", [
        //     "jwt" => $jwt
        // ]);
        
        // $oauth_id = $jwt->iss;
        
        // Have to get it from the unauthenticated request instead of JWT due to issue above
        $oauth_id = $request->getParsedBody()['oauth_client_id'];
        $installation = $this->installationMapper->first([ 'oauth_id' => $oauth_id ]);
        
        $this->container->logger->info("Web Hook installation fetched", [
            "installation" => $installation
        ]);
        
        $message = strip_tags($request->getParsedBody()['item']['message']['message']);
        
        $respData = null;
        
        try {
            $respData = $this->processTrigger($installation, $message);
        }
        catch (\Exception $e) {
            $this->container->logger->error("Error processing trigger", [ "exception" => $e ]);
            
            $respData = new \stdClass;
            $respData->color = "red";
            $respData->message_format = "html";
            $respData->message = "<strong>Uh oh!</strong> Something went wrong. Please ask the integration administrator to check the logs.";
        }
        
        $response = $response->withJson($respData);

        return $response;
    }
    
    private function processTrigger($installation, $message) {
        $words = explode(" ", $message);

        if (count($words) == 1) {
            return $this->randomQuote($installation);
        }
        elseif (count($words) == 2 && strtolower($words[1]) == "help") {
            return $this->usage($installation);
        }
        else {
            $arguments = array_slice($words, 1);
            return $this->quoteSearch($installation, $arguments);
        }
    }
    
    private function randomQuote($installation) {
        $this->container->logger->info("Random quote requested");
        $tweet = $this->tweetMapper
            ->query("SELECT * FROM `tweets` ORDER BY rand() LIMIT 1")
            ->first();
        
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->message = "I couldn't find a random tweet. Maybe I haven't had time to update my list of tweets, yet.";
            $respData->color = "red";
        }
        else {
            $respData = $this->createMessageForTweet($tweet);
        }
        
        return $respData;
    }
    
    private function quoteSearch($installation, $arguments) {
        $argString = implode(" ", $arguments);
        $this->container->logger->info("Quote search requested", [ "arguments" => $argString ]);
        
        // Try for a direct match
        $tweet = $this->tweetMapper
            ->query("SELECT * FROM `tweets` "
                . "WHERE `text` LIKE ? "
                . "ORDER BY created_at DESC LIMIT 1", [ "%{$argString}%" ])
            ->first();
            
        if (!$tweet) {
            // Try with words in order
            $likeArgs = implode("%", $arguments);
            $tweet = $this->tweetMapper
                ->query("SELECT * FROM `tweets` "
                    . "WHERE `text` LIKE ? "
                    . "ORDER BY created_at DESC LIMIT 1", [ "%{$likeArgs}%" ])
                ->first();
        }
            
        if (!$tweet) {
            // Try with all words in any order
            $likeArgs = array_map(function($item) { return "%{$item}%"; }, $arguments);
            $tweet = $this->tweetMapper
                ->query("SELECT * FROM `tweets` "
                    . "WHERE 1=1 "
                    . str_repeat(" AND `text` LIKE ? ", count($likeArgs))
                    . "ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
            
        if (!$tweet) {
            // Try with any words in any order
            $likeArgs = array_map(function($item) { return "%{$item}%"; }, $arguments);
            $tweet = $this->tweetMapper
                ->query("SELECT * FROM `tweets` "
                    . "WHERE ("
                    . substr(str_repeat(" OR `text` LIKE ? ", count($likeArgs)), 4)
                    . ") ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
        
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->message = "I couldn't find a tweet with that search text.";
            $respData->color = "red";
        }
        else {
            $respData = $this->createMessageForTweet($tweet);
        }
        
        return $respData;
    }
    
    private function usage($installation) {
        $respData = new \stdClass;
        $respData->from = "Help";
        $respData->message_format = "html";
        $respData->color = "yellow";
        $respData->message = "<strong>Twitter Quotes Help</strong><br/><ul>"
            . "<li><strong><code>{$installation->webhook_trigger}</code></strong> &ndash; Random quote</li>"
            . "<li><strong><code>{$installation->webhook_trigger} help</code></strong> &ndash; This help message</li>"
            . "<li><strong><code>{$installation->webhook_trigger} search text</code></strong> &ndash; Most recent matching quote for search text</li>"
            . "</ul>";
            
        return $respData;
    }
    
    private function createMessageForTweet($tweet) {
        $user = $this->userMapper->first([ 'id' => $tweet->user_id ]);
        
        $respData = new \stdClass;
        $respData->message = $tweet->text;
        
        $respData->card = new \stdClass;
        $respData->card->style = "link";
        $respData->card->description = new \stdClass;
        $respData->card->description->value = $tweet->text;
        $respData->card->description->format = "text";
        $respData->card->format = "compact";
        $respData->card->url = "https://twitter.com/statuses/{$tweet->tweet_id}";
        // $respData->card->thumbnail = new \stdClass;
        // $respData->card->thumbnail->url = $user->profile_image_url_https;
        $respData->card->title = "{$user->name}";
        if ($user->screen_name != $user->name) {
            $respData->card->title .= "({$user->screen_name})";
        }
        $respData->card->id = Uuid::uuid4()->toString();
        $respData->card->icon = new \stdClass;
        $respData->card->icon->url = $user->profile_image_url_https;
        
        $this->container->logger->info("Created card for tweet", [ "card" => $respData->card ]);
        
        return $respData;
    }
}