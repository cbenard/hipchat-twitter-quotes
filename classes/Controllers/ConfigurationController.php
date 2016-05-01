<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class ConfigurationController {
    private $container;
    private $hipchat;
    private $csrf;
    
    public function __construct($container) {
        $this->container = $container;
        $this->hipchat = $container->hipchat;
        $this->csrf = $container->csrf;
    }
    
    public function display(Request $request, Response $response, $args) {
        $this->container->logger->info("Configuration Requested");
        $jwt = $this->container->jwt->validateRequest($request);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $jwt->iss ]);
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "screen_name" => $installation->twitter_screenname,
            "webhook_trigger" => $installation->webhook_trigger,

            "csrf_nameKey" => $this->csrf->getTokenNameKey(),
            "csrf_valueKey" => $this->csrf->getTokenValueKey(),
            "csrf_name" => $request->getAttribute($this->csrf->getTokenNameKey()),
            "csrf_value" => $request->getAttribute($this->csrf->getTokenValueKey()),
        ]);

        return $response;
    }
    
    public function update(Request $request, Response $response, $args) {
        $body = $request->getParsedBody();
        $this->container->logger->info("Update Configuration Requested", [ 'request' => $body ]);
        $jwt = $this->container->jwt->validateRequest($request);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $oauth_id = $jwt->iss;
        $installation = $mapper->first([ 'oauth_id' => $oauth_id ]);
        
        $installation->twitter_screenname = $body['screen_name'];
        $installation->webhook_trigger = $body['webhook_trigger'];
        
        $this->hipchat->registerhook($installation);
        
        $mapper->save($installation);
        
        try {
            $this->sendReconfigureMessage($installation);
        }
        catch (\Exception $e) {
            $this->container->logger->error("Error sending configuration message", [ "exception" => $e ]);
        }
        
        try {
            // Refresh tweets now
            $this->container->updatetwitterjob->update();
        }
        catch (\Exception $e) {
            $this->container->logger->error("Error fetching new tweets after reconfiguration", [ "exception" => $e ]);
        }
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "screen_name" => $installation->twitter_screenname,
            "webhook_trigger" => $installation->webhook_trigger
        ]);

        return $response;
    }
    
    private function sendReconfigureMessage($installation) {
        $message = new \stdClass;
        $message->from = "Reconfiguration";
        $message->message_format = "html";
        $message->color = "yellow";
        $message->message = "I have been reconfigured. I am now monitoring "
            . "<a href=\"https://twitter.com/{$installation->twitter_screenname}\">@{$installation->twitter_screenname}</a> "
            . "and listening for the trigger <code>{$installation->webhook_trigger}</code>.";
        
        $this->hipchat->sendRoomNotification($installation, $message);
    }
}