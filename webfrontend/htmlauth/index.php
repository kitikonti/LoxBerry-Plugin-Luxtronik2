<?php

require_once "loxberry_io.php";
require_once "Config/Lite.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

// The plugin's own classes, from the composer install under bin/. LoxBerry has
// no mechanism for sharing composer packages between a plugin's directories -
// its plugin documentation does not mention composer at all - so the frontend
// simply loads the autoloader the bin/ install already produced. Guarded
// because postinstall.sh may not have run yet, or may have failed.
$luxtronik2_autoload = "$lbpbindir/fetch_heat_pump_data/vendor/autoload.php";
$luxtronik2_classes  = file_exists($luxtronik2_autoload);
if ($luxtronik2_classes) {
  require_once $luxtronik2_autoload;
}

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
      $messages["error"][] = $L['MESSAGES.CYCLE_INVALID'];
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

/**
 * Escape a value for use inside an HTML attribute.
 *
 * Config values are round-tripped through this form, so an unescaped quote in a
 * stored value would break out of the value="" attribute.
 */
function h($string) {
  return htmlspecialchars((string) $string, ENT_QUOTES, "UTF-8");
}

// NEVER name this $cfg - see the note in bin/fetch_heat_pump_data/fetch.php.
// The LoxBerry SDK holds general.json in a global $cfg; shadowing it here makes
// LBWeb::lbheader() fall back to the default theme.
/**
 * Read once from the heat pump with the values currently in the form.
 *
 * Note the password is not really being validated: the controller accepts any
 * password for a read-only login, so this answers "is the controller reachable
 * and speaking the protocol", which is what actually goes wrong in practice.
 */
function luxtronik2_test_connection($ip, $port, $password) {
  global $messages, $L, $luxtronik2_classes;

  if (!$luxtronik2_classes) {
    $messages["error"][] = $L['MESSAGES.TEST_UNAVAILABLE'];
    return;
  }
  try {
    // Shorter than the cronjob's timeout - someone is waiting on a web page.
    $controller = new Luxtronic2\LuxController($ip, $port, $password, 8);
    $data = $controller->getData();
    $messages["info"][] = sprintf($L['MESSAGES.TEST_OK'],
      $ip, $port, count($data), implode(", ", array_slice(array_keys($data), 0, 4)));
    foreach ($controller->getWarnings() as $warning) {
      $messages["error"][] = $warning;
    }
  }
  catch (Throwable $e) {
    $messages["error"][] = sprintf($L['MESSAGES.TEST_FAILED'], $ip, $port, $e->getMessage());
  }
}

$pluginconfig = new Config_Lite("$lbpconfigdir/pluginconfig.cfg",LOCK_EX,INI_SCANNER_RAW);

// Value filter files - see LuxFilter. Every use is guarded by
// $luxtronik2_classes; without them the filter section is shown disabled.
if ($luxtronik2_classes) {
  $selection_file = "$lbpconfigdir/" . Luxtronic2\LuxFilter::SELECTION_FILE;
  $available_file = "$lbplogdir/" . Luxtronic2\LuxFilter::AVAILABLE_FILE;
}

// Submitted values, only populated on POST - see the $rejected block below.
$ip = $port = $password = "";
$cycle = 0;
$filter_mode = "all";
$filter_values = array();

