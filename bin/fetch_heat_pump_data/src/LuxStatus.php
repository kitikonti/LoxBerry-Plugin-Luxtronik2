<?php

namespace Luxtronic2;

/**
 * Computes how long the heat pump has been standing.
 *
 * This is the only value the plugin derives rather than reads, and it exists
 * because it is the one genuinely useful thing not already in the payload: the
 * controller publishes *when* it last stopped (abschaltungen[0].uhrzeit), not
 * *how long ago*, and turning that into an elapsed time in Loxone Config means
 * parsing "16.08.26 16:55:40" by hand.
 *
 * The arithmetic is the controller's own: it derives its display timer from the
 * same shutdown timestamp, verified to the second against a real display.
 *
 * Everything else is published verbatim. There is deliberately no composed
 * status text - see the code tables in README.md: the mode enum has 8 values
 * where the controller's display draws on 16, so any sentence built from it
 * would silently read "Warmwasser" while the display says "Pumpenvorlauf".
 *
 * While the pump runs, the controller's own counter is already published
 * verbatim as ablaufzeiten.wp_seit, so nothing is derived for that case.
 */
class LuxStatus {

  /** How the controller writes timestamps in the Abschaltungen list. */
  const TIMESTAMP_FORMAT = 'd.m.y H:i:s';

  /** Our own "the controller sent nothing" marker, see LuxController. */
  const MISSING = '-';

  /**
   * @param array $data      the array getData() returned
   * @param int   $modeCode  ID_WEB_WP_BZ_akt from LuxCalculations. Required:
   *   without it there is no trustworthy way to tell standing from starting.
   *   The compressor is still off during the controller's Pumpenvorlauf phase,
   *   so a heuristic based on it calls a starting pump "standing".
   * @param int   $now       unix timestamp, injectable so this can be tested
   * @return array  empty when the pump is not standing or the time is unusable
   */
  public static function compose(array $data, $modeCode = NULL, $now = NULL) {
    if ($modeCode === NULL
      || (int) $modeCode !== LuxCalculations::MODE_NO_REQUEST) {
      return [];
    }

    $since   = self::value($data, ['abschaltungen', 0, 'uhrzeit']);
    $seconds = $since === NULL
      ? NULL
      : self::secondsSince($since, $now === NULL ? time() : $now);
    if ($seconds === NULL) {
      return [];
    }

    return [
      'Steht Seit'          => self::formatDuration($seconds),
      'Steht Seit Sekunden' => (string) $seconds,
    ];
  }

  /** Fetch a nested value, or NULL if absent, empty or our missing marker. */
  private static function value(array $data, array $path) {
    $node = $data;
    foreach ($path as $key) {
      if (!is_array($node) || !array_key_exists($key, $node)) {
        return NULL;
      }
      $node = $node[$key];
    }
    if (!is_string($node)) {
      return NULL;
    }
    $node = trim($node);
    return ($node === '' || $node === self::MISSING) ? NULL : $node;
  }

  /**
   * Seconds between a controller timestamp ("16.08.26 16:55:40") and now.
   *
   * NULL if it does not parse, or if it is in the future - a controller whose
   * clock is ahead would otherwise produce a negative duration. Parsed in the
   * local timezone, which is correct as long as the controller's clock is also
   * local; do not force UTC.
   */
  private static function secondsSince($timestamp, $now) {
    $parsed = \DateTime::createFromFormat(self::TIMESTAMP_FORMAT, $timestamp);
    if ($parsed === FALSE) {
      return NULL;
    }
    $elapsed = $now - $parsed->getTimestamp();
    return $elapsed < 0 ? NULL : $elapsed;
  }

  /** Seconds as HH:MM:SS, hours not wrapping at 24 - the display counts on. */
  private static function formatDuration($seconds) {
    return sprintf('%02d:%02d:%02d',
      intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }
}
