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
        
        $jwt = $this->jwt->validateRequest($request);
        
        $this->container->logger->info("Web Hook JWT validated", [
            "jwt" => $jwt
        ]);
        
        $oauth_id = $jwt->iss;
        $installation = $this->installationMapper->get($oauth_id);
        
        $this->container->logger->info("Web Hook installation fetched", [
            "installation" => $installation
        ]);
        
        $message = trim(strip_tags($request->getParsedBody()['item']['message']['message']));
        
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
        elseif (count($words) == 2 && strtolower($words[1]) == "latest") {
            return $this->latest($installation);
        }
        else {
            $arguments = array_slice($words, 1);
            return $this->quoteSearch($installation, $arguments);
        }
    }
    
    private function randomQuote($installation) {
        $this->container->logger->info("Random quote requested");
        $tweet = $this->tweetMapper->random($installation->twitter_screenname);
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
    
    private function latest($installation) {
        $this->container->logger->info("Latest quote requested");
        $tweet = $this->tweetMapper->latest($installation->twitter_screenname);
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->message = "I couldn't find the latest tweet. Maybe I haven't had time to update my list of tweets, yet.";
            $respData->color = "red";
        }
        else {
            $respData = $this->createMessageForTweet($tweet);
        }
        
        return $respData;
    }
    
    private function quoteSearch($installation, $arguments) {
        $this->container->logger->info("Quote search requested", [ "arguments" => implode(" ", $arguments) ]);
        $tweet = $this->tweetMapper->search($installation->twitter_screenname, $arguments);
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
            . "<li><strong><code>{$installation->webhook_trigger} latest</code></strong> &ndash; The latest tweet from the monitored account</li>"
            . "<li><strong><code>{$installation->webhook_trigger} search text</code></strong> &ndash; Most recent matching quote for search text</li>"
            . "</ul>"
            . "<br />"
            . "<em>Twitter Quotes for HipChat is an <a href=\"https://github.com/cbenard/hipchat-twitter-quotes\">open source project</a> by <a href=\"https://chrisbenard.net\">Chris Benard</a></em>.";
            
        return $respData;
    }
    
    private function createMessageForTweet($tweet) {
        $user = $this->userMapper->first([ 'id' => $tweet->user_id ]);

        // Convert new lines to <br/>
        $htmlText = str_replace("\r\n", "\n", $tweet->text);
        $htmlText = str_replace("\r", "\n", $htmlText);
        $htmlText = str_replace("\n", "<br />", $htmlText);
        
        $respData = new \stdClass;
        $respData->message = "<strong><a href=\"https://twitter.com/statuses/{$tweet->tweet_id}\">@{$user->screen_name}</a></strong>: {$htmlText}";
        $respData->message_format = "html";
        
        $respData->card = new \stdClass;
        $respData->card->style = "link";
        $respData->card->description = new \stdClass;
        $respData->card->description->value = $tweet->text;
        $respData->card->description->format = "text";
        $respData->card->format = "medium";
        $respData->card->url = "https://twitter.com/statuses/{$tweet->tweet_id}";
        $respData->card->title = "{$user->name}";
        if ($user->screen_name != $user->name) {
            $respData->card->title .= " (@{$user->screen_name})";
        }
        $respData->card->id = Uuid::uuid4()->toString();
        $respData->card->icon = new \stdClass;
        $respData->card->icon->url = $user->profile_image_url_https;

        $this->container->logger->info("Created message for tweet", [ "card" => $respData->card ]);
        
        return $respData;
    }
}