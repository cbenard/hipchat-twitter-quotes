<?php

namespace cbenard;

class UpdateTwitterJob {
    private $container;
    private $db;
    private $twitter;
    private $hipchat;
    private $globalSettings;
    private $consoleLogger;
    private $utc;
    
    public function __construct($container, $consoleLogger = null) {
        $this->container = $container;
        $this->db = $container->db;
        $this->twitter = $container->twitter;
        $this->hipchat = $container->hipchat;
        $this->globalSettings = $container->globalSettings;
        $this->consoleLogger = $consoleLogger;
        $this->utc = new \DateTimeZone("UTC");
    }

    public function updateSingle($installation, $screen_name, $backfill = false, $suppress_notification = true) {
        $twitter_token = $this->globalSettings->getTwitterToken();
        
        if ($twitter_token) {
            $this->log("Using previous Twitter bearer token.\r\n");
            $this->twitter->setBearerToken($twitter_token);
        }

        $this->updateAccountInformation($screen_name);
        $this->updateTweetsByName($screen_name, $backfill, $suppress_notification);

        if (!$twitter_token && $this->twitter->getBearerToken()) {
            $this->log("Saving new Twitter bearer token...");
            $this->globalSettings->setTwitterToken($this->twitter->getBearerToken());
            $this->log("Done.\r\n");
        }
    }
    
