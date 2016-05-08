<?php

use Respect\Validation\Validator as v;

$container = new \Slim\Container;

$container['mode'] = $config['app']['debug'] ? "development" : "production";
$container['debug'] = $config['app']['debug'];
$container['displayErrorDetails'] = $config['app']['debug'];

$container['logger'] = function($c) use ($config) {
    $logger = new \Monolog\Logger('my_logger');
    if ($config['app']['log']) {
        $file_handler = new \Monolog\Handler\StreamHandler($config['app']['log']);
        $logger->pushHandler($file_handler);
    }
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

$container['hipchat'] = function($c) { return new \cbenard\HipChatService($c); };

$container['twitter'] = function($c) { return new \cbenard\TwitterService($c); };

$container['globalSettings'] = function ($c) { return new \cbenard\GlobalSettingsService($c); };

$container['updatetwitterjob'] = function ($c) { return new \cbenard\UpdateTwitterJob($c); };

$container['csrf'] = function ($c) { return new \Slim\Csrf\Guard; };

$container['configureValidation'] = function () {
  //Create the validators
  $accountValidator = v::alnum('-_')->noWhitespace()->length(1, 50);
  $triggerValidator = v::regex('/^\\/[a-zA-Z0-9]+$/')->length(1, 20);
  $newValidators = array(
    'screen_name_new' => $accountValidator,
    'webhook_trigger_new' => $triggerValidator
  );

  $existingValidators = array(
    'webhook_trigger_' => $triggerValidator
  );

  return new \cbenard\ConfigureValidation($newValidators, $existingValidators);
};

$container['orphan'] = function ($c) { return new \cbenard\OrphanCleanupJob($c); };