<?php

if ("cli" != php_sapi_name()) die ('This must be run from the command line.');

require("common.php");

$db = $container['db'];

echo "Running migrations...\r\n";
$mapper = $db->mapper('Entity\Installation');
$mapper->migrate();

echo "Done.\r\n";