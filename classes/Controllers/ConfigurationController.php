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
    
        $baseUrl = $this->container->config['global']['baseUrl'];
        $integration_screenname = $this->container->config['hipchat']['screenname'];
        
        $response->getBody()->write("Hello, World");

        return $response;
    }
}