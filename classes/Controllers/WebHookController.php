<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class WebHookController {
    private $container;
    private $jwt;
    private $installationMapper;
    
    public function __construct($container) {
        $this->container = $container;
        $this->jwt = $container->jwt;
        $this->installationMapper = $container->db->mapper('\Entity\Installation');
    }
    
    public function trigger(Request $request, Response $response, $args) {
        $this->container->logger->addInfo("Web Hook Triggered", [
            "request" => $request,
            "body" => $request->getBody(),
            "queryParams" => $request->getQueryParams(),
            "headers" => $request->getHeaders(),
            "oauth_client_id" => $request->getParsedBody()['oauth_client_id']
        ]);
        
        // HipChat doesn't appear to be sending the JWT with the web hook.
        // I believe this is a bug.
        
        // $jwt = $this->jwt->validateRequest($request);
        
        // $this->container->logger->addInfo("Web Hook JWT validated", [
        //     "jwt" => $jwt
        // ]);
        
        // $oauth_id = $jwt->iss;
        
        // Have to get it from the unauthenticated request instead of JWT due to issue above
        $oauth_id = $request->getParsedBody()['oauth_client_id'];
        $installation = $this->installationMapper->first([ 'oauth_id' => $oauth_id ]);
        
        $this->container->logger->addInfo("Web Hook installation fetched", [
            "installation" => $installation
        ]);
        
        $respData = new \stdClass;
        $respData->message_format = "text";
        $respData->message = "test response. you sent: " . $request->getParsedBody()['item']['message']['message'];
        
        $response = $response->withJson($respData);

        return $response;
    }
}