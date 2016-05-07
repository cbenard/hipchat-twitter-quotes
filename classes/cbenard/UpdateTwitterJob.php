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
    
    public function update($screen_name = null, $backfill = true) {
        $twitter_token = $this->globalSettings->getTwitterToken();
        
        if ($twitter_token) {
            $this->log("Using previous Twitter bearer token.\r\n");
            $this->twitter->setBearerToken($twitter_token);
        }

        if ($screen_name) {
            $this->updateAccountInformation($screen_name);
            $this->updateTweets($screen_name, $backfill);
        }
        else {
            $accounts = $this->getAccounts();
            
            foreach ($accounts as $twitter_screenname) {
                try {
                    $newCount = $this->updateTweets($twitter_screenname, $backfill);
                    if ($newCount) {
                        $this->updateAccountInformation($twitter_screenname);
                    }
                }
                catch (\Exception $ex) {
                    $this->log("Unable to fetch information for: ". $twitter_screenname);
                    $this->container->logger->error("Unable to run twitter update job", [ "screen_name" => $twitter_screenname, "exception" => $ex ]);
                }
            }
            
            $this->globalSettings->setLastUpdated();
            
            $this->log("Running orphan cleanup...");
            $orphanCleanupSuccess = $this->container->orphan->cleanup();
            $this->log(($orphanCleanupSuccess ? "Success" : "Failed") . ".\r\n");
        }

        if (!$twitter_token && $this->twitter->getBearerToken()) {
            $this->log("Saving new Twitter bearer token...");
            $this->globalSettings->setTwitterToken($this->twitter->getBearerToken());
            $this->log("Done.\r\n");
        }
    }
    
    private function getAccounts() {
        $accounts = [];
        $this->log("Fetching Twitter accounts from active installation mappings...");
        $mapper = $this->db->mapper('\Entity\InstallationTwitterUser');
        $joins = $mapper->all()->active();
        if (!$joins || count($joins) > 0) {
            $accounts = array_map(function ($item) {
                return $item['screen_name'];
            }, $joins->toArray());
            $accounts = array_unique($accounts);
        }
        $this->log("Done (" . count($accounts). ").\r\n");
        
        return $accounts;
    }
    
    private function updateAccountInformation($twitter_screenname) {
        $currentInfo = $this->twitter->getUserInfo($twitter_screenname);
        $this->log("Retrieved information for @{$twitter_screenname}...");
        
        $mapper = $this->db->mapper('\Entity\TwitterUser');
        $currentRecord = $mapper->get($twitter_screenname);
        if (!$currentRecord) {
            $currentRecord = $mapper->build([ 'screen_name' => $twitter_screenname ]);
        }
        
        $currentRecord->user_id = $currentInfo->id;
        $currentRecord->profile_image_url_https = $currentInfo->profile_image_url_https;
        $currentRecord->name = $currentInfo->name;
        
        $mapper->save($currentRecord);
        $this->log("Saved.\r\n");
    }
    
    private function updateTweets($twitter_screenname, $backfill = true) {
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
            
            if ($min_tweet && $backfill) {
                $backfillTweets = $this->twitter->getTweetsBefore($twitter_screenname, $min_tweet->tweet_id);
                $this->log("Retrieving backfill tweets for @{$twitter_screenname}...");

                $backfillCount = count($backfillTweets);
                $mapper->saveTweets($twitter_screenname, $backfillTweets, $mapper);
                $this->log("Saved {$backfillCount}.\r\n");
            }
        }
        
        if ($initialCount) {
            $this->sendUpdatedNotification($twitter_screenname, $initialCount, $backfillCount);
        }
        
        return $tweets ? $initialCount : false;
    }
    
    private function sendUpdatedNotification($twitter_screenname, $count, $backfillCount) {
        $maxNumber = 3;
        $mapper = $this->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $mapper->where([
            'screen_name' => $twitter_screenname,
            'is_active' => true,
            'notify_new_tweets' => true,
        ]);
        
        if ($configurations) {
            foreach ($configurations as $configuration) {
                $tweetMapper = $this->db->mapper('\Entity\Tweet');
                $tweets = $tweetMapper
                    ->where([ 'screen_name' => $configuration->screen_name ])
                    ->order([ 'created_at' => 'DESC' ])
                    ->limit(min([ $maxNumber, $count ]));
                    
                try {
                    // Chronological order up to 3
                    $reverseTweets = [];
                    foreach ($tweets as $tweet) {
                        array_unshift($reverseTweets, $tweet);
                    }
                    
                    foreach ($reverseTweets as $tweet) {
                        $tweet = (object)$tweet;
                        $message = $this->hipchat->createMessageForTweet($tweet);
                        $message->from = "New Tweet";
                        $this->hipchat->sendRoomNotification($configuration->installation, $message);
                    }

                    if ($count > $maxNumber) {
                        $remainder = $count - $maxNumber;
                        
                        $message = new \stdClass;
                        $message->from = "New Tweet" . ($count != 1 ? "s" : "");
                        $message->message_format = "html";
                        $message->color = "yellow";
                        $tweetplurality = $remainder > 1 ? "tweets were" : "tweet was";
                        $message->message = "{$remainder} more {$tweetplurality} imported from <a href=\"https://twitter.com/{$configuration->screen_name}\">@{$configuration->screen_name}</a> but not displayed.";
                        
                        $this->hipchat->sendRoomNotification($configuration->installation, $message);
                    }
                }
                catch (\Exception $e) {
                    $this->log("Error sending updated notification: " . $e);
                    $this->container->logger->error("Error sending updated notification", [ "exception" => $e ]);
                }
            }
        }
    }
    
    private function log($message) {
        if (is_callable($this->consoleLogger)) {
            call_user_func($this->consoleLogger, $message);
        }
    }
}