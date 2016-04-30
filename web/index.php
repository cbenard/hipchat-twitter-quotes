<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require_once("../common.php");

$app = new \Slim\App($container);

$app->get('/capabilities.json', '\Controllers\StaticContentController:capabilities');
$app->post('/installed', '\Controllers\InstallationController:install');
$app->get('/uninstalled', '\Controllers\InstallationController:uninstall');
$app->get('/configure', '\Controllers\ConfigurationController:configure');

$app->run();