if (!empty($_POST)) {
  $ip       = trim(isset($_POST["luxtronik2-ip"]) ? $_POST["luxtronik2-ip"] : "");
  $port     = trim(isset($_POST["luxtronik2-port"]) ? $_POST["luxtronik2-port"] : "");
  $password = isset($_POST["luxtronik2-password"]) ? $_POST["luxtronik2-password"] : "";
  $cycle    = (int) (isset($_POST["luxtronik2-croncycle"]) ? $_POST["luxtronik2-croncycle"] : 0);
  $filter_mode = (isset($_POST["luxtronik2-filter"]) && $_POST["luxtronik2-filter"] === "selected")
    ? "selected" : "all";
  $filter_values = (isset($_POST["luxtronik2-values"]) && is_array($_POST["luxtronik2-values"]))
    ? $_POST["luxtronik2-values"] : array();
  if ($luxtronik2_classes) {
    $filter_values = Luxtronic2\LuxFilter::clean($filter_values);
  }
  // A filter that lets nothing through would leave Loxone without any value.
  if ($filter_mode === "selected" && empty($filter_values)) {
    $messages["error"][] = $L['MESSAGES.FILTER_EMPTY'];
  }

  // Validate before saving: a bad address here means fetch.php fails on every
  // cron run, and the only place that shows up is the plugin log.
  if ($ip === "") {
    $messages["error"][] = $L['MESSAGES.IP_EMPTY'];
  }
  elseif (!filter_var($ip, FILTER_VALIDATE_IP)
    && !filter_var($ip, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    $messages["error"][] = sprintf($L['MESSAGES.IP_INVALID'], $ip);
  }
  if ($port === "" || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    $messages["error"][] = $L['MESSAGES.PORT_INVALID'];
  }
  if (!array_key_exists($cycle, $croncycle_options)) {
    $messages["error"][] = $L['MESSAGES.CYCLE_INVALID'];
  }

  // "Test connection" checks the entered values without saving them, so a bad
  // address can be corrected before it becomes the cronjob's problem.
  if (isset($_POST["luxtronik2-test"])) {
    if (empty($messages["error"])) {
      luxtronik2_test_connection($ip, $port, $password);
    }
  }
  elseif (empty($messages["error"])) {
    $pluginconfig->set("SETTINGS","IP",$ip);
    $pluginconfig->set("SETTINGS","PORT",$port);
    $pluginconfig->set("SETTINGS","PASSWORD",$password);
    if (isset($_POST["luxtronik2-cron"])) {
      $pluginconfig->set("SETTINGS","CRON",true);
      update_crontab($cycle);
    }
    else {
      $pluginconfig->set("SETTINGS","CRON",false);
      update_crontab();
    }
    $pluginconfig->set("SETTINGS","CRONCYCLE",$cycle);
    // The selection is kept while the filter is off, so switching it back on
    // restores it. Without the classes the posted list was never shown (and
    // so is empty): leave the stored one alone.
    if ($luxtronik2_classes) {
      if (Luxtronic2\LuxFilter::writeSelection($selection_file, $filter_values)) {
        $pluginconfig->set("SETTINGS","FILTER",$filter_mode);
      }
      else {
        $messages["error"][] = sprintf($L['MESSAGES.FILTER_NOTSAVED'], $selection_file);
      }
    }
    $pluginconfig->save();
    $messages["info"][] = $L['MESSAGES.SAVED'];
  }
  else {
    $messages["error"][] = $L['MESSAGES.NOTSAVED'];
  }
}

// Show the saved settings, except after a rejected save - then show what the
// user typed, so a typo can be corrected instead of retyped.
$rejected     = !empty($messages["error"]) && !empty($_POST);
$form_ip      = $rejected ? $ip       : $pluginconfig->get("SETTINGS","IP", "");
$form_port    = $rejected ? $port     : $pluginconfig->get("SETTINGS","PORT", "");
$form_pass    = $rejected ? $password : $pluginconfig->get("SETTINGS","PASSWORD", "");
$form_cycle   = $rejected ? $cycle    : $pluginconfig->get("SETTINGS","CRONCYCLE", "");

$cron_checked = "";
if ($rejected ? isset($_POST["luxtronik2-cron"]) : $pluginconfig->getBool("SETTINGS","CRON")) {
  $cron_checked = "checked=\"\"";
}

// Value filter: offer every name read since boot, plus every saved one - a
// selected value the controller did not deliver lately must stay visible, or
// saving the page would silently drop it from the selection.
$form_filter = $rejected ? $filter_mode : $pluginconfig->get("SETTINGS","FILTER","all");
$filter_sections = array();
$filter_available = array();
if ($luxtronik2_classes) {
  $form_values = $rejected ? $filter_values : Luxtronic2\LuxFilter::readSelection($selection_file);
  $filter_available = Luxtronic2\LuxFilter::readAvailable($available_file);
  $all_names = array_unique(array_merge(array_keys($filter_available), $form_values));
  sort($all_names, SORT_STRING);
  $form_selected = array_flip($form_values);
  foreach ($all_names as $name) {
    $filter_sections[Luxtronic2\LuxFilter::section($name)][] = array(
      "name"      => $name,
      "value"     => array_key_exists($name, $filter_available) ? (string) $filter_available[$name] : NULL,
      "checked"   => isset($form_selected[$name]),
    );
  }
}

$template_title = "Luxtronik 2";
$helplink = "http://www.loxwiki.eu/display/LOXBERRY/Luxtronik2";
$helptemplate = "help.html";

LBWeb::lbheader($template_title, $helplink, $helptemplate);

foreach ($messages as $type => $type_messages) {
  echo "<div class=\"message $type\"><ul>";
  foreach ($type_messages as $type_message) {
    echo "<li>" . h($type_message) . "</li>";
  }
  echo "</ul></div>";
}

?>

  <style>
    .message {
      border: 1px solid;
      margin-bottom: 1em;
    }

    .message.error {
      border-color: red;
      background: #fff5f5;
      color: red;
    }

    .message.info {
      border-color: green;
      background: #f6fff6;
      color: green;
    }

    .luxtronik2-form-submit {
      margin-top: 4em;
      display: flex;
      justify-content: center;
    }

    .luxtronik2-settings h2 {
      margin-top: 3em;
    }

    .luxtronik2-settings h2:first-of-type {
      margin-top: 0;
    }

    .luxtronik2-value {
      float: right;
      font-weight: normal;
      opacity: 0.7;
    }

    .luxtronik2-notice {
      font-weight: bold;
    }
  </style>

  <form class="luxtronik2-settings" method="post" name="settings">
    <h2><?= $L['HPSETTINGS.TITLE'] ?></h2>

    <div class="ui-field-contain">
      <label for="luxtronik2-ip"><?= $L['HPSETTINGS.IP'] ?>:</label>
      <input type="text" name="luxtronik2-ip" id="luxtronik2-ip" placeholder="192.168.178.1" value="<?= h($form_ip) ?>">
    </div>

    <div class="ui-field-contain">
      <label for="luxtronik2-port"><?= $L['HPSETTINGS.PORT'] ?>:</label>
      <input type="number" name="luxtronik2-port" id="luxtronik2-port" min="1" max="65535" placeholder="8214" value="<?= h($form_port) ?>">
    </div>

    <div class="ui-field-contain">
      <label for="luxtronik2-password"><?= $L['HPSETTINGS.PASSWORD'] ?>:</label>
      <input type="password" name="luxtronik2-password" id="luxtronik2-password" placeholder="999999" value="<?= h($form_pass) ?>">
    </div>

    <h2><?= $L['PSETTINGS.TITLE'] ?></h2>

    <div class="ui-field-contain">
      <label for="luxtronik2-cron"><?= $L['PSETTINGS.FETCH'] ?>:</label>
      <input type="checkbox" data-role="flipswitch" name="luxtronik2-cron" id="luxtronik2-cron" data-on-text="Ja" data-off-text="Nein" data-wrapper-class="luxtronik2-cron" <?= $cron_checked ?>>
    </div>

    <div class="ui-field-contain luxtronik2-croncycle-wrapper">
      <label for="luxtronik2-croncycle" class="select"><?= $L['PSETTINGS.CYCLE'] ?>:</label>
      <select name="luxtronik2-croncycle" id="luxtronik2-croncycle">
        <?php

        foreach ($croncycle_options as $key => $value) {
          $text = $value["text"];
          if ($form_cycle == $key) {
            echo "<option value=\"$key\" selected=\"selected\">$text</option>";
          }
          else {
            echo "<option value=\"$key\">$text</option>";
          }
        }

        ?>
      </select>
    </div>

    <h2><?= $L['FILTER.TITLE'] ?></h2>

    <p><?= h($L['FILTER.INTRO']) ?></p>

    <div class="ui-field-contain">
      <label for="luxtronik2-filter" class="select"><?= $L['FILTER.MODE'] ?>:</label>
      <select name="luxtronik2-filter" id="luxtronik2-filter"<?= $luxtronik2_classes ? "" : " disabled=\"disabled\"" ?>>
        <option value="all"<?= $form_filter === "selected" ? "" : " selected=\"selected\"" ?>><?= h($L['FILTER.MODE_ALL']) ?></option>
        <option value="selected"<?= $form_filter === "selected" ? " selected=\"selected\"" : "" ?>><?= h($L['FILTER.MODE_SELECTED']) ?></option>
      </select>
    </div>

    <div class="luxtronik2-values-wrapper">
      <?php if (!$luxtronik2_classes): ?>
        <p><?= h($L['MESSAGES.TEST_UNAVAILABLE']) ?></p>
      <?php else: ?>
        <p><?= h($L['FILTER.HINT']) ?></p>
        <?php if (empty($filter_available)): ?>
          <p class="luxtronik2-notice"><?= h($L['FILTER.NO_DATA']) ?></p>
        <?php endif; ?>
        <p class="luxtronik2-count"></p>
        <?php foreach ($filter_sections as $section => $entries): ?>
          <div data-role="collapsible" data-mini="true" class="luxtronik2-section">
            <h3><?= h($section) ?> <span class="luxtronik2-section-count"></span></h3>
            <p>
              <a href="#" class="luxtronik2-select-all"><?= h($L['FILTER.SELECT_ALL']) ?></a> &middot;
              <a href="#" class="luxtronik2-select-none"><?= h($L['FILTER.SELECT_NONE']) ?></a>
            </p>
            <fieldset data-role="controlgroup" data-mini="true">
              <?php foreach ($entries as $entry):
                $id = "luxtronik2-value-" . md5($entry["name"]); ?>
                <input type="checkbox" name="luxtronik2-values[]" id="<?= $id ?>" value="<?= h($entry["name"]) ?>"<?= $entry["checked"] ? " checked=\"checked\"" : "" ?>>
                <label for="<?= $id ?>"><?= h($entry["name"]) ?>
                  <span class="luxtronik2-value"><?= $entry["value"] === NULL ? h($L['FILTER.NOT_DELIVERED']) : h($entry["value"]) ?></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="ui-field-contain luxtronik2-form-submit">
      <input type="submit" value="<?= h($L['SETTINGS.SAVE']) ?>" data-icon="check">
      <input type="submit" name="luxtronik2-test" value="<?= h($L['SETTINGS.TEST']) ?>" data-icon="refresh">
    </div>
  </form>

  <div class="howto">
    <?= $L['HOWTO.TEXT'] ?>
  </div>

  <script>

    $("#luxtronik2-cron").bind("change", function(event, ui) {
      hideShowCronCycle();
    });

    hideShowCronCycle();

    function hideShowCronCycle() {
      if ($("#luxtronik2-cron").prop("checked")) {
        $(".luxtronik2-croncycle-wrapper").show();
      }
      else {
        $(".luxtronik2-croncycle-wrapper").hide();
      }
    }

    // Value filter: the list only matters when "selected values" is chosen.
    // Hidden checkboxes are still submitted, so the selection survives
    // switching the filter off and on again.
    $("#luxtronik2-filter").bind("change", hideShowValues);
    $(".luxtronik2-values-wrapper").on("change", "input[type=checkbox]", updateCounts);
    hideShowValues();
    updateCounts();

    function hideShowValues() {
      $(".luxtronik2-values-wrapper").toggle($("#luxtronik2-filter").val() === "selected");
    }

    function updateCounts() {
      var all = $(".luxtronik2-values-wrapper input[type=checkbox]");
      $(".luxtronik2-count").text(<?= json_encode($L['FILTER.COUNT']) ?>
        .replace("%1", all.filter(":checked").length).replace("%2", all.length));
      $(".luxtronik2-section").each(function() {
        var boxes = $(this).find("input[type=checkbox]");
        $(this).find(".luxtronik2-section-count")
          .text("(" + boxes.filter(":checked").length + "/" + boxes.length + ")");
      });
    }

    $(".luxtronik2-select-all, .luxtronik2-select-none").on("click", function(event) {
      event.preventDefault();
      $(this).closest(".luxtronik2-section").find("input[type=checkbox]")
        .prop("checked", $(this).hasClass("luxtronik2-select-all"))
        .checkboxradio("refresh");
      updateCounts();
    });

  </script>

<?php
// Finally print the footer
LBWeb::lbfooter();
?>