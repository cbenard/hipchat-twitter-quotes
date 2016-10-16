<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class TwitterAuthenticationController {
    private $container;
    
    public function __construct($container) {
        $this->container = $container;
    }
    
    public function create(Request $request, Response $response, $args) {
        $this->container->logger->info("Twitter Authentication Requested", [ 'params' => $request->getQueryParams() ]);
        $params = $request->getQueryParams();
        $installation_oauth_id = $params['installation_oauth_id'];
        $callbackUrl = $this->container->router->pathFor('twitterauth_receive');

        return $response->withStatus(302)->withHeader('Location', $callbackUrl);
    }
}