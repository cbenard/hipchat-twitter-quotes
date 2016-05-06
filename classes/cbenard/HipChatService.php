<?php

namespace cbenard;

class HipChatService {
    private $container;
    private $db;
    private $installationMapper;
    private $logger;
    
    public function __construct($container) {
        $this->container = $container;
        $this->db = $this->container->db;
        $this->installationMapper = $this->db->mapper('\Entity\Installation');
        $this->logger = $this->container->logger;
    }
    
    public function registerHook($installation, $webhook_trigger) {
        $this->ensureToken($installation);
        $sanitized_trigger = str_replace("/", "", $webhook_trigger);
        $uri = "room/{$installation->room_id}/extension/webhook/twitterquote_{$sanitized_trigger}";
        
        $request = new \stdClass;
        $request->name = "Twitter Quote Trigger";
        $request->authentication = "jwt";
        $request->key = "twitterquote_{$sanitized_trigger}";
        $request->event = "room_message";
        $request->pattern = $this->getHookPattern($webhook_trigger);
        $request->url = $this->container->config['global']['baseUrl'] . "/webhook";
        
        $this->logger->info("Registering hook", [ "roomID" => $installation->room_id, "trigger" => "twitterquote_{$sanitized_trigger}", "request" => $request ]);
        
        $response = \Httpful\Request::put($installation->api_url . $uri)
            ->addHeader("Authorization", "Bearer " . $installation->access_token)
            ->body($request)
            ->sendsJson()
            ->expectsJson()
            ->send();
            
        $this->logger->info("Register hook response received", [ "response" => $response ]);
    }
    
    public function removeHook($installation, $webhook_trigger) {
        $this->ensureToken($installation);
        $sanitized_trigger = str_replace("/", "", $webhook_trigger);
        $uri = "room/{$installation->room_id}/extension/webhook/twitterquote_{$sanitized_trigger}";
        
        $this->logger->info("Deleting hook", [ "roomID" => $installation->room_id, "trigger" => "twitterquote_{$sanitized_trigger}" ]);
        
        $response = \Httpful\Request::delete($installation->api_url . $uri)
            ->addHeader("Authorization", "Bearer " . $installation->access_token)
            ->send();
            
        $this->logger->info("Delete hook response received", [ "response" => $response ]);
    }
    
    public function sendRoomNotification($installation, $message) {
        $this->ensureToken($installation);
        $uri = "room/{$installation->room_id}/notification";
        
        $request = $message;
        
        $this->logger->info("Sending room notification", [ "request" => $request ]);
        
        $response = \Httpful\Request::post($installation->api_url . $uri)
            ->addHeader("Authorization", "Bearer " . $installation->access_token)
            ->body($request)
            ->sendsJson()
            ->expectsJson()
            ->send();
            
        $this->logger->info("Send room notification response received", [ "response" => $response ]);
    }
    
    private function getHookPattern($hookText) {
        $hookText = trim($hookText, " ./\r\n\t\0\x0B");
        
        $hookRegex = "^[/.]";
        
        foreach (str_split($hookText) as $char) {
            $hookRegex .= "[" . strtolower($char) . strtoupper($char) . "]";
        }
        
        $hookRegex .= "(\$| )";
        
        return $hookRegex;
    }
    
    private function refreshAccessToken($installation) {
        $uri = $installation->token_url;
        $request = new \stdClass;
        $request->grant_type = "client_credentials";
        
        $response = \Httpful\Request::post($uri)
            ->authenticateWith($installation->oauth_id, $installation->oauth_secret)
            ->body($request)
            ->sendsForm()
            ->expectsJson()
            ->send();
            
       $this->logger->info("Token response received", [ "response" => $response ]);

       $expSeconds = $response->body->expires_in - 60;
       $exp = new \DateTime;
       $exp->modify("+{$expSeconds} seconds");
       
       $installation->access_token = $response->body->access_token;
       $installation->access_token_expiration = $exp;
       $this->installationMapper->save($installation);
       
       return $response;
    }
    
    private function isExpired($expirationDateTime) {
        return $expirationDateTime < new \DateTime;
    }

    private function ensureToken($installation) {
        if (!$installation->access_token || $this->isExpired($installation->access_token_expiration)) {
            $this->refreshAccessToken($installation);
        }
        
        return $installation;
    }
}