    public function updateAll($backfill = true, $suppress_notification = true) {
        $twitter_token = $this->globalSettings->getTwitterToken();
        
        if ($twitter_token) {
            $this->log("Using previous Twitter bearer token.\r\n");
            $this->twitter->setBearerToken($twitter_token);
        }

        $accounts = $this->getAccounts();
        
        foreach ($accounts as $user_id) {
            try {
                $newCount = $this->updateTweetsByID($user_id, $backfill);

                if (!$newCount) {
                    // If there are no new tweets to use for updates and
                    //   account hasn't updated in > 1hr, update account info
                    $now = new \DateTime;
                    $now->modify("-1 hour");
                    $mapper = $this->db->mapper('\Entity\TwitterUser');
                    $currentRecord = $mapper->get($user_id);
                    
                    if ($currentRecord->updated_on < $now) {
                        $this->updateAccountInformation(null, $user_id);
                    }
                }
            }
            catch (\Exception $ex) {
                $this->log("Unable to fetch information for ID: ". $user_id);
                $this->container->logger->error("Unable to run twitter update job", [ "screen_name" => $twitter_screenname, "exception" => $ex ]);
            }
        }
        
        $this->globalSettings->setLastUpdated();
        
        $this->log("Running orphan cleanup...");
        $orphanCleanupSuccess = $this->container->orphan->cleanup();
        $this->log(($orphanCleanupSuccess ? "Success" : "Failed") . ".\r\n");

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
                return $item['user_id'];
            }, $joins->toArray());
            $accounts = array_unique($accounts);
        }
        $this->log("Done (" . count($accounts). ").\r\n");
        
        return $accounts;
    }
    
    private function updateAccountInformation($twitter_screenname, $twitter_user_id = null, $currentInfo = null) {
        if (!$currentInfo) {
            if ($twitter_user_id) {
                $currentInfo = $this->twitter->getUserInfoByID($twitter_user_id);
            }
            else {
                $currentInfo = $this->twitter->getUserInfoByName($twitter_screenname);
            }
        }
        $this->log("Retrieved information for @{$currentInfo->screen_name}...");
        
        $mapper = $this->db->mapper('\Entity\TwitterUser');
        $currentRecord = $mapper->get($currentInfo->id);
        if (!$currentRecord) {
            $currentRecord = $mapper->build([ 'user_id' => $currentInfo->id ]);
        }
        
        $currentRecord->screen_name = $currentInfo->screen_name;
        $currentRecord->profile_image_url_https = $currentInfo->profile_image_url_https;
        $currentRecord->name = $currentInfo->name;
        
        $mapper->save($currentRecord);
        $this->log("Saved.\r\n");
    }
    
    private function updateTweetsByName($twitter_screenname, $backfill = true, $suppress_notification = false) {
        return $this->_updateTweets($twitter_screenname, null, $backfill, $suppress_notification);
    }
    
    private function updateTweetsByID($user_id, $backfill = true, $suppress_notification = false) {
        return $this->_updateTweets(null, $user_id, $backfill, $suppress_notification);
    }
    
    private function _updateTweets($twitter_screenname, $twitter_user_id, $backfill = true, $suppress_notification = false) {
        if (!$twitter_user_id) {
            $mapper = $this->db->mapper('\Entity\TwitterUser');
            $temp_user = $mapper->first([ 'screen_name' => $twitter_screenname]);
            $twitter_user_id = $temp_user->user_id;
        }
        if (!$twitter_screenname) {
            $mapper = $this->db->mapper('\Entity\TwitterUser');
            $temp_user = $mapper->get($twitter_user_id);
            $twitter_screenname = $temp_user->screen_name;
        }

        $mapper = $this->db->mapper('\Entity\Tweet');
        $max_tweet = $mapper->latest($twitter_user_id);
        $max_tweet_id = $max_tweet ? $max_tweet->tweet_id : null;
        
        $tweets = $this->twitter->getTweetsSince($twitter_user_id, $max_tweet_id);
        $this->log("Retrieved tweets for @{$twitter_screenname}...");

        $initialCount = count($tweets);
        $mapper->saveTweets($tweets);        
        $this->log("Saved {$initialCount}.\r\n");

        $tweetToUseForUserUpdate = null;
        if ($initialCount) {
            reset($tweets);
            $tweetToUseForUserUpdate = $tweets[key($tweets)];
        }

        $backfillCount = 0;
        
        if ($max_tweet) {
            $min_tweet = $mapper->earliest($twitter_user_id);
            
            if ($min_tweet && $backfill) {
                $backfillTweets = $this->twitter->getTweetsBefore($twitter_user_id, $min_tweet->tweet_id);
                $this->log("Retrieving backfill tweets for @{$twitter_screenname}...");

                $backfillCount = count($backfillTweets);
                $mapper->saveTweets($backfillTweets);
                $this->log("Saved {$backfillCount}.\r\n");

                if ($backfillCount && !$tweetToUseForUserUpdate) {
                    reset($backfillTweets);
                    $tweetToUseForUserUpdate = $backfillTweets[key($backfillTweets)];
                }
            }
        }
        
        if ($tweetToUseForUserUpdate) {
            $currentInfo = (object)array(
                'created_at' => new \DateTime($tweetToUseForUserUpdate->user->created_at, $this->utc),
                'id' => $tweetToUseForUserUpdate->user->id_str,
                'profile_image_url_https' => $tweetToUseForUserUpdate->user->profile_image_url_https,
                'name' => $tweetToUseForUserUpdate->user->name,
                'screen_name' => $tweetToUseForUserUpdate->user->screen_name
            );

            $this->updateAccountInformation(null, null, $currentInfo);
            $this->log("Updated user information for @{$currentInfo->screen_name}...");
        }

        if ($initialCount && !$suppress_notification) {
            $this->sendUpdatedNotification($twitter_user_id, $initialCount, $backfillCount);
        }
        
        return $tweets ? $initialCount : false;
    }
    
    private function sendUpdatedNotification($twitter_user_id, $count, $backfillCount) {
        $maxNumber = 3;
        $mapper = $this->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $mapper->where([
            'user_id' => $twitter_user_id,
            'is_active' => true,
            'notify_new_tweets' => true,
        ]);
        
        $tweetMapper = $this->db->mapper('\Entity\Tweet');
        $tweets = $tweetMapper
            ->where([ 'user_id' => $twitter_user_id ])
            ->order([ 'created_at' => 'DESC' ])
            ->limit(min([ $maxNumber, $count ]));

        // Chronological order up to 3
        $reverseTweets = [];
        foreach ($tweets as $tweet) {
            array_unshift($reverseTweets, $tweet);
        }

        if ($configurations) {
            foreach ($configurations as $configuration) {
                try {
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