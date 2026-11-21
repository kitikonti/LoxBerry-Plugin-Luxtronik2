<?php

namespace Luxtronic2;

/**
 * The optional value filter: publish only the values a user actually has a
 * virtual input for.
 *
 * Why it exists: the MQTT Gateway sends EVERY value it gets to the Miniserver as
 * an HTTP request, and a value without a matching virtual input answers 404.
 * Since Miniserver firmware 17.3 each 404 costs the sender penalty points in the
 * Miniserver's application firewall, up to throttling (HTTP 429) and blocking.
 *
 * Values are identified by the name the Gateway gives them - the exact
 * designation of the virtual input in Loxone Config, e.g.
 * "luxtronik2_ablaufzeiten_wp##_seit" - so a user can copy names straight from
 * the Gateway's Incoming Overview and the filter can be checked against the
 * Loxone project character for character.
 *
 * The filter only ever DROPS keys. A kept value is published byte for byte as
 * before (including the "-" for empty readings), so no Miniserver input changes.
 *
 * No LoxBerry dependency, like LuxController.
 */
class LuxFilter {

  /**
   * The one MQTT topic everything is published on, as a single retained JSON
   * message. The Gateway flattens it into luxtronik2_<section>_<key> names.
   * Renaming it renames every Miniserver input.
   */
  const TOPIC = 'luxtronik2';

  /** Publish everything - the default, and the behaviour before the filter. */
  const MODE_ALL = 'all';

  /** Publish only the names in the selection. */
  const MODE_SELECTED = 'selected';

  /** The selection, one Gateway name per line, in the plugin config dir. */
  const SELECTION_FILE = 'published_values.txt';

  /**
   * Every name seen since boot with its latest value, written by fetch.php into
   * the plugin's log dir (a RAM disk) so the settings page can offer them.
   */
  const AVAILABLE_FILE = 'available_values.json';

  /**
   * The Gateway's name for every leaf of $data, as name => value.
   *
   * Mirrors what mqttgateway.pl does with a JSON payload: Hash::Flatten with
   * HashDelimiter and ArrayDelimiter both '_' and EscapeSequence '#', then
   * "<topic>/" prepended and '/' replaced by '_'. Hash::Flatten escapes the
   * escape sequence itself, then every delimiter once per delimiter - with two
   * identical delimiters an underscore inside a key therefore becomes '##_'.
   * That is why "wp_seit" shows up as "wp##_seit" while the separators stay a
   * plain '_'. List indices (the error and shutdown logs) are plain digits.
   *
   * @param array $data the payload as published (keys already transformed)
   * @return array name => scalar value
   */
  public static function names(array $data) {
    $result = [];
    self::walk($data, self::TOPIC, $result);
    return $result;
  }

  private static function walk(array $node, $prefix, array &$result) {
    foreach ($node as $key => $value) {
      $name = $prefix . '_' . self::escape((string) $key);
      if (is_array($value)) {
        self::walk($value, $name, $result);
      }
      else {
        $result[$name] = $value;
      }
    }
  }

  private static function escape($key) {
    return str_replace('_', '##_', str_replace('#', '##', $key));
  }

  /**
   * $data with every leaf removed whose name is not in $selected. Sections left
   * empty are dropped entirely; the structure and order of what remains are
   * untouched, so its flattened names are exactly the ones selected.
   *
   * @param array    $data
   * @param string[] $selected Gateway names
   * @return array
   */
  public static function apply(array $data, array $selected) {
    return self::prune($data, self::TOPIC, array_flip($selected));
  }

  private static function prune(array $node, $prefix, array $keep) {
    $result = [];
    foreach ($node as $key => $value) {
      $name = $prefix . '_' . self::escape((string) $key);
      if (is_array($value)) {
        $value = self::prune($value, $name, $keep);
        if ($value !== []) {
          $result[$key] = $value;
        }
      }
      elseif (isset($keep[$name])) {
        $result[$key] = $value;
      }
    }
    return $result;
  }

  /**
   * The section a Gateway name belongs to - its second segment, as the
   * settings page groups by it. The segment separators are the '_' NOT preceded
   * by '#'.
   */
  public static function section($name) {
    $prefix = self::TOPIC . '_';
    if (strpos($name, $prefix) === 0) {
      $name = substr($name, strlen($prefix));
    }
    $parts = preg_split('/(?<!#)_/', $name, 2);
    return $parts[0];
  }

  /**
   * Normalise a list of names: trimmed, no blanks, no control characters,
   * unique, sorted.
   *
   * @param string[] $names
   * @return string[]
   */
  public static function clean(array $names) {
    $result = [];
    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name !== '' && !preg_match('/[\x00-\x1F\x7F]/', $name)) {
        $result[$name] = TRUE;
      }
    }
    $result = array_keys($result);
    sort($result, SORT_STRING);
    return $result;
  }

  /** @return string[] the saved selection; empty when there is none. */
  public static function readSelection($file) {
    if (!is_readable($file)) {
      return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    return $lines === FALSE ? [] : self::clean($lines);
  }

  /** @return bool */
  public static function writeSelection($file, array $names) {
    $names = self::clean($names);
    return file_put_contents($file, $names === [] ? '' : implode("\n", $names) . "\n", LOCK_EX) !== FALSE;
  }

  /** @return array name => last value; empty when nothing was read yet. */
  public static function readAvailable($file) {
    if (!is_readable($file)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($file), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Merge this run's names into the list of available ones. Names are never
   * removed until the RAM disk is wiped on boot: some values exist only in
   * certain states (the standing timer only while the pump stands), and the
   * settings page should still offer them while the pump is running.
   *
   * Written to a temp file and renamed, so the settings page never reads half a
   * file.
   *
   * @return bool
   */
  public static function updateAvailable($file, array $names) {
    $merged = array_merge(self::readAvailable($file), $names);
    ksort($merged, SORT_STRING);
    $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      return FALSE;
    }
    $temp = $file . '.tmp';
    return file_put_contents($temp, $json) !== FALSE && rename($temp, $file);
  }
}
