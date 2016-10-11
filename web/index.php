<?php

ini_set("session.cookie_secure", true);
ini_set("session.cookie_httponly", true);
// Available starting in 5.5.2 and we only require 5.5
if (PHP_VERSION_ID >= 50502) {
    ini_set("session.cookie_use_strict_mode", true);
}

session_cache_limiter(false);
session_start();
        
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require_once("../common.php");

$app = new \Slim\App($container);

$app->get('/', '\Controllers\StaticContentController:index');
$app->get('/capabilities.json', '\Controllers\StaticContentController:capabilities');
$app->post('/installed', '\Controllers\InstallationController:install');
$app->get('/uninstalled', '\Controllers\InstallationController:uninstall');
$app->get('/configure', '\Controllers\ConfigurationController:display')
    ->add($container->csrf);
$app->post('/configure', '\Controllers\ConfigurationController:update')
    ->add($container->csrf)
    ->add($container->configureValidation);
$app->post('/webhook', '\Controllers\WebHookController:trigger');

$app->run();