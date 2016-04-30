<?php

namespace cbenard;

use \Firebase\JWT\JWT;

class JWTService {
    private $container;
    private $db;
    
    public function __construct($container) {
        $this->container = $container;
        $this->db = $container->db;
    }
    
    public function validateRequest($request) {
        $encodedJwt = $this->getJwt($request);
        if (!$encodedJwt) {
            throw new \Exception("Unable to find JWT token.");
        }
        $jwtResponse = $this->validateJwt($encodedJwt);
        
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
    
    public function validateJwt($jwt) {
        $this->container->logger->addInfo("JWT validation Requested", [ 'encoded' => $jwt ]);
        $jwtArray = explode('.', $jwt);
        if (count($jwtArray) !== 3) return false;
        
        $header = json_decode(base64_decode($jwtArray[0]));
        $payload = json_decode(base64_decode($jwtArray[1]));
        $oauth_id = $payload->iss;
        
        JWT::$leeway = 60;
        
        $mapper = $this->db->mapper('\Entity\Installation');
        $installation = $mapper->first([ 'oauth_id' => $oauth_id ]);
        
        $decoded = null;
        $decoded = JWT::decode($jwt, $installation->oauth_secret, array($header->alg));
        
        return $decoded;
    }
}