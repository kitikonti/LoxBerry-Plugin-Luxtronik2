<?php

require_once "loxberry_io.php";
require_once "Config/Lite.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

// This will read your language files to the array $L
$L = LBSystem::readlanguage("language.ini");

// Create empty messages array.
$messages = array();

// Set base url.
$base_url = "http://" . $_SERVER['SERVER_NAME'];

// Array with cycle options used in a select field.
$croncycle_options = array(
  1 => array(
    "text" => $L['CRONCYCLE.1MINUTE'],
    "crontab_cycle" => "* * * * *",
  ),
  3 => array(
    "text" => $L['CRONCYCLE.3MINUTE'],
    "crontab_cycle" => "*/3 * * * *",
  ),
  5 => array(
    "text" => $L['CRONCYCLE.5MINUTE'],
    "crontab_cycle" => "*/5 * * * *",
  ),
  10 => array(
    "text" => $L['CRONCYCLE.10MINUTE'],
    "crontab_cycle" => "*/10 * * * *",
  ),
  15 => array(
    "text" => $L['CRONCYCLE.15MINUTE'],
    "crontab_cycle" => "*/15 * * * *",
  ),
  30 => array(
    "text" => $L['CRONCYCLE.30MINUTE'],
    "crontab_cycle" => "*/30 * * * *",
  ),
  60 => array(
    "text" => $L['CRONCYCLE.60MINUTE'],
    "crontab_cycle" => "0 * * * *",
  ),
);

/**
 * Helper Function to update the crontab.
 *
 * The crontab is used to automatically call the latest heatpump data. With this function
 * we set the cycle in which we call the data and create the crontab, or disable the cron.
 */
function update_crontab($cycle = 0) {
  global $croncycle_options;
  global $lbphtmlauthdir;
  global $lbpbindir;
  global $lbhomedir;

  // Documentation how we can update the crontab in loxberry.
  // https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/eigene_cronjobs_im_plugin_code_pflegen

  // Create temp file.
  $temp_file = tmpfile();
  // If cycle is defined create cronjob.
  if ($cycle !== 0) {
    // Abort and set error if not a valid cycle.
    if (!in_array($cycle, array_keys($croncycle_options))) {
      global $messages;
      $messages["error"][] = "Invalid cycle option. Cronjob will not be created.";
      fclose($temp_file);
      return;
    }
    // Get the timing string for the used cycle time.
    $crontab_cycle = $croncycle_options[$cycle]["crontab_cycle"];
    // Create cron command.
    $crontab_command = "$crontab_cycle loxberry /usr/bin/php $lbpbindir/fetch_heat_pump_data/fetch.php >/dev/null 2>&1\n";
  }
  // If no cycle is defined delete existing cronjob.
  else {
    // Clear cron command.
    $crontab_command = "";
  }
  // Write cron command to temp file.
  fwrite($temp_file, $crontab_command);
  // Get path to temp file.
  $path = stream_get_meta_data($temp_file)['uri'];
  // Execute shell script which generates the cronjob.
  shell_exec("sudo $lbhomedir/sbin/installcrontab.sh luxtronik2 $path");
  // Close and delete temp file.
  fclose($temp_file);
}

$cfg = new Config_Lite("$lbpconfigdir/pluginconfig.cfg",LOCK_EX,INI_SCANNER_RAW);

if (!empty($_POST)) {
  $cfg->set("SETTINGS","IP",$_POST["luxtronik2-ip"]);
  $cfg->set("SETTINGS","PORT",$_POST["luxtronik2-port"]);
  $cfg->set("SETTINGS","PASSWORD",$_POST["luxtronik2-password"]);
  if (isset($_POST["luxtronik2-cron"])) {
    $cfg->set("SETTINGS","CRON",true);
    update_crontab($_POST["luxtronik2-croncycle"]);
  }
  else {
    $cfg->set("SETTINGS","CRON",false);
    update_crontab();
  }
  $cfg->set("SETTINGS","CRONCYCLE",$_POST["luxtronik2-croncycle"]);
  $cfg->save();
}

$cron_checked = "";
if ($cfg->getBool("SETTINGS","CRON")) {
  $cron_checked = "checked=\"\"";
}

foreach ($messages as $type => $type_messages) {
  echo "<div class=\"message $type\"><ul>";
  foreach ($type_messages as $type_message) {
    echo "<li>$type_message</li>";
  }
  echo "</ul></div>";
}

$template_title = "Luxtronik 2";
$helplink = "http://www.loxwiki.eu/display/LOXBERRY/Luxtronik2";
$helptemplate = "help.html";

LBWeb::lbheader($template_title, $helplink, $helptemplate);

// This is the main area for your plugin
?>

  <p><?=$L['TOP.ALPHA']?></p>
  <p><?=$L['TOP.BETA']?></p>
  <p><?=$L['BOTTOM.GAMMA']?></p>

<?php
// Finally print the footer
LBWeb::lbfooter();
?>