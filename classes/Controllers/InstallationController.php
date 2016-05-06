<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class InstallationController {
    private $container;
    private $hipchat;
    
    public function __construct($container) {
        $this->container = $container;
        $this->hipchat = $container->hipchat;
    }
    
    public function install(Request $request, Response $response, $args) {
        $raw_body = (string)$request->getBody();
        $this->container->logger->info("Install Requested", [ "capabilities_url" => $request->getParsedBody()['capabilitiesUrl'], "oauthId" => $request->getParsedBody()['oauthId'] ]);
    
        $body = $request->getParsedBody();
        $capabilities = json_decode(file_get_contents($body['capabilitiesUrl']));
        $this->container->logger->info("Retrieved Capabilities", [
            "tokenUrl" => $capabilities->capabilities->oauth2Provider->tokenUrl,
            "apiUrl" => $capabilities->capabilities->hipchatApiProvider->url,
            ]);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $body['oauthId'] ]);
        if (!$installation) {
            $installation = $mapper->build([
                'oauth_id' => $body['oauthId']
            ]);
        }
        
        unset($installation->access_token);
        unset($installation->access_token_expiration);
        
        $installation->oauth_secret = $body['oauthSecret'];
        $installation->room_id = $body['roomId'];
        $installation->group_id = $body['groupId'];
        $installation->raw_json = $raw_body;
        $installation->token_url = $capabilities->capabilities->oauth2Provider->tokenUrl;
        $installation->api_url = $capabilities->capabilities->hipchatApiProvider->url;
       
        $dbresult = $mapper->save($installation);
        
        try {
            $this->sendInstallationMessage($installation);
        }
        catch (\Exception $e) {
            $this->container->logger->error("Error sending installation message", [ "exception" => $e ]);
        }
        
        if ($dbresult) {
            return $response;
        }
        else {        
            return $response->withStatus(500);
        }
    }
    
    public function uninstall(Request $request, Response $response, $args) {
        $headers = $request->getHeaders();
        $body = $request->getQueryParams();
        $this->container->logger->info("Uninstall Requested", [ "headers" => $headers, "body" => $body ]);
        
        $installable = json_decode(file_get_contents($body['installable_url']));
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $installable->oauthId ]);
        if ($installation) {
            $mapper->delete($installation);
        }
        
        return $response->withStatus(301)->withHeader('Location', $body['redirect_url']);
    }
    
    private function sendInstallationMessage($installation) {
        $message = new \stdClass;
        $message->from = "Installation";
        $message->message_format = "html";
        $message->color = "yellow";
        $message->message = "I have been installed. Please visit the integration's Configure tab to set the Twitter account I will monitor and my trigger.";
        
        $this->hipchat->sendRoomNotification($installation, $message);
    }
}