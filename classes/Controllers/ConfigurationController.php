<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class ConfigurationController {
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
    }
    
    public function configure(Request $request, Response $response, $args) {
        $this->container->logger->addInfo("Configuration Requested");
        $this->validateJwt($request);
        
        $response = $this->container->view->render($response, "configure.phtml", [
        ]);

        return $response;
    }
    
    private function validateJwt($request) {
        $encodedJwt = $this->getJwt($request);
        $jwtResponse = $this->container->jwt->validateJwt($encodedJwt);
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