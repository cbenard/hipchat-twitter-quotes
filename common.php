<?php

require_once("config.php");
require_once("vendor/autoload.php");
require_once("classes/autoload.php");
require_once("classes/dependency_injection.php");

date_default_timezone_set($config['global']['timezone']);