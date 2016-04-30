<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class StaticContentController {
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
    }
    
    public function capabilities(Request $request, Response $response, $args) {
        $this->container->logger->info("Capabilities Requested");
    
        $baseUrl = $this->container->config['global']['baseUrl'];
        $integration_screenname = $this->container->config['hipchat']['screenname'];
        
        $response = $this->container->view->render($response, "capabilities.json",
            ["base_url" => $baseUrl, "integration_screenname" => $integration_screenname]
        )->withHeader('Content-Type','application/json;charset=utf-8');

        return $response;
    }
}