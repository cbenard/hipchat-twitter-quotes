<?php

namespace Controllers;

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

class TwitterAuthenticationController {
    private $container;
    private $twitter;
    private $globalConfig;
    
    public function __construct($container) {
        $this->container = $container;
        $this->twitter = $container->twitter;
        $this->globalConfig = $container->config['global'];
    }
    
    public function create(Request $request, Response $response, $args) {
        $this->container->logger->info("Twitter Authentication Requested", [ 'params' => $request->getQueryParams() ]);
        $params = $request->getQueryParams();
        $installation_oauth_id = $params['installation_oauth_id'];

        $error = $this->runPreFlightChecks($installation_oauth_id);
        if (!$installation_oauth_id || $error) {
            if (!$installation_oauth_id) {
                $error = "Installation OAuth ID not specified.";
            }

            $body = $response->getBody();
            $body->write($error);
            return $response->withStatus(403);
        }

        $mapper = $this->container->db->mapper('\Entity\TwitterAuthentication');
        $auth = $mapper->create([]);

        $callbackUrl = $this->globalConfig['baseUrl'];
        $callbackUrl .= $this->container->router->pathFor('twitterauth_complete');
        $callbackUrl .= "?id=" . urlencode($auth->id);
        $callbackUrl .= "&installation_oauth_id=" . urlencode($installation_oauth_id);

        // get the request token
        $reply = $this->twitter->oauthRequestToken($callbackUrl);

        // store the token
        $auth->request_token = $reply->oauth_token;
        $auth->request_token_secret = $reply->oauth_token_secret;
        $mapper->save($auth);

        // redirect to auth website
        $auth_url = $this->twitter->oauthAuthorizationUrl($reply->oauth_token, $reply->oauth_token_secret);

        return $response->withStatus(302)->withHeader('Location', $auth_url);

        // Testing
        // $body = $response->getBody();
        // $body->write($auth_url);
        // return $response;
    }
    
    public function complete(Request $request, Response $response, $args) {
        $this->container->logger->info("Twitter Authentication Reply Received", [ 'params' => $request->getQueryParams() ]);
        $params = $request->getQueryParams();

        if (!isset($params['id'])) {
            $this->container->logger->error("Twitter Authentication ID not set.", [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, "Twitter Authentication ID not set.", 403);
        }
        if (!isset($params['installation_oauth_id'])) {
            $this->container->logger->error("HipChat OAuth ID not set.", [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, "HipChat OAuth ID not set.", 403);
        }
        $installation_oauth_id = $params['installation_oauth_id'];
        $installationMapper = $this->container->db->mapper('\Entity\Installation');
        $installation = $installationMapper->get($installation_oauth_id); 
        if (!$installation) {
            $this->container->logger->error("Unable to find HipChat installation from OAuth ID.", [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, "Unable to find HipChat installation from OAuth ID: " . $installation_oauth_id, 403);
        }        

        $authMapper = $this->container->db->mapper('\Entity\TwitterAuthentication');
        $auth = $authMapper->get($params['id']);
        if (!$auth) {
            $this->container->logger->error("Unable to find Twitter Authentication from ID.", [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, "Unable to find Twitter Authentication from ID: " . $params['id'], 403);
        }

        if (!isset($params['oauth_verifier'])) {
            $this->container->logger->error("Twitter OAuth Verifier not set.", [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, "Twitter OAuth Verifier not set.", 403);
        }

        $error = $this->runPreFlightChecks($installation->oauth_id);
        if ($error) {
            $this->container->logger->error($error, [ "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, $error, 403);
        }

        if (!$auth->request_token || !$auth->request_token_secret) {
            $error = "Unable to find request tokens from prior Twitter authorization request";
            $this->container->logger->error($error, [ "authorization" => $auth, "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, $error, 403);
        }

        if ($auth->is_completed) {
            $error = "Twitter authorization is already completed.";
            $this->container->logger->error($error, [ "authorization" => $auth, "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, $error, 403);
        }

        // get the access token
        $reply = $this->twitter->oauthAccessToken($auth->request_token, $auth->request_token_secret, $params['oauth_verifier']);
        if (!$reply || !isset($reply->oauth_token) || !isset($reply->oauth_token_secret)
            || !$reply->oauth_token || !$reply->oauth_token_secret) {
            $error = "Invalid Twitter access token reply.";
            $this->container->logger->error($error, [ "reply" => $reply, "params" => $params, "request" => $request, "session" => $_SESSION ]);
            return $this->createErrorResponse($response, $error, 403);
        }

        // store the token (which is different from the request token!)
        $auth->access_token = $reply->oauth_token;
        $auth->access_token_secret = $reply->oauth_token_secret;
        $auth->is_completed = true;
        $authMapper->save($auth);

        $installation->twitter_authentication_id = $auth->id;
        $installationMapper->save($installation);        

        $currentInfo = $this->twitter->verifyCredentials($auth->access_token, $auth->access_token_secret);

        $auth->user_id = $currentInfo->id;
        $auth->screen_name = $currentInfo->screen_name;
        $auth->profile_image_url_https = $currentInfo->profile_image_url_https;
        $auth->name = $currentInfo->name;
        $auth->verified_on = new \DateTime;
        $authMapper->save($auth);

        //return $response;
        // Testing
        // $body = $response->getBody();
        // $body->write("Done.\n");
        // $body->write(print_r($auth, true));
        // return $response->withHeader('Content-Type', 'text/plain');
        $response = $this->container->view->render($response, "twitterauth_receive.phtml", [
        ]);

        return $response;
    }

    private function runPreFlightChecks($installation_oauth_id) {
        if (!isset($_SESSION["authenticated_for_{$installation_oauth_id}"])
            || $_SESSION["authenticated_for_{$installation_oauth_id}"] !== true) {

            $this->container->logger->error("No authorized to add room Twitter authorization.", [ "installation_oauth_id" => $installation_oauth_id, "session" => $_SESSION ]);
            return "Authentication information expired. Please refresh.";
        }

        return false;
    }

    private function createErrorResponse($response, $error, $status = 500) {
        $body = $response->getBody();
        $body->write($error);
        return $response->withStatus($status);
    }
}