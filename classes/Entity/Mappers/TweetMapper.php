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
    
    public function saveTweets($screen_name, $tweets) {
        foreach ($tweets as $tweet) {
            $this->create([
                'tweet_id' => $tweet->id,
                'created_at' => $tweet->created_at,
                'text' => $tweet->text,
                'user_id' => $tweet->user_id,
                'screen_name' => $screen_name
            ]);
        }
    }
    
    public function random($screen_name) {
        return $this->query("SELECT * FROM `tweets` WHERE `screen_name` = ? ORDER BY rand() LIMIT 1",
                [ $screen_name ])
            ->first();
    }
    
    public function search($screen_name, $arguments) {
        $argString = htmlspecialchars(implode(" ", $arguments));

        // Try for a direct match
        $tweet = $this
            ->query("SELECT * FROM `tweets` "
                . "WHERE `text` LIKE ? "
                . "AND `screen_name` = ? "
                . "ORDER BY created_at DESC LIMIT 1", [ "%{$argString}%", $screen_name ])
            ->first();
            
        if (!$tweet) {
            // Try with words in order
            $likeArgs = htmlspecialchars(implode("%", $arguments));
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE `text` LIKE ? "
                    . "AND `screen_name` = ? "
                    . "ORDER BY created_at DESC LIMIT 1", [ "%{$likeArgs}%", $screen_name ])
                ->first();
        }
            
        if (!$tweet) {
            // Try with all words in any order
            $likeArgs = array_map(function($item) { return htmlspecialchars("%{$item}%"); }, $arguments);
            array_unshift($likeArgs, $screen_name);
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE `screen_name` = ? "
                    . str_repeat(" AND `text` LIKE ? ", count($likeArgs) - 1)
                    . "ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
            
        if (!$tweet) {
            // Try with any words in any order
            $likeArgs = array_map(function($item) { return htmlspecialchars("%{$item}%"); }, $arguments);
            array_push($likeArgs, $screen_name);
            $tweet = $this
                ->query("SELECT * FROM `tweets` "
                    . "WHERE ("
                    . substr(str_repeat(" OR `text` LIKE ? ", count($likeArgs) - 1), 4)
                    . ") "
                    . "AND `screen_name` = ? "
                    ."ORDER BY created_at DESC LIMIT 1", $likeArgs)
                ->first();
        }
        
        return $tweet;
    }
    
    private function tweetsForUser($screen_name) {
        return $this->where([ 'screen_name' => $screen_name ]);
    }
}