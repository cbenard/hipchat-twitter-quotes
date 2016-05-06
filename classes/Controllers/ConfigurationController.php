<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class ConfigurationController {
    private $container;
    private $hipchat;
    private $csrf;
    private $configureValidation;
    
    public function __construct($container) {
        $this->container = $container;
        $this->hipchat = $container->hipchat;
        $this->csrf = $container->csrf;
        $this->configureValidation = $container->configureValidation;
    }
    
    public function display(Request $request, Response $response, $args) {
        $this->container->logger->info("Configuration Requested");
        $jwt = null;
        
        try {
            $jwt = $this->container->jwt->validateRequest($request);
        }
        catch (\Firebase\JWT\ExpiredException $ex) {
            $this->container->logger->info("Expired JWT token on configuration", [ "exception" => $ex, "request" => $request ]);
            $returnParameters = [
                "errors" => [ "Authentication information expired. Please refresh." ],
                "dont_display_form" => true
            ];
            return $this->container->view->render($response, "configure.phtml", $returnParameters)
                ->withStatus(403);
        }
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->get($jwt->iss);

        $joinMapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $joinMapper->where([ 'installations_oauth_id' => $jwt->iss ])->active();
        $this->container->logger->info("configurations", [ "oauth_id" => $jwt->iss, "configurations" => $configurations->toArray() ]);
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "errors" => null,
            "success" => null,
            "configurations" => $configurations,
            
            "screen_name_new" => null,
            "webhook_trigger_new" => null,
            "notify_new_tweets_new" => true,

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
        $jwt = null;
        
        try {
            $jwt = $this->container->jwt->validateRequest($request);
        }
        catch (\Firebase\JWT\ExpiredException $ex) {
            $this->container->logger->info("Expired JWT token on configuration", [ "exception" => $ex, "request" => $request ]);
            $returnParameters = [
                "errors" => [ "Authentication information expired. Please refresh." ],
                "dont_display_form" => true
            ];
            return $this->container->view->render($response, "configure.phtml", $returnParameters)
                ->withStatus(403);
        }
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->get($jwt->iss);

        $joinMapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $joinMapper->where([ 'installations_oauth_id' => $jwt->iss ])->active();
        
        $returnParameters = [
            "errors" => $this->getFlattenedErrors($this->configureValidation->getErrors()),
            "success" => null,
            "configurations" => $configurations,
            "saved_screen_name" => null,

            "screen_name_new" => null,
            "webhook_trigger_new" => null,
            "notify_new_tweets_new" => true,

            "csrf_nameKey" => $this->csrf->getTokenNameKey(),
            "csrf_valueKey" => $this->csrf->getTokenValueKey(),
            "csrf_name" => $request->getAttribute($this->csrf->getTokenNameKey()),
            "csrf_value" => $request->getAttribute($this->csrf->getTokenValueKey()),
        ];
        
        $saveID = null;
        foreach ($body as $key => $value) {
            if (strlen($key) > 5 && substr($key, 0, 5) == "save_") {
                $saveID = intval(substr($key, 5));
                break;
            }
        }

        $deleteID = null;
        foreach ($body as $key => $value) {
            if (strlen($key) > 7 && substr($key, 0, 7) == "delete_") {
                $deleteID = intval(substr($key, 7));
                break;
            }
        }
        
        if (!$this->configureValidation->hasErrors()) {
            if (isset($body['save_new']) && $body['save_new']) {
                $returnParameters = $this->addNew($installation, $body, $request, $response, $args, $returnParameters);
            }
            elseif ($saveID != null) {
                $returnParameters = $this->saveExisting($installation, $saveID, $body, $request, $response, $args, $returnParameters);
            }
            elseif ($deleteID != null) {
                $returnParameters = $this->deleteExisting($installation, $deleteID, $body, $request, $response, $args, $returnParameters);
            }
            else {
                $this->container->logger->error("Unable to determine save type on configuration", [ "body" => $body ]);
                throw new \Exception("Unable to determine save type on configuration.");
            }
            
            try {
                $this->sendReconfigureMessage($installation);
            }
            catch (\Exception $e) {
                $this->container->logger->error("Error sending configuration message", [ "exception" => $e ]);
            }
            
            if ($returnParameters['saved_screen_name']) {
                try {
                    // Refresh tweets now
                    $this->container->updatetwitterjob->update($returnParameters['saved_screen_name']);
                }
                catch (\Exception $e) {
                    $this->container->logger->error("Error fetching new tweets after reconfiguration", [ "exception" => $e ]);
                }
            }
        }
        else {
            $returnParameters["screen_name_new"] = $body['screen_name_new'];
            $returnParameters["webhook_trigger_new"] = $body['webhook_trigger_new'];
            $returnParameters["notify_new_tweets_new"] = $body['notify_new_tweets_new'];
        }
        
        $response = $this->container->view->render($response, "configure.phtml", $returnParameters);

        return $response;
    }
    
    private function addNew($installation, $body, Request $request, Response $response, $args, $returnParameters) {
        $mapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $screen_name = strtolower(trim($body['screen_name_new']));
        $webhook_trigger = strtolower(trim($body['webhook_trigger_new']));
        $notify_new_tweets = isset($body['notify_new_tweets_new']) && $body['notify_new_tweets_new'] == "on" ? true : false;

        try {
            $mapper->addNew($installation->oauth_id, $screen_name, $webhook_trigger, $notify_new_tweets);
            $this->hipchat->registerHook($installation, $webhook_trigger);
            $returnParameters["success"] = "Now following @{$screen_name} with {$webhook_trigger}";
            $returnParameters["saved_screen_name"] = $screen_name;
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Error adding new configuration", [ "exception" => $ex ]);
            $returnParameters["errors"] = [ "There was an error adding the new configuration." ];
            
            $returnParameters["screen_name_new"] = $body['screen_name_new'];
            $returnParameters["webhook_trigger_new"] = $body['webhook_trigger_new'];
            $returnParameters["notify_new_tweets_new"] = isset($body['notify_new_tweets_new']) ? $body['notify_new_tweets_new'] : null;
        }
        
        return $returnParameters;
    }
    
    private function saveExisting($installation, $saveID, $body, Request $request, Response $response, $args, $returnParameters) {
        $mapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $old = $mapper->get($saveID);
        
        $screen_name = strtolower(trim($body['screen_name_' . $saveID]));
        $webhook_trigger = strtolower(trim($body['webhook_trigger_' . $saveID]));
        $notify_new_tweets = $body['notify_new_tweets_' . $saveID] == "on" ? true : false;

        try {
            $mapper->updateExisting($saveID, $installation->oauth_id, $screen_name, $webhook_trigger, $notify_new_tweets);
            if ($old->webhook_trigger != $webhook_trigger) {
                $this->hipchat->removeHook($installation, $old->webhook_trigger);
            }
            $this->hipchat->registerHook($installation, $webhook_trigger);
            $returnParameters["success"] = "Updated @{$screen_name} with {$webhook_trigger}";
            $returnParameters["saved_screen_name"] = $screen_name;
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Error saving existing configuration", [ "exception" => $ex ]);
            $returnParameters["errors"] = [ "There was an error saving the existing configuration." ];
        }
        
        return $returnParameters;
    }
    
    private function deleteExisting($installation, $deleteID, $body, Request $request, Response $response, $args, $returnParameters) {
        try {
            $mapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
            $old = $mapper->get($deleteID);
            $mapper->tombstone($deleteID);
            $this->hipchat->removeHook($installation, $old->webhook_trigger);
            $returnParameters["success"] = "Deleted monitoring of @{$old->screen_name} with {$old->webhook_trigger}";
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Error saving existing configuration", [ "exception" => $ex ]);
            $returnParameters["errors"] = [ "There was an error saving the existing configuration." ];
        }
        
        return $returnParameters;
    }
    
    private function getFlattenedErrors($validationErrors) {
        $retErrors = [];
        
        foreach ($validationErrors as $elementName => $subErrors) {
            foreach ($subErrors as $error){
                array_push($retErrors, (object)[ "name" => $elementName, "value" => $error ]);
            }
        }
        
        return count($retErrors) ? $retErrors : null;
    }
    
    private function sendReconfigureMessage($installation) {
        $message = new \stdClass;
        $message->from = "Reconfiguration";
        $message->message_format = "html";
        $message->color = "yellow";
        $message->message = "I have been reconfigured. I am now monitoring "
            . "<a href=\"https://twitter.com/{$installation->twitter_screenname}\">@{$installation->twitter_screenname}</a> "
            . "and listening for the trigger <strong><code>{$installation->webhook_trigger}</code></strong>.<br /><br />"
            . "Try typing <strong><code>{$installation->webhook_trigger} help</code></strong> for more information.";
        
        $this->hipchat->sendRoomNotification($installation, $message);
    }
}