<?php

$container = new \Slim\Container;

$container['logger'] = function($c) {
    $logger = new \Monolog\Logger('my_logger');
    $file_handler = new \Monolog\Handler\StreamHandler("../logs/app.log");
    $logger->pushHandler($file_handler);
    return $logger;
};

$container['view'] = new \Slim\Views\PhpRenderer("../templates/");

$container['config'] = $config;

$cfg = new \Spot\Config();
$cfg->addConnection('main', [
    'dbname' => $config['db']['main']['name'],
    'user' => $config['db']['main']['user'],
    'password' => $config['db']['main']['password'],
    'host' => $config['db']['main']['host'],
    'driver' => 'pdo_mysql',
]);
$container['db'] = new \Spot\Locator($cfg);