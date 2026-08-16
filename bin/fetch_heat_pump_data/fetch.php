<?php

require __DIR__ . '/vendor/autoload.php';
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
require_once "Config/Lite.php";
require_once "loxberry_log.php";

use Luxtronic2\LuxController;
use Luxtronic2\LuxStatusReader;
use Luxtronic2\LuxException;
use Luxtronic2\LuxConnectionException;
use Luxtronic2\LuxProtocolException;

// Exit codes. The crontab sends stdout/stderr to /dev/null, so these only show
// up when the script is run by hand - the log is the channel that matters.
const EXIT_OK               = 0;
const EXIT_NOT_CONFIGURED   = 1;
const EXIT_HEATPUMP_OFFLINE = 2;
const EXIT_PROTOCOL         = 3;
const EXIT_MQTT             = 4;
const EXIT_UNEXPECTED       = 5;

// Seconds to wait for the heat pump. Must stay well below the cron cycle (the
// shortest selectable one is 1 minute) so runs cannot pile up on a controller
// that is already struggling.
const LUX_TIMEOUT = 10;

// Seconds to wait for the status socket (8889/8888). Shorter than the main
// timeout: this is an optional extra, and the two must still fit inside the
// shortest cron cycle together.
const LUX_STATUS_TIMEOUT = 5;

// Creates a log object.
$log = LBLog::newLog([
  "name" => "Fetch",
  "filename" => "$lbplogdir/fetch.log",
  "append" => 1
]);
LOGSTART("Fetch request");

$exitCode   = EXIT_OK;
$controller = NULL;

try {
  // ---------------------------------------------------------------- config --
  // NEVER name this $cfg. The LoxBerry SDK keeps LoxBerry's own general.json in
  // a global of that name, and this file scope IS the global scope - assigning
  // $cfg here silently replaces it. mqtt_connectiondetails() then reads
  // $cfg->Mqtt->Brokerhost off a Config_Lite object and returns an empty broker
  // host, and LBWeb::lbheader() loses the user's theme the same way.
  try {
    $pluginconfig = new Config_Lite("$lbpconfigdir/pluginconfig.cfg", LOCK_EX, INI_SCANNER_RAW);
  }
  catch (Throwable $e) {
    // Config_Lite throws when the file is missing or unreadable.
    throw new InvalidArgumentException(
      "Cannot read $lbpconfigdir/pluginconfig.cfg: " . $e->getMessage()
    );
  }
  // Always pass a default: Config_Lite::get() throws on a missing key otherwise.
  $ip       = trim($pluginconfig->get("SETTINGS", "IP", ""));
  $port     = trim($pluginconfig->get("SETTINGS", "PORT", ""));
  $password = $pluginconfig->get("SETTINGS", "PASSWORD", "");

  if ($ip === "" || $port === "") {
    throw new InvalidArgumentException(
      "Heat pump IP and port are not configured yet - open the plugin settings "
      . "page. Disable the cronjob there to stop this message."
    );
  }

  // ------------------------------------------------------------- heat pump --
  $controller = new LuxController($ip, $port, $password, LUX_TIMEOUT);
  $started    = microtime(TRUE);
  $data       = $controller->getData();
  LOGINF(sprintf(
    'Read %d sections from %s:%s in %.1f s',
    count($data), $ip, $port, microtime(TRUE) - $started
  ));

  // The controller's own display text lives on a separate socket, not in the
  // WebSocket tree. It is a bonus on top of the real payload, so a failure here
  // must never cost us a publish - log it quietly and carry on without it.
  // Quietly, because this runs every minute: a pump without that socket would
  // otherwise fill the log with warnings for a value it never had.
  try {
    $status = (new LuxStatusReader($ip, LUX_STATUS_TIMEOUT))->read();
    $data['Status'] = $status;
    LOGINF('Controller status: ' . $status['Zeile1'] . ' / ' . $status['Zeile3']);
  }
  catch (LuxException $e) {
    LOGINF('Status lines unavailable (publishing heat pump data anyway): ' . $e->getMessage());
  }

  $payload = json_encode($data);
  if ($payload === FALSE) {
    throw new RuntimeException(
      'Could not encode the payload as JSON: ' . json_last_error_msg()
    );
  }

  // ------------------------------------------------------------------ MQTT --
  // Only now, with data in hand: a failed fetch must not leave a broker
  // connection open, and there is nothing to publish anyway.
  $creds = mqtt_connectiondetails();
  if (empty($creds['brokerhost'])) {
    throw new InvalidArgumentException(
      'No MQTT broker configured in LoxBerry - check the MQTT Gateway settings.'
    );
  }
  // MQTT requires a unique client id
  $client_id = uniqid(gethostname() . "_client");
  $mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'], $creds['brokerport'], $client_id);
  // Connect to mqtt broker and publish loxtronik data
  if ($mqtt->connect(TRUE, NULL, $creds['brokeruser'], $creds['brokerpass'])) {
    $mqtt->publish("luxtronik2", $payload, 0, 1);
    $mqtt->close();
    LOGOK("Fetched data and published to MQTT");
  }
  // Set error message if mqtt connection failed
  else {
    LOGERR("MQTT connection to {$creds['brokerhost']}:{$creds['brokerport']} failed");
    $exitCode = EXIT_MQTT;
  }
}
catch (InvalidArgumentException $e) {
  LOGWARN($e->getMessage());
  $exitCode = EXIT_NOT_CONFIGURED;
}
catch (LuxConnectionException $e) {
  // Expected and transient. The previous retained message stays on the broker;
  // it is now stale, which is why the timestamp of this log line matters.
  LOGERR($e->getMessage());
  $exitCode = EXIT_HEATPUMP_OFFLINE;
}
catch (LuxProtocolException $e) {
  // Retrying will not help - a firmware or language change moved things.
  LOGCRIT($e->getMessage());
  $exitCode = EXIT_PROTOCOL;
}
catch (Throwable $e) {
  LOGCRIT(get_class($e) . ': ' . $e->getMessage()
    . ' in ' . $e->getFile() . ':' . $e->getLine());
  $exitCode = EXIT_UNEXPECTED;
}

// Non-fatal oddities noticed while parsing, on the success and failure paths.
if ($controller !== NULL) {
  foreach ($controller->getWarnings() as $warning) {
    LOGWARN($warning);
  }
}

LOGEND();
// exit() does not run finally blocks, so it goes last, outside the try.
exit($exitCode);
