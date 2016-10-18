<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class ConfigurationController {
    private $container;
    private $hipchat;
    private $csrf;
    private $configureValidation;
    private $twitter;
    
    public function __construct($container) {
        $this->container = $container;
        $this->hipchat = $container->hipchat;
        $this->csrf = $container->csrf;
        $this->configureValidation = $container->configureValidation;
        $this->twitter = $container->twitter;
    }
    
    public function display(Request $request, Response $response, $args) {
        $this->container->logger->info("Configuration Requested");
        $jwt = null;
        
        try {
            $jwt = $this->container->jwt->validateRequest($request);
        }
        catch (\Firebase\JWT\ExpiredException $ex) {
            $_SESSION["authenticated_for_{$installation_oauth_id}"] = false;
            $this->container->logger->info("Expired JWT token on configuration", [ "exception" => $ex, "request" => $request ]);
            $returnParameters = [
                "errors" => [ "Authentication information expired. Please refresh." ],
                "dont_display_form" => true
            ];
            return $this->container->view->render($response, "configure.phtml", $returnParameters)
                ->withStatus(403);
        }
        
        $installation_oauth_id = $jwt->iss;
        $_SESSION["authenticated_for_{$installation_oauth_id}"] = true;
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->all()->with('twitter_authentication')->where(['oauth_id' => $installation_oauth_id])->first();

        $joinMapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $joinMapper->where([ 'installations_oauth_id' => $installation_oauth_id ])->active();
        $this->container->logger->info("configurations", [ "oauth_id" => $installation_oauth_id, "configurations" => $configurations->toArray() ]);
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "errors" => null,
            "success" => null,
            "configurations" => $configurations,

            "postUri" => $request->getUri()->getPath(),
            "installation_oauth_id" => $installation_oauth_id,
            
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
        
        $installation_oauth_id = isset($_POST["installation_oauth_id"]) ? $_POST["installation_oauth_id"] : null;

        if (!isset($_SESSION["authenticated_for_{$installation_oauth_id}"])
            || $_SESSION["authenticated_for_{$installation_oauth_id}"] !== true) {

            $this->container->logger->error("No authorized to edit room's configuration.", [ "installation_oauth_id" => $installation_oauth_id, "request" => $request, "session" => $_SESSION ]);
            $returnParameters = [
                "errors" => [ "Authentication information expired. Please refresh." ],
                "dont_display_form" => true
            ];
            return $this->container->view->render($response, "configure.phtml", $returnParameters)
                ->withStatus(403);
        }
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->all()->with('twitter_authentication')->where(['oauth_id' => $installation_oauth_id])->first();

        $joinMapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $joinMapper->where([ 'installations_oauth_id' => $installation_oauth_id ])->active();
        
        $returnParameters = [
            "errors" => $this->getFlattenedErrors($this->configureValidation->getErrors()),
            "success" => null,
            "configurations" => $configurations,

            "postUri" => $request->getUri()->getPath(),
            "installation_oauth_id" => $installation_oauth_id,

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
                    $this->container->updatetwitterjob->update($returnParameters['saved_screen_name'], $backfill = false, $suppress_notification = true);
                    // For testing, but normally we don't want this much logging
                    // $container = $this->container;
                    // $u = new \cbenard\UpdateTwitterJob($this->container, function($msg) use ($container) { $container->logger->info($msg); });
                    // $u->update($returnParameters['saved_screen_name'], $backfill = false, $suppress_notification = true);
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
        $screen_name = strtolower(trim($body['screen_name_new']));
        $webhook_trigger = strtolower(trim($body['webhook_trigger_new']));
        $notify_new_tweets = isset($body['notify_new_tweets_new']) && $body['notify_new_tweets_new'] == "on" ? true : false;
        $id_str = null;
        $display_name = null;
        $profile_image_url_https = null;

        try {
            $user = $this->twitter->getUserInfoByName($screen_name);
            $id_str = $user->id;
            $display_name = $user->name;
            $screen_name = $user->screen_name;
            $profile_image_url_https = $user->profile_image_url_https;
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Unable to verify twitter account", [ "screen_name" => $screen_name, "request" => $request ]);
            $returnParameters["errors"] = [ "Unable to verify Twitter account @{$screen_name}." ];

            $returnParameters["screen_name_new"] = $body['screen_name_new'];
            $returnParameters["webhook_trigger_new"] = $body['webhook_trigger_new'];
            $returnParameters["notify_new_tweets_new"] = isset($body['notify_new_tweets_new']) ? $body['notify_new_tweets_new'] : null;
            return $returnParameters;
        }
        
        try {
            $mapper = $this->container->db->mapper('\Entity\TwitterUser');
            $user = $mapper->get($id_str);
            if (!$user) {
                $mapper->create([
                    'user_id' => $id_str,
                    'screen_name' => $screen_name,
                    'name' => $display_name,
                    'profile_image_url_https' => $profile_image_url_https
                ]);
            }
        }
        catch (\Exception $ex) {
            $this->container->logger->error("Unable to verify create local twitter account", [ "screen_name" => $screen_name, "request" => $request ]);
            $returnParameters["errors"] = [ "Unable to create local Twitter account information for @{$screen_name}." ];

            $returnParameters["screen_name_new"] = $body['screen_name_new'];
            $returnParameters["webhook_trigger_new"] = $body['webhook_trigger_new'];
            $returnParameters["notify_new_tweets_new"] = isset($body['notify_new_tweets_new']) ? $body['notify_new_tweets_new'] : null;
            return $returnParameters;
        }
        
        try {
            $mapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
            $mapper->addNew($installation->oauth_id, $id_str, $screen_name, $display_name, $webhook_trigger, $notify_new_tweets);
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
        
        $webhook_trigger = strtolower(trim($body['webhook_trigger_' . $saveID]));
        $notify_new_tweets = isset($body['notify_new_tweets_' . $saveID]) && $body['notify_new_tweets_' . $saveID] == "on" ? true : false;

        try {
            $mapper->updateExisting($saveID, $installation->oauth_id, $webhook_trigger, $notify_new_tweets);
            if ($old->webhook_trigger != $webhook_trigger) {
                $this->hipchat->removeHook($installation, $old->webhook_trigger);
            }
            $this->hipchat->registerHook($installation, $webhook_trigger);
            $returnParameters["success"] = "Updated @{$old->user->screen_name} with {$webhook_trigger}";
            $returnParameters["saved_screen_name"] = $old->user->screen_name;
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
        $mapper = $this->container->db->mapper('\Entity\InstallationTwitterUser');
        $configurations = $mapper
            ->where([ "installations_oauth_id" => $installation->oauth_id ])
            ->active()
            ->order([ 'created_on' => 'ASC' ]);
        
        $message = new \stdClass;
        $message->from = "Reconfiguration";
        $message->message_format = "html";
        $message->color = "yellow";
        $message->message = "I have been reconfigured. ";
        
        if (count($configurations)) {
            $accountplurality = count($installation->configurations) > 2 ? "accounts" : "account";
            $message->message .= "I am now monitoring the following {$accountplurality}:<br /><ul>";
            foreach ($configurations as $configuration) {
                $message->message .= "<li><a href=\"https://twitter.com/{$configuration->user->screen_name}\">@{$configuration->user->screen_name}</a> "
                    . "&ndash; <strong><code>{$configuration->webhook_trigger}</code></strong></li>";
            }
            $message->message .= "</ul>";
            
            $message->message .= "<br />Try typing <strong><code>{$configurations[0]->webhook_trigger} help</code></strong> for more information.";
        }
        else {
            $message->message .= "I am not monitoring any accounts. Please visit the Configuration tab to set up accounts to monitor.";
        }

        $this->hipchat->sendRoomNotification($installation, $message);
    }
}