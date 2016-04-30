<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class ConfigurationController {
    private $container;
    private $hipchat;
    
    public function __construct($container) {
        $this->container = $container;
        $this->hipchat = $container->hipchat;
    }
    
    public function display(Request $request, Response $response, $args) {
        $this->container->logger->addInfo("Configuration Requested");
        $jwt = $this->validateJwt($request);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $jwt->iss ]);
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "screen_name" => $installation->twitter_screenname,
            "webhook_trigger" => $installation->webhook_trigger
        ]);

        return $response;
    }
    
    public function update(Request $request, Response $response, $args) {
        $body = $request->getParsedBody();
        $this->container->logger->addInfo("Update Configuration Requested", [ 'request' => $body ]);
        $jwt = $this->validateJwt($request);
        
        $mapper = $this->container->db->mapper('\Entity\Installation');
        $oauth_id = $jwt->iss;
        $installation = $mapper->first([ 'oauth_id' => $oauth_id ]);
        
        $installation->twitter_screenname = $body['screen_name'];
        $installation->webhook_trigger = $body['webhook_trigger'];
        
        $this->hipchat->registerhook($installation);
        
        $mapper->save($installation);
        
        $response = $this->container->view->render($response, "configure.phtml", [
            "screen_name" => $installation->twitter_screenname,
            "webhook_trigger" => $installation->webhook_trigger
        ]);

        return $response;
    }
    
    private function validateJwt($request) {
        $encodedJwt = $this->getJwt($request);
        $jwtResponse = $this->container->jwt->validateJwt($encodedJwt);
        
        return $jwtResponse;
    }
    
    private function getJwt($request) {
        $queryParams = $request->getQueryParams();
        $headers = $request->getHeaders();
        $encodedJwt = null;
        
        if (isset($queryParams['signed_request'])) {
            $encodedJwt = $queryParams['signed_request'];
        }
        elseif (isset($headers['authorization'])) {
            $encodedJwt = substr($headers['authorization'], 4, 0);
        }
        elseif (isset($headers['Authorization'])) {
            $encodedJwt = substr($headers['Authorization'], 4, 0);
        }
        
        return $encodedJwt;
    }
}