<?php

namespace Luxtronic2;

/**
 * The values the plugin derives rather than reads.
 *
 * Both exist for the same reason: the controller publishes a *point in time*
 * where the useful thing is a *span*, and turning "16.08.26 16:55:40" into an
 * elapsed time in Loxone Config means parsing that date by hand.
 *
 * - How long the pump has been standing, from abschaltungen[0].uhrzeit. The
 *   arithmetic is the controller's own: it derives its display timer from the
 *   same shutdown timestamp, verified to the second against a real display.
 * - How long ago the newest Fehlerspeicher entry was recorded. Loxone shows it
 *   as "18.05.25 15:02:23 | VD Alarm (787)", leaving the reader to work out
 *   whether that was yesterday or fifteen months ago.
 *
 * Everything else is published verbatim. There is deliberately no composed
 * status text and no composed "vor 457 Tagen" either - see the code tables in
 * README.md: the controller supplies the words, the plugin only the numbers.
 * The mode enum has 8 values where the controller's display draws on 16, so any
 * sentence built from it would silently read "Warmwasser" while the display
 * says "Pumpenvorlauf".
 *
 * While the pump runs, the controller's own counter is already published
 * verbatim as ablaufzeiten.wp_seit, so nothing is derived for that case.
 */
class LuxStatus {

  /** How the controller writes timestamps in the list sections. */
  const TIMESTAMP_FORMAT = 'd.m.y H:i:s';

  /** Our own "the controller sent nothing" marker, see LuxController. */
  const MISSING = '-';

  const SECONDS_PER_DAY = 86400;

  /**
   * @param array $data      the array getData() returned
   * @param int   $modeCode  ID_WEB_WP_BZ_akt from LuxCalculations. Required for
   *   the standing timer: without it there is no trustworthy way to tell
   *   standing from starting. The compressor is still off during the
   *   controller's Pumpenvorlauf phase, so a heuristic based on it calls a
   *   starting pump "standing". The error age does not need it.
   * @param int   $now       unix timestamp, injectable so this can be tested
   * @return array  empty when neither value can be derived
   */
  public static function compose(array $data, $modeCode = NULL, $now = NULL) {
    $now = $now === NULL ? time() : $now;
    return self::standingSince($data, $modeCode, $now)
      + self::lastErrorAge($data, $now);
  }

  /**
   * How long the pump has been standing - only while it actually is.
   *
   * A timestamp in the future drops the field rather than being clamped: while
   * standing, the timer is the whole point, and a controller clock ahead of the
   * LoxBerry would have it stuck at zero for as long as the offset lasts.
   */
  private static function standingSince(array $data, $modeCode, $now) {
    if ($modeCode === NULL
      || (int) $modeCode !== LuxCalculations::MODE_NO_REQUEST) {
      return [];
    }

    $since = self::value($data, ['abschaltungen', 0, 'uhrzeit']);
    $when  = $since === NULL ? NULL : self::parseTimestamp($since);
    if ($when === NULL) {
      return [];
    }
    $seconds = $now - $when;
    if ($seconds < 0) {
      return [];
    }

    return [
      'Steht Seit'          => self::formatDuration($seconds),
      'Steht Seit Sekunden' => (string) $seconds,
    ];
  }

  /**
   * How long ago the newest Fehlerspeicher entry was recorded.
   *
   * Unlike the standing timer this is not tied to the mode code - the last
   * error is the last error whatever the pump is doing - so it is published on
   * every run.
   *
   * Seconds and whole days, and no HH:MM:SS variant: these spans run to months,
   * where a clock format that never wraps is unreadable.
   */
  private static function lastErrorAge(array $data, $now) {
    $since = self::value($data, ['fehlerspeicher', 0, 'uhrzeit']);
    $when  = $since === NULL ? NULL : self::parseTimestamp($since);
    if ($when === NULL) {
      // No entry at all, or a timestamp we cannot read: publish nothing rather
      // than a zero, which would read as "an error just happened". Loxone then
      // keeps the last value it received.
      return [];
    }
    // Clamped, not dropped: a controller clock a few seconds ahead of the
    // LoxBerry is drift, and an error age of zero is a truthful answer to
    // "how long ago" in a way a standing timer stuck at zero is not.
    $seconds = max(0, $now - $when);

    return [
      'Fehler Seit Sekunden' => (string) $seconds,
      'Fehler Seit Tage'     => (string) intdiv($seconds, self::SECONDS_PER_DAY),
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
   * A controller timestamp ("16.08.26 16:55:40") as a unix timestamp, or NULL
   * if it does not parse.
   *
   * Parsed in the local timezone, which is correct as long as the controller's
   * clock is also local; do not force UTC.
   *
   * PHP reads a two-digit year of 70-99 as 19xx. A Luxtronik 2 has no dates
   * before 2000, so such a year is a 21st-century one - without the correction
   * an entry stamped ".99" would be reported as decades old.
   */
  private static function parseTimestamp($timestamp) {
    $parsed = \DateTime::createFromFormat(self::TIMESTAMP_FORMAT, $timestamp);
    if ($parsed === FALSE) {
      return NULL;
    }
    if ((int) $parsed->format('Y') < 2000) {
      $parsed->modify('+100 years');
    }
    return $parsed->getTimestamp();
  }

  /** Seconds as HH:MM:SS, hours not wrapping at 24 - the display counts on. */
  private static function formatDuration($seconds) {
    return sprintf('%02d:%02d:%02d',
      intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }
}
