<?php

$container = new \Slim\Container;

$container['mode'] = $config['app']['debug'] ? "development" : "production";
$container['debug'] = $config['app']['debug'];
$container['displayErrorDetails'] = $config['app']['debug'];

$container['logger'] = function($c) {
    $logger = new \Monolog\Logger('my_logger');
    $file_handler = new \Monolog\Handler\StreamHandler("../logs/app.log");
    $logger->pushHandler($file_handler);
    return $logger;
};

$container['view'] = function($c) { return new \Slim\Views\PhpRenderer("../templates/"); };

$container['config'] = $config;

$cfg = new \Spot\Config();
$cfg->addConnection('main', [
    'dbname' => $config['db']['main']['name'],
    'user' => $config['db']['main']['user'],
    'password' => $config['db']['main']['password'],
    'host' => $config['db']['main']['host'],
    'driver' => 'pdo_mysql',
]);
$container['db'] = function($c) use ($cfg) { return new \Spot\Locator($cfg); };

$container['jwt'] = function($c) { return new \cbenard\JWTService($c); };