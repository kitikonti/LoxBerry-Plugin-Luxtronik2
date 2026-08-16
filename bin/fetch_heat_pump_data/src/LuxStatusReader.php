<?php

namespace Luxtronic2;

/**
 * Reads the controller's main-menu status lines over the legacy binary protocol.
 *
 * The WebSocket interface on 8214 does NOT carry the status text the controller
 * shows on its display ("Wärmepumpe steht" / "seit" / "Keine Anforderung") - its
 * only operating-state field is Anlagenstatus/Betriebszustand, which is simply
 * empty whenever there is no active demand. Verified by dumping the complete
 * navigation tree of a FW V3.90.3 controller: no node contains that text.
 *
 * The status lines live in the binary "calculations" array instead, which is
 * served on a plain TCP socket - port 8889 on firmware >= 3.81, port 8888 on
 * older ones. Request 3004 returns the whole array; indices 117/118/119 are the
 * three display lines as enum codes.
 *
 * This reader is deliberately narrow: it asks for the calculations array, takes
 * those three values and nothing else. It never writes.
 */
class LuxStatusReader {

  /** Read the "calculations" array. */
  const READ_CALCULATIONS = 3004;

  /** Indices within that array. */
  const IDX_LINE1 = 117;
  const IDX_LINE2 = 118;
  const IDX_LINE3 = 119;

  /** Newer firmware serves the socket on 8889, older on 8888. */
  const DEFAULT_PORTS = [8889, 8888];

  /**
   * A sane upper bound on the array length, used to reject a reply that is not
   * actually this protocol. Real controllers report a few hundred values (272
   * on FW V3.90.3).
   */
  const MAX_VALUES = 2000;

  /**
   * German labels for the enum codes.
   *
   * "Wärmepumpe läuft", "Wärmepumpe steht", "seit", "Keine Anforderung" and
   * "Heizbetrieb" are confirmed verbatim against a real controller display. The
   * remaining labels are translated from the English ones in python-luxtronik
   * and may not match the controller's exact wording - the numeric code is
   * published alongside so nothing depends on the translation.
   */
  const LINE1_LABELS = [
    0 => 'Wärmepumpe läuft',
    1 => 'Wärmepumpe steht',
    2 => 'Wärmepumpe kommt',
    3 => 'Fehler',
    4 => 'Abtauen',
    5 => 'Warte auf LIN-Verbindung',
    6 => 'Verdichter heizt auf',
    7 => 'Pumpenvorlauf',
  ];

  const LINE2_LABELS = [
    0 => 'seit',
    1 => 'in',
  ];

  const LINE3_LABELS = [
    0  => 'Heizbetrieb',
    1  => 'Keine Anforderung',
    2  => 'Netzeinschaltverzögerung',
    3  => 'Schaltspielzeit',
    4  => 'Sperrzeit',
    5  => 'Brauchwasser',
    6  => 'Estrich Programm',
    7  => 'Abtauen',
    8  => 'Pumpenvorlauf',
    9  => 'Thermische Desinfektion',
    10 => 'Kühlbetrieb',
    12 => 'Schwimmbad/Solar',
    13 => 'Heizen Ext. Energiequelle',
    14 => 'Brauchwasser Ext. Energiequelle',
    16 => 'Durchflussüberwachung',
    17 => 'ZWE 1 aktiv',
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
   * Return the three status lines, both as text and as the raw enum code.
   *
   * @return array
   * @throws LuxConnectionException when no port answers.
   * @throws LuxProtocolException when something answers but not with this
   *   protocol.
   */
  public function read() {
    $values = $this->readCalculations();

    $line = function ($index, array $labels) use ($values) {
      if (!array_key_exists($index, $values)) {
        return ['text' => '-', 'code' => NULL];
      }
      $code = $values[$index];
      return [
        'text' => array_key_exists($code, $labels) ? $labels[$code] : "Code $code",
        'code' => $code,
      ];
    };

    $l1 = $line(self::IDX_LINE1, self::LINE1_LABELS);
    $l2 = $line(self::IDX_LINE2, self::LINE2_LABELS);
    $l3 = $line(self::IDX_LINE3, self::LINE3_LABELS);

    // Both forms are published on purpose: the text is what the controller
    // shows, the code is what Loxone logic should switch on - it is stable
    // across firmware wording and language.
    return [
      'Zeile1'      => $l1['text'],
      'Zeile1 Code' => $l1['code'] === NULL ? '-' : (string) $l1['code'],
      'Zeile2'      => $l2['text'],
      'Zeile2 Code' => $l2['code'] === NULL ? '-' : (string) $l2['code'],
      'Zeile3'      => $l3['text'],
      'Zeile3 Code' => $l3['code'] === NULL ? '-' : (string) $l3['code'],
    ];
  }

  /**
   * Request 3004 and return the calculations array, keyed by index.
   *
   * Wire format: send two big-endian int32 (command, 0), then read back the
   * echoed command, a status word, the number of values, and that many
   * big-endian int32.
   */
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
      "No status socket on $this->ip (tried " . implode(', ', $this->ports)
      . "; last error was $lastError). Firmware below 3.81 uses 8888, newer "
      . 'firmware 8889.'
    );
  }

  private function exchange() {
    stream_set_timeout($this->socket, $this->timeout);
    if (@fwrite($this->socket, pack('N2', self::READ_CALCULATIONS, 0)) === FALSE) {
      throw new LuxConnectionException("Could not send the status request to $this->ip.");
    }

    $command = $this->readInt32();
    if ($command !== self::READ_CALCULATIONS) {
      throw new LuxProtocolException(sprintf(
        'The status socket on %s answered with %s instead of echoing %d.',
        $this->ip, var_export($command, TRUE), self::READ_CALCULATIONS
      ));
    }
    $this->readInt32();                     // status word, unused
    $count = $this->readInt32();
    if ($count === NULL || $count < 1 || $count > self::MAX_VALUES) {
      throw new LuxProtocolException(sprintf(
        'The status socket on %s reported an implausible value count: %s.',
        $this->ip, var_export($count, TRUE)
      ));
    }

    $values = [];
    for ($i = 0; $i < $count; $i++) {
      $value = $this->readInt32();
      if ($value === NULL) {
        throw new LuxProtocolException(
          "The status socket on $this->ip closed after $i of $count values."
        );
      }
      $values[$i] = $value;
    }

    return $values;
  }

  /**
   * Read one big-endian signed 32 bit integer, or NULL on end of stream.
   *
   * PHP has no unpack format for a signed big-endian int, so it is read
   * unsigned and folded back into the negative range - values such as
   * temperatures below zero arrive that way.
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
