<?php

require_once "loxberry_io.php";
require_once "Config/Lite.php";
require_once "loxberry_web.php";
require_once "loxberry_log.php";

// This will read your language files to the array $L
$L = LBSystem::readlanguage("language.ini");
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