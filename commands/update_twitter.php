<?php

if ("cli" != php_sapi_name()) die ('This must be run from the command line.');

require("common.php");

$ut = new \cbenard\UpdateTwitterJob($container, function($message) { echo $message; });
$ut->update();