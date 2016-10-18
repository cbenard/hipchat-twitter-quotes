<?php

namespace Entity\Mappers;
use \Spot\Mapper;

class TweetMapper extends Mapper {
    public function latest($screen_name) {
        return $this->tweetsForUser($screen_name)
            ->order([ 'created_at' => 'DESC', 'tweet_id' => 'DESC' ])
            ->first();
    }
    
    public function earliest($screen_name) {
        return $this->tweetsForUser($screen_name)
            ->order([ 'created_at' => 'ASC', 'tweet_id' => 'ASC' ])
            ->first();
    }
    
    public function saveTweets($tweets) {
        foreach ($tweets as $tweet) {
            $this->create([
                'tweet_id' => $tweet->id,
                'created_at' => $tweet->created_at,
                'text' => $tweet->text,
                'user_id' => $tweet->user->id_str,
            ]);
        }
    }
    
    public function random($twitter_user_id) {
        return $this->query("SELECT * FROM `tweets` WHERE `user_id` = ? ORDER BY rand() LIMIT 1",
                [ $twitter_user_id ])
            ->first();
    }
    
    public function search($twitter_user_id, $arguments) {
        $argString = htmlspecialchars(implode(" ", $arguments));

        // Try for a direct match
        $tweet = $this
            ->query("SELECT * FROM `tweets` "
                . "WHERE `text` LIKE ? "
                . "AND `user_id` = ? "
                . "ORDER BY created_at DESC LIMIT 1", [ "%{$argString}%", $twitter_user_id ])
            ->first();
            
        if (!$tweet) {
            // Try with words in order
            $likeArgs = htmlspecialchars(implode("%", $arguments));
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE `text` LIKE ? "
                    . "AND `user_id` = ? "
                    . "ORDER BY created_at DESC LIMIT 1", [ "%{$likeArgs}%", $twitter_user_id ])
                ->first();
        }
            
        if (!$tweet) {
            // Try with all words in any order
            $likeArgs = array_map(function($item) { return htmlspecialchars("%{$item}%"); }, $arguments);
            array_unshift($likeArgs, $twitter_user_id);
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE `user_id` = ? "
                    . str_repeat(" AND `text` LIKE ? ", count($likeArgs) - 1)
                    . "ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
            
        if (!$tweet) {
            // Try with any words in any order
            $likeArgs = array_map(function($item) { return htmlspecialchars("%{$item}%"); }, $arguments);
            array_push($likeArgs, $twitter_user_id);
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE ("
                    . substr(str_repeat(" OR `text` LIKE ? ", count($likeArgs) - 1), 4)
                    . ") "
                    . "AND `user_id` = ? "
                    ."ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
        
        return $tweet;
    }
    
    private function tweetsForUser($twitter_user_id) {
        return $this->where([ 'user_id' => $twitter_user_id ]);
    }
}