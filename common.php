<?php

require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/vendor/autoload.php");
require_once(__DIR__ . "/classes/autoload.php");
require_once(__DIR__ . "/classes/dependency_injection.php");

date_default_timezone_set($config['global']['timezone']);