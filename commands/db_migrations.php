#!/usr/bin/env php
<?php

if ("cli" != php_sapi_name()) die ('This must be run from the command line.');

require(__DIR__ . "/../common.php");

$db = $container['db'];

echo "Running migrations...\r\n";
$mapper = $db->mapper('Entity\Installation');
$mapper->migrate();
$mapper = $db->mapper('Entity\TwitterUser');
$mapper->migrate();
$mapper = $db->mapper('Entity\Tweet');
$mapper->migrate();
$mapper = $db->mapper('Entity\GlobalSetting');
$mapper->migrate();
$mapper = $db->mapper('Entity\InstallationTwitterUser');
$mapper->migrate();
$mapper = $db->mapper('Entity\TwitterAuthentication');
$mapper->migrate();

echo "Done.\r\n";