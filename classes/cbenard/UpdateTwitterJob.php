<?php

namespace cbenard;

class UpdateTwitterJob {
    private $container;
    private $db;
    private $twitter;
    private $hipchat;
    private $globalSettings;
    private $consoleLogger;
    
    public function __construct($container, $consoleLogger = null) {
        $this->container = $container;
        $this->db = $container->db;
        $this->twitter = $container->twitter;
        $this->hipchat = $container->hipchat;
        $this->globalSettings = $container->globalSettings;
        $this->consoleLogger = $consoleLogger;
    }
    
    public function update() {
        $twitter_token = $this->globalSettings->getTwitterToken();
        
        if ($twitter_token) {
            $this->log("Using previous Twitter bearer token.\r\n");
            $this->twitter->setBearerToken($twitter_token);
        }

        $accounts = $this->getAccounts();
        
        foreach ($accounts as $twitter_screenname) {
            $newCount = $this->updateTweets($twitter_screenname);
            if ($newCount) {
                $this->updateAccountInformation($twitter_screenname);
            }
        }

        if (!$twitter_token && $this->twitter->getBearerToken()) {
            $this->log("Saving new Twitter bearer token...");
            $this->globalSettings->setTwitterToken($this->twitter->getBearerToken());
            $this->log("Done.\r\n");
        }
    }
    
    private function getAccounts() {
        $accounts = [];
        $this->log("Fetching Twitter accounts from installations...");
        $mapper = $this->db->mapper('\Entity\Installation');
        $installations = $mapper->all()->where(['twitter_screenname !=' => null]);
        if (!$installations || count($installations) > 0) {
            $accounts = array_map(function ($item) {
                return $item['twitter_screenname'];
            }, $installations->toArray());
            $accounts = array_unique($accounts);
        }
        $this->log("Done (" . count($accounts). ").\r\n");
        
        return $accounts;
    }
    
    private function updateAccountInformation($twitter_screenname) {
        $currentInfo = $this->twitter->getUserInfo($twitter_screenname);
        $this->log("Retrieved information for @{$twitter_screenname}...");
        
        $mapper = $this->db->mapper('\Entity\TwitterUser');
        $currentRecord = $mapper->first([ 'screen_name' => $twitter_screenname ]);
        if (!$currentRecord) {
            $currentRecord = $mapper->build([ 'screen_name' => $twitter_screenname ]);
        }
        
        $currentRecord->id = $currentInfo->id;
        $currentRecord->profile_image_url_https = $currentInfo->profile_image_url_https;
        $currentRecord->name = $currentInfo->name;
        
        $mapper->save($currentRecord);
        $this->log("Saved.\r\n");
    }
    
    private function updateTweets($twitter_screenname) {
        $mapper = $this->db->mapper('\Entity\Tweet');
        $max_tweet = $mapper->latest($twitter_screenname);
        $max_tweet_id = $max_tweet ? $max_tweet->tweet_id : null;
        
        $tweets = $this->twitter->getTweetsSince($twitter_screenname, $max_tweet_id);
        $this->log("Retrieved tweets for @{$twitter_screenname}...");

        $initialCount = count($tweets);
        $mapper->saveTweets($twitter_screenname, $tweets);        
        $this->log("Saved {$initialCount}.\r\n");

        $backfillCount = 0;
        
        if ($max_tweet) {
            $min_tweet = $mapper->earliest($twitter_screenname);
            
            if ($min_tweet) {
                $backfillTweets = $this->twitter->getTweetsBefore($twitter_screenname, $min_tweet->tweet_id);
                $this->log("Retrieving backfill tweets for @{$twitter_screenname}...");

                $backfillCount = count($backfillTweets);
                $mapper->saveTweets($twitter_screenname, $backfillTweets, $mapper);
                $this->log("Saved {$backfillCount}.\r\n");
            }
        }
        
        try {
            if ($initialCount) {
                // Disabled for now. User feedback said this was annoying. May be configurable later.
                // $this->sendUpdatedNotification($twitter_screenname, $initialCount, $backfillCount);
            }
        }
        catch (\Exception $e) {
            $this->log("Error sending updated notification: " . $e);
            $this->container->logger->error("Error sending updated notification", [ "exception" => $e ]);
        }
        
        return $tweets ? $initialCount : false;
    }
    
    private function sendUpdatedNotification($twitter_screenname, $count, $backfillCount) {
        $mapper = $this->db->mapper('\Entity\Installation');
        $installations = $mapper->all([ 'twitter_screenname' => $twitter_screenname ]);
        
        if ($installations) {
            foreach ($installations as $installation) {
                $message = new \stdClass;
                $message->from = "New Tweet" . ($count != 1 ? "s" : "");
                $message->message_format = "html";
                $message->color = "yellow";
                $tweetplurality = $count != 1 ? "new tweets have" : "a new tweet has";
                $itistheyare = $count != 1 ? "They are" : "It is";
                $message->message = "I have noticed that {$tweetplurality} been added by "
                    . "<a href=\"https://twitter.com/{$installation->twitter_screenname}\">@{$installation->twitter_screenname}</a>. "
                    . "{$itistheyare} now available for use with this integration.<br /><br />"
                    . "Try typing <strong><code>{$installation->webhook_trigger} latest</code></strong> to see the latest tweet.";
                
                $this->hipchat->sendRoomNotification($installation, $message);
            }
        }
    }
    
    private function log($message) {
        if (is_callable($this->consoleLogger)) {
            call_user_func($this->consoleLogger, $message);
        }
    }
}