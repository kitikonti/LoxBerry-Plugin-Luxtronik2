<?php

namespace Luxtronic2;

/**
 * Reads the two numeric state codes from the legacy binary protocol.
 *
 * The WebSocket reports state as German text only, which is awkward to switch
 * on in Loxone Config. The binary "calculations" array carries the same state
 * as enum codes, served on a plain TCP socket - port 8889 on firmware >= 3.81,
 * 8888 on older ones. Send two big-endian int32 (3004, 0); read back the echoed
 * command, a status word, a count, then that many big-endian *signed* int32.
 *
 * Only two indices are taken, both verified against this controller's own
 * WebSocket payload:
 *
 *   80  current operation mode  - read 5 ("no request") while the pump stood
 *                                 with an empty Betriebszustand
 *   110 most recent shutdown    - read 9 while abschaltungen[0] said
 *       reason                    "keine Anf."; indices 106-110 read 9,9,9,26,9
 *                                 against the five listed shutdowns, and the
 *                                 111-115 timestamps matched their times exactly
 *
 * Note what is NOT here: ID_WEB_HauptMenuStatus_Zeile1/2/3 at indices 117-120.
 * They are documented as the controller's display lines but read zero on
 * FW V3.90.3, and a release was once built on a single lucky non-zero reading
 * of them. Do not add a field here without watching it *change* with the pump.
 */
class LuxCalculations {

  const READ_CALCULATIONS = 3004;

  /** The one mode meaning "not working"; every other code is an active mode. */
  const MODE_NO_REQUEST = 5;

  const IDX_OPERATION_MODE = 80;
  const IDX_LAST_SWITCHOFF = 110;

  /** Newer firmware serves the socket on 8889, older on 8888. */
  const DEFAULT_PORTS = [8889, 8888];

  /** Rejects a reply that is not actually this protocol (272 on FW V3.90.3). */
  const MAX_VALUES = 2000;

  /**
   * ID_WEB_WP_BZ_akt. Mirrors the third line of the controller display.
   * "Keine Anforderung" and "Warmwasser" are confirmed verbatim against a real
   * display; the rest follow the same naming.
   */
  const OPERATION_MODES = [
    0 => 'Heizbetrieb',
    1 => 'Warmwasser',
    2 => 'Schwimmbad/Solar',
    3 => 'EVU-Sperre',
    4 => 'Abtauen',
    5 => 'Keine Anforderung',
    6 => 'Heizen Ext. Energiequelle',
    7 => 'Kühlbetrieb',
  ];

  /**
   * ID_WEB_Switchoff_file_Nr. Translated from python-luxtronik's English
   * labels; the code is published alongside so nothing depends on the wording.
   * This controller reported 26, which that table does not list at all - hence
   * the "Code n" fallback rather than an error.
   */
  const SWITCHOFF_REASONS = [
    0  => 'WP-Fehler',
    1  => 'Anlagenfehler',
    2  => 'Betriebsart ZWE',
    3  => 'EVU-Sperre',
    5  => 'Luftabtauen',
    6  => 'Max. Einsatztemperatur',
    7  => 'Min. Einsatztemperatur',
    8  => 'Untere Einsatzgrenze',
    9  => 'Keine Anforderung',
    10 => 'Externe Energiequelle',
    11 => 'Durchfluss',
    12 => 'Niederdruck-Pause',
    13 => 'Überhitzungs-Pause',
    14 => 'Inverter-Pause',
    15 => 'Enthitzer-Pause',
    16 => 'Betriebsart Umschaltung',
    17 => 'Andere Abschaltung',
    18 => 'Min. Durchfluss Kühlung',
    19 => 'PV max',
    20 => 'Heissgas-Pause',
    21 => 'Überhitzung Heissgas',
    22 => 'Keine Anforderung',
    23 => 'Min. WQ-Austritt Kühlung',
    24 => 'LPC',
    25 => 'Neustart',
  ];

  private $ip;
  private $ports;
  private $timeout;
  private $socket;

