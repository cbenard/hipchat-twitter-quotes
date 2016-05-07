<?php

namespace cbenard;

class OrphanCleanupJob {
    private $db;
    private $container;
    private $logger;
    
    public function __construct($container) {
        $this->container = $container;
        $this->logger = $container->logger;
        $this->db = $container->config['db']['main'];
    }
    
    public function cleanup() {
        $driver = null;
        if (0 !== strpos($this->db['driver'], "pdo_")) {
            $this->logger->error("Orphan cleanup requested but driver is unsupported", [ "driver" => $this->db['driver'] ]);
            return false;
        }
        $driver = substr($this->db['driver'], 4);
        
        try {
            $oneWeekAgo = new \DateTime;
            $oneWeekAgo->modify("-1 week");
            $conn = new \PDO("{$driver}:host={$this->db['host']};dbname={$this->db['name']}", $this->db['user'], $this->db['password']);

            $mappingCleanup = "delete a from installations_twitter_users a "
                . "left join installations b on a.installations_oauth_id = b.oauth_id "
                . "where b.oauth_id IS NULL OR (is_active = 0 AND a.updated_on < :updated_on)";
            $conn->prepare($mappingCleanup)->execute([ "updated_on" => $oneWeekAgo->format("Y-m-d H:i:s") ]);
            
            $accountCleanup = "delete a from twitter_users a "
                . "left join installations_twitter_users b on a.screen_name = b.screen_name "
                . "where b.screen_name is null";
            $conn->exec($accountCleanup);
            
            $tweetCleaup = "delete a from tweets a "
                . "left join twitter_users b on a.screen_name = b.screen_name "
                . "where b.screen_name is null";
            $conn->exec($tweetCleaup);
            
            return true;
        }
        catch (\Exception $ex) {
            $this->logger->warning("Error running orphan cleanup", [ "exception" => $ex ]);
        }
        
        return false;
    }
}