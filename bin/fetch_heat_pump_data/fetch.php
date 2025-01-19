<?php

require __DIR__ . '/vendor/autoload.php';
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once "Config/Lite.php";

use Luxtronic2\LuxController;

// Get the MQTT Gateway connection details from LoxBerry
$creds = mqtt_connectiondetails();
// MQTT requires a unique client id
$client_id = uniqid(gethostname() . "_client");

$cfg = new Config_Lite("$lbpconfigdir/pluginconfig.cfg",LOCK_EX,INI_SCANNER_RAW);
$ip = $cfg->get("SETTINGS","IP");
$port = $cfg->get("SETTINGS","PORT");
$password = $cfg->get("SETTINGS","PASSWORD");

// Create new Luxtronik Controller Object
$controller = new LuxController($ip, $port, $password);

// Create new phpMQTT Object
$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);
// Connect to mqtt broker and publish loxtronik data
if ($mqtt->connect(TRUE, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
  $mqtt->publish("luxtronik2", json_encode($controller->getData()), 0, 1);
  $mqtt->close();
}
// Set error message if mqtt connection failed
else {
  echo "MQTT connection failed";
}
