<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class InstallationController {
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
    }
    
    public function install(Request $request, Response $response, $args) {
        $raw_body = (string)$request->getBody();
        $this->container->logger->addInfo("Install Requested", [ "capabilities_url" => $request->getParsedBody()['capabilitiesUrl'], "oauthId" => $request->getParsedBody()['oauthId'] ]);
    
        $body = $request->getParsedBody();
        $capabilities = json_decode(file_get_contents($body['capabilitiesUrl']));
        $this->container->logger->addInfo("Retrieved Capabilities", [
            "tokenUrl" => $capabilities->capabilities->oauth2Provider->tokenUrl,
            "apiUrl" => $capabilities->capabilities->hipchatApiProvider->url,
            ]);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $body['oauthId'] ]);
        if (!$installation) {
            $installation = $mapper->build([ 'oauth_id' => $body['oauthId'] ]);
        }
        
        unset($installation->access_token);
        unset($installation->access_token_expiration);
        
        $installation->raw_json = $raw_body;
        $installation->token_url = $capabilities->capabilities->oauth2Provider->tokenUrl;
        $installation->api_url = $capabilities->capabilities->hipchatApiProvider->url;
       
        $dbresult = $mapper->save($installation);
        
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
        $this->container->logger->addInfo("Uninstall Requested", [ "headers" => $headers, "body" => $body ]);
        
        $installable = json_decode(file_get_contents($body['installable_url']));
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $installable->oauthId ]);
        if ($installation) {
            $mapper->delete($installation);
        }
        
        return $response->withStatus(301)->withHeader('Location', $body['redirect_url']);
    }
}