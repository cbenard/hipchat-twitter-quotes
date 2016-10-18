<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class WebHookController {
    private $container;
    private $jwt;
    private $installationMapper;
    private $joinMapper;
    private $tweetMapper;
    private $userMapper;
    private $db;
    private $hipchat;
    
    public function __construct($container) {
        $this->container = $container;
        $this->jwt = $container->jwt;
        $this->installationMapper = $container->db->mapper('\Entity\Installation');
        $this->joinMapper = $container->db->mapper('\Entity\InstallationTwitterUser');
        $this->userMapper = $container->db->mapper('\Entity\TwitterUser');
        $this->tweetMapper = $container->db->mapper('\Entity\Tweet');
        $this->db = $container->db;
        $this->hipchat = $container->hipchat;
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

        try {        
            $lastUpdated = $this->container->globalSettings->getLastUpdated();
            $now = new \DateTime;
            $now->modify("-1 hour");
            
            if ($lastUpdated < $now) {
                $this->container->logger->info("Running full twitter update because it's been a long time (no cron?)", [ "last_updated" => $lastUpdated ]);
                $this->container->updatetwitterjob->update();
            }
        }
        catch (\Exception $ex) {
            $this->container->logger->info("Error running full twitter update without cron", [ "last_updated" => $lastUpdated, "exception" => $ex ]);
        }

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
        $trigger = str_replace(".", "/", strtolower($words[0]));
        
        $matchingRecord = $this->joinMapper
            ->where([
                "installations_oauth_id" => $installation->oauth_id,
                "webhook_trigger" => $trigger,
                "is_active" => true
            ])
            ->active()
            ->first();
            
        if ($matchingRecord === false) {
            // We have an old hook registered somehow
            return $this->removeOldHook($installation, $trigger);
        }
        elseif (count($words) == 1) {
            return $this->randomQuote($installation, $matchingRecord);
        }
        elseif (count($words) == 2 && strtolower($words[1]) == "help") {
            return $this->usage($installation, $matchingRecord);
        }
        elseif (count($words) == 2 && strtolower($words[1]) == "latest") {
            return $this->latest($installation, $matchingRecord);
        }
        else {
            $arguments = array_slice($words, 1);
            return $this->quoteSearch($installation, $matchingRecord, $arguments);
        }
    }
    
    private function removeOldHook($installation, $trigger) {
        $respData = new \stdClass;
        $respData->color = "red";

        $this->container->logger->error("Received invalid webhook from HipChat server side", [ "trigger" => $trigger ]);

        try {
            $this->container->hipchat->removeHook($installation, $trigger);
            $respData->message = "I received a web hook ({$trigger}) that was no longer in the database. I removed it from HipChat's room configuration.";
            $respData->from = "Orphan Web Hook";
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Failed removing invalid webhook from HipChat server side", [ "trigger" => $trigger, "exception" => $ex ]);
            $respData->message = "I received a web hook ({$trigger}) that was no longer in the database. I attempted to remove it from HipChat's room configuration, but there was an error.";
            $respData->from = "Orphan Web Hook";
        }
        
        return $respData;
    }
    
    private function randomQuote($installation, $matchingRecord) {
        $this->container->logger->info("Random quote requested");
        $tweet = $this->tweetMapper->random($matchingRecord->user_id);
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->message = "I couldn't find a random tweet. Maybe I haven't had time to update my list of tweets, yet.";
            $respData->color = "red";
        }
        else {
            $respData = $this->hipchat->createMessageForTweet($tweet);
            $respData->from = "Random Quote";
        }
        
        return $respData;
    }
    
    private function latest($installation, $matchingRecord) {
        $this->container->logger->info("Latest quote requested");
        $tweet = $this->tweetMapper->latest($matchingRecord->user_id);
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->message = "I couldn't find the latest tweet. Maybe I haven't had time to update my list of tweets, yet.";
            $respData->color = "red";
        }
        else {
            $respData = $this->hipchat->createMessageForTweet($tweet);
            $respData->from = "Latest Quote";
        }
        
        return $respData;
    }
    
    private function quoteSearch($installation, $matchingRecord, $arguments) {
        $this->container->logger->info("Quote search requested", [ "arguments" => implode(" ", $arguments) ]);
        $tweet = $this->tweetMapper->search($matchingRecord->user_id, $arguments);
        $respData = new \stdClass;
        
        if (!$tweet) {
            $respData->from = "No match";
            $respData->message = "I couldn't find a tweet with that search text.";
            $respData->color = "red";
        }
        else {
            $respData = $this->hipchat->createMessageForTweet($tweet);
            $respData->from = "Quote Match";
        }
        
        return $respData;
    }
    
    private function usage($installation, $matchingRecord) {
        $respData = new \stdClass;
        $respData->from = "Help";
        $respData->message_format = "html";
        $respData->color = "yellow";
        $respData->message = "<strong>Twitter Quotes Help</strong><br/><ul>"
            . "<li><strong><code>{$matchingRecord->webhook_trigger}</code></strong> &ndash; Random quote</li>"
            . "<li><strong><code>{$matchingRecord->webhook_trigger} help</code></strong> &ndash; This help message</li>"
            . "<li><strong><code>{$matchingRecord->webhook_trigger} latest</code></strong> &ndash; The latest tweet from the monitored account</li>"
            . "<li><strong><code>{$matchingRecord->webhook_trigger} search text</code></strong> &ndash; Most recent matching quote for search text</li>"
            . "</ul>";
            
        if (count($installation->configurations->active()) > 1) {
            $accountplurality = count($installation->configurations) > 2 ? "accounts" : "account";
            $respData->message .= "<br />I also monitor the following {$accountplurality}:<ul>";
            foreach ($installation->configurations->active() as $configuration) {
                if ($configuration->id == $matchingRecord->id) {
                    continue;
                }
                else {
                    $respData->message .= "<li><a href=\"https://twitter.com/{$configuration->user->screen_name}\">@{$configuration->user->screen_name}</a> "
                        . "&ndash; <strong><code>{$configuration->webhook_trigger}</code></strong></li>";
                }
            }
            $respData->message .= "</ul>";
        }
            
        $respData->message .= "<br />"
            . "<em>Twitter Quotes for HipChat is an <a href=\"https://github.com/cbenard/hipchat-twitter-quotes\">open source project</a> by <a href=\"https://chrisbenard.net\">Chris Benard</a></em>.";
            
        return $respData;
    }
}