  public function __construct($ip, $timeout = 5, array $ports = NULL) {
    $this->ip      = $ip;
    $this->timeout = (int) $timeout > 0 ? (int) $timeout : 5;
    $this->ports   = $ports === NULL ? self::DEFAULT_PORTS : $ports;
  }

  /**
   * @return array the code/label pairs, ready to merge into the status section
   * @throws LuxConnectionException when no port answers
   * @throws LuxProtocolException when something answers but not with this
   *   protocol
   */
  public function read() {
    $values = $this->readCalculations();

    $mode   = array_key_exists(self::IDX_OPERATION_MODE, $values)
      ? $values[self::IDX_OPERATION_MODE] : NULL;
    $reason = array_key_exists(self::IDX_LAST_SWITCHOFF, $values)
      ? $values[self::IDX_LAST_SWITCHOFF] : NULL;

    $out = [];
    if ($mode !== NULL) {
      $out['Modus Code'] = (string) $mode;
      $out['Modus']      = self::label(self::OPERATION_MODES, $mode);
    }
    if ($reason !== NULL) {
      // NOT "Grund Code": LuxStatus already publishes "Grund" for the CURRENT
      // reason ("Warmwasser" while running). Sharing that prefix would imply
      // this is its numeric form, when it is the last SHUTDOWN reason and while
      // running refers to a past event.
      $out['Abschaltung Code'] = (string) $reason;
      $out['Abschaltung Text'] = self::label(self::SWITCHOFF_REASONS, $reason);
    }
    return $out;
  }

  /** A known label, or "Code n" - firmwares report codes no table lists. */
  private static function label(array $labels, $code) {
    return array_key_exists($code, $labels) ? $labels[$code] : "Code $code";
  }

  private function readCalculations() {
    $lastError = '';
    foreach ($this->ports as $port) {
      $this->socket = @fsockopen($this->ip, $port, $errno, $errstr, $this->timeout);
      if ($this->socket) {
        try {
          return $this->exchange();
        }
        finally {
          if (is_resource($this->socket)) {
            fclose($this->socket);
          }
          $this->socket = NULL;
        }
      }
      $lastError = "$port: $errstr";
    }
    throw new LuxConnectionException(
      "No calculations socket on $this->ip (tried " . implode(', ', $this->ports)
      . "; last error was $lastError)."
    );
  }

  private function exchange() {
    stream_set_timeout($this->socket, $this->timeout);
    if (@fwrite($this->socket, pack('N2', self::READ_CALCULATIONS, 0)) === FALSE) {
      throw new LuxConnectionException("Could not send the request to $this->ip.");
    }

    $command = $this->readInt32();
    if ($command !== self::READ_CALCULATIONS) {
      throw new LuxProtocolException(sprintf(
        'The socket on %s answered with %s instead of echoing %d.',
        $this->ip, var_export($command, TRUE), self::READ_CALCULATIONS
      ));
    }
    $this->readInt32();                     // status word, unused
    $count = $this->readInt32();
    if ($count === NULL || $count < 1 || $count > self::MAX_VALUES) {
      throw new LuxProtocolException(sprintf(
        'The socket on %s reported an implausible value count: %s.',
        $this->ip, var_export($count, TRUE)
      ));
    }

    $values = [];
    for ($i = 0; $i < $count; $i++) {
      $value = $this->readInt32();
      if ($value === NULL) {
        throw new LuxProtocolException(
          "The socket on $this->ip closed after $i of $count values."
        );
      }
      $values[$i] = $value;
    }
    return $values;
  }

  /**
   * One big-endian signed 32 bit integer, or NULL at end of stream. PHP has no
   * signed big-endian unpack format, so it is read unsigned and folded back.
   */
  private function readInt32() {
    $buffer = '';
    while (strlen($buffer) < 4) {
      $chunk = fread($this->socket, 4 - strlen($buffer));
      if ($chunk === FALSE || $chunk === '') {
        return NULL;
      }
      $buffer .= $chunk;
    }
    $value = unpack('N', $buffer)[1];
    return $value >= 0x80000000 ? $value - 0x100000000 : $value;
  }
}
