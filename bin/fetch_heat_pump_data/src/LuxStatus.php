<?php

namespace Luxtronic2;

/**
 * Composes the status line the controller shows on its display.
 *
 * This is DERIVED, not read: neither protocol exposes that text. See CLAUDE.md
 * for the full investigation - in short, the WebSocket has no status node and
 * the binary protocol's HauptMenuStatus fields read zero on FW V3.90.3.
 *
 * Everything needed is already in the payload though, and the arithmetic was
 * verified against a real display: the controller derives its own "seit" timer
 * from the most recent shutdown timestamp exactly the same way (index 115 of
 * the binary array plus the displayed 12762 s landed on the wall clock to the
 * second).
 *
 * Deliberately conservative: every field is omitted rather than guessed when
 * the inputs are missing, and nothing here overwrites a value the controller
 * actually sent.
 */
class LuxStatus {

  /** How the controller writes timestamps in the Abschaltungen list. */
  const TIMESTAMP_FORMAT = 'd.m.y H:i:s';

  /** Our own "the controller sent nothing" marker, see LuxController. */
  const MISSING = '-';

  /**
   * Build the status section from an already key-transformed payload.
   *
   * @param array $data      the array getData() returned
   * @param int   $now        unix timestamp, injectable so this can be tested
   * @param string $modeLabel  the human label for that code, used as the reason
   *   while running so the text reads like the display
   * @param int   $modeCode   ID_WEB_WP_BZ_akt from LuxCalculations, when the
   *   binary socket answered. Authoritative: it reports an active mode during
   *   the Pumpenvorlauf phase, where the compressor is still off and
   *   Betriebszustand alone cannot tell "standing" from "about to start".
   * @return array|null  null when there is not enough information
   */
  public static function compose(array $data, $now = NULL, $modeCode = NULL, $modeLabel = NULL) {
    $now = $now === NULL ? time() : $now;

    $running = $modeCode !== NULL
      ? ((int) $modeCode !== LuxCalculations::MODE_NO_REQUEST)
      : self::isRunning($data);
    if ($running === NULL) {
      return NULL;
    }

    $status = [
      // Numeric first: Loxone logic should switch on this, not on the text.
      'Laeuft' => $running ? '1' : '0',
      'Zeile1' => $running ? 'Wärmepumpe läuft' : 'Wärmepumpe steht',
    ];

    // The reason: while running the controller names the active mode, while
    // standing it is whatever last shut it down.
    // While running the mode label matches the display exactly ("Warmwasser"),
    // where Betriebszustand only abbreviates it ("WW") - so prefer the label
    // and fall back to the WebSocket when the binary socket did not answer.
    $reason = $running
      ? ($modeLabel !== NULL ? $modeLabel : self::value($data, ['anlagenstatus', 'betriebszustand']))
      : self::value($data, ['abschaltungen', 0, 'name']);
    if ($reason !== NULL) {
      // Passed through verbatim. The controller abbreviates in the Abschaltungen
      // list ("keine Anf." where the display writes "Keine Anforderung"); that
      // is its own wording, so it is not expanded here.
      $status['Grund'] = $reason;
    }

    // The elapsed time is only derivable while the pump is standing, from the
    // timestamp of the shutdown that stopped it. Nothing in either protocol
    // records when a *run* started, so that case gets no timer rather than a
    // made up one.
    if (!$running) {
      $since = self::value($data, ['abschaltungen', 0, 'uhrzeit']);
      $seconds = $since === NULL ? NULL : self::secondsSince($since, $now);
      if ($seconds !== NULL) {
        $status['Seit']           = self::formatDuration($seconds);
        $status['Seit Sekunden']  = (string) $seconds;
      }
    }

    $status['Text'] = self::line($status);

    return $status;
  }

  /**
   * TRUE while the heat pump is working, FALSE while it stands, NULL when the
   * payload does not say.
   *
   * Betriebszustand is the controller's own operating state and carries the
   * active mode while running; it is empty whenever there is no demand. The
   * compressor output is the fallback for firmwares that leave it empty always.
   */
  private static function isRunning(array $data) {
    $mode = self::value($data, ['anlagenstatus', 'betriebszustand']);
    if ($mode !== NULL) {
      return TRUE;
    }
    $compressor = self::value($data, ['ausgaenge', 'verdichter']);
    if ($compressor === NULL) {
      // Neither field present - say nothing rather than guess "standing".
      return isset($data['anlagenstatus']) || isset($data['ausgaenge']) ? FALSE : NULL;
    }
    return strcasecmp(trim($compressor), 'Ein') === 0;
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
   * Returns NULL if it does not parse, or if it is in the future - a controller
   * whose clock is ahead would otherwise produce a negative duration.
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

  /** The whole thing as one line, mirroring the controller's three rows. */
  private static function line(array $status) {
    $line = $status['Zeile1'];
    if (isset($status['Seit'])) {
      $line .= ' seit ' . $status['Seit'];
    }
    if (isset($status['Grund'])) {
      $line .= ' - ' . $status['Grund'];
    }
    return $line;
  }
}
