<?php

namespace Luxtronic2;

use WebSocket\Client;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

class LuxController {

  /**
   * Sections exported as a flat name => value map.
   *
   * These constants are the feature surface: adding a data point to the MQTT
   * payload is usually just adding its German label here. The names are the
   * ones the controller reports, so they are firmware- and language-dependent.
   */
  const simpleInformationItemNames = [
    'Temperaturen',
    'Eingänge',
    'Ausgänge',
    'Ablaufzeiten',
    'Betriebsstunden',
    'Anlagenstatus',
    'Wärmemenge',
    'Eingesetzte Energie',
    'Leistungsaufnahme',
    'GLT',
  ];

  /** Sections exported as a list of {name, uhrzeit} rows. */
  const listInformationItemNames = [
    'Fehlerspeicher',
    'Abschaltungen',
  ];

  /** Sections that group further sections instead of holding values. */
  const containerItemNames = [
    'Energiemonitor',
  ];

  /** The navigation node whose whole subtree we export. */
  const informationNodeName = 'Informationen';

  /** Seconds to wait for the controller, see connectToClient(). */
  const defaultTimeout = 10;

  private $ip;
  private $port;
  private $password;
  private $timeout;
  private $dataArray = [];
  private $warnings = [];
  private $client;

  public function __construct($ip, $port, $password, $timeout = self::defaultTimeout) {
    $this->ip       = $ip;
    $this->port     = $port;
    $this->password = $password;
    $this->timeout  = (int) $timeout > 0 ? (int) $timeout : self::defaultTimeout;
  }

  /**
   * Non-fatal oddities noticed during the last getData() call, for the caller
   * to log. Kept out of the class itself so LuxController stays free of any
   * LoxBerry dependency and can be reused by the web frontend later.
   *
   * @return string[]
   */
  public function getWarnings() {
    return $this->warnings;
  }

  /**
   * Read the whole "Informationen" subtree and return it as the array that is
   * published to MQTT.
   *
   * The key names of the returned array are a public contract: the MQTT Gateway
   * flattens them into Loxone Miniserver input names, so renaming one renames an
   * input in every existing installation.
   *
   * @return array
   * @throws LuxConnectionException when the controller cannot be reached or
   *   stops answering within the timeout.
   * @throws LuxProtocolException when it answers with something unexpected.
   */
  public function getData() {
    $this->dataArray = [];
    $this->warnings  = [];

    try {
      $this->connectToClient();
      $this->collectData();
    }
    catch (TimeoutException $e) {
      // php8.x (phrity/websocket:2.x):
      //   catch (\WebSocket\Exception\ConnectionTimeoutException $e)
      throw new LuxConnectionException(
        "No answer from the heat pump at $this->ip:$this->port within "
        . "$this->timeout s. The controller's web server is known to hang under "
        . "sustained polling; a power cycle usually clears it.",
        0,
        $e
      );
    }
    catch (ConnectionException $e) {
      // Covers connect (code 1100) and handshake (code 1101) failures.
      // php8.x (phrity/websocket:2.x):
      //   catch (\WebSocket\Exception\ClientException $e)
      throw new LuxConnectionException(
        "Cannot reach the heat pump at $this->ip:$this->port: " . $e->getMessage(),
        0,
        $e
      );
    }
    catch (\WebSocket\Exception $e) {
      // Everything else the library can raise, e.g. BadUriException on a blank
      // IP address.
      // php8.x (phrity/websocket:2.x):
      //   catch (\WebSocket\Exception\Exception $e)
      throw new LuxConnectionException(
        "WebSocket error talking to $this->ip:$this->port: " . $e->getMessage(),
        0,
        $e
      );
    }
    finally {
      // Runs before the exceptions above propagate, so the socket is always
      // released - including on the paths where the library already dropped it.
      $this->disconnectFromClient();
    }

    if ($this->dataArray === []) {
      throw new LuxProtocolException(
        'The heat pump returned no known section. Either the controller speaks '
        . 'another language or the firmware renamed the sections; expected one '
        . 'of: ' . implode(', ', array_merge(
            self::simpleInformationItemNames,
            self::listInformationItemNames,
            self::containerItemNames
          )) . '.'
      );
    }

    $this->transformKeys($this->dataArray);

    return $this->dataArray;
  }

  private function connectToClient() {
    // support for php7.4 (phrity/websocket:1.x)
    //
    // 'timeout' is the socket timeout in seconds. It applies to the TCP connect
    // *and* to every single receive(), which is the only thing standing between
    // a wedged controller and a cron job that blocks far into the next cycle -
    // the protocol has no documented server-side idle timeout. The library
    // default is 5 s; we choose our own so it cannot change under us.
    $this->client = new Client("ws://$this->ip:$this->port", [
      'headers' => [
        'Sec-WebSocket-Protocol' => 'Lux_WS',
      ],
      'timeout' => $this->timeout,
    ]);
    // Use the following three lines instead after switching to
    // php8.x (phrity/websocket:2.x, which requires php >= 8.0). The options
    // array is gone; every option has a setter, and setTimeout() replaces the
    // 'timeout' key:
    //    $this->client = new Client("ws://$this->ip:$this->port");
    //    $this->client->addHeader("Sec-WebSocket-Protocol", "Lux_WS");
    //    $this->client->setTimeout($this->timeout);
    $this->client->text("LOGIN;$this->password");
  }

  /**
   * Close the socket whatever state it is in. Never throws.
   *
   * In phrity/websocket 1.x, close() sends a close frame and then *blocks
   * reading* until the peer answers with one. A controller that does not answer
   * would turn a perfectly successful poll into a TimeoutException raised from a
   * finally block, which would also mask the original error. disconnect() drops
   * the socket unconditionally, so it is the one that matters.
   */
  private function disconnectFromClient() {
    if ($this->client === NULL) {
      return;
    }
    try {
      // php8.x (phrity/websocket:2.x): drop this call. There close() is a send
      // method that reconnects when the client is already disconnected.
      $this->client->close();
    }
    catch (\Throwable $e) {
      $this->warnings[] = 'The heat pump did not answer the WebSocket close '
        . 'handshake: ' . $e->getMessage();
    }
    try {
      $this->client->disconnect();
    }
    catch (\Throwable $e) {
      // Nothing left to do; the process is about to end anyway.
    }
    $this->client = NULL;
  }

  private function collectData() {
    // The reply to LOGIN is the complete navigation tree. Every <item> carries
    // both an id attribute and a <name> child, so the id of the "Informationen"
    // node is known without any further round trip.
    //
    // Those ids are heap pointers inside the controller and are only valid for
    // this connection - never cache them across runs, always LOGIN, parse the
    // navigation, then GET.
    $navigation = $this->receiveDocument('Navigation');

    $informationId = NULL;
    $topLevelNames = [];
    foreach (self::childItems($navigation) as $item) {
      $name            = trim(self::nodeName($item));
      $topLevelNames[] = $name;
      if ($informationId === NULL
        && $name === self::informationNodeName
        && isset($item['@attributes']['id'])
      ) {
        $informationId = $item['@attributes']['id'];
      }
    }

    if ($informationId === NULL) {
      throw new LuxProtocolException(sprintf(
        'The navigation tree has no "%s" node. The controller offers: %s.',
        self::informationNodeName,
        $topLevelNames === [] ? '(nothing)' : implode(', ', $topLevelNames)
      ));
    }

    // ONE GET on the "Informationen" node returns the entire subtree with all
    // values: every section (Temperaturen, Eingänge, Betriebsstunden,
    // Fehlerspeicher, Anlagenstatus, Wärmemenge, GLT, Energiemonitor, ...)
    // nested inside a single <Content> document. This plugin used to issue one
    // GET per section, i.e. ten round trips for exactly the same data - a lot of
    // load for a controller whose web server hangs under sustained polling.
    // Same approach as hansmi/wp2reg-luxws and ioBroker.luxtronik2.
    $this->client->text('GET;' . $informationId);
    $content = $this->receiveDocument('Content');

    // Each child of <Content> is one section, in exactly the shape the
    // per-section GET replies used to have.
    foreach (self::childItems($content) as $section) {
      $this->collectItemData($section);
    }
  }

  /**
   * Receive one frame and parse it into the nested-array shape the rest of this
   * class works on.
   *
   * The json round trip is what gives us: attributes under '@attributes', a
   * single repeated child element as a plain associative array, several as a
   * list, and an empty element as an empty array - which is why missing values
   * are exported as '-' rather than null.
   *
   * @param string $expectedRootName 'Navigation' or 'Content'
   * @return array
   * @throws LuxProtocolException
   */
  private function receiveDocument($expectedRootName) {
    // support for php7.4 (phrity/websocket:1.x) - receive() returns the payload
    // as a string, or null when the peer sent a close frame.
    $xmlData = $this->client->receive();
    // Use the following two lines instead after switching to
    // php8.x (phrity/websocket:2.x) - receive() returns a Message object there:
    //    $message = $this->client->receive();
    //    $xmlData = $message === NULL ? '' : $message->getContent();

    $xml = @simplexml_load_string((string) $xmlData);
    if ($xml === FALSE) {
      throw new LuxProtocolException(
        "Expected a <$expectedRootName> document from the heat pump, got "
        . 'something that is not XML: ' . self::excerpt($xmlData)
      );
    }
    if ($xml->getName() !== $expectedRootName) {
      throw new LuxProtocolException(
        "Expected a <$expectedRootName> document from the heat pump, got <"
        . $xml->getName() . '>.'
      );
    }

    $json = json_encode((array) $xml);
    if ($json === FALSE) {
      throw new LuxProtocolException(
        "The <$expectedRootName> document could not be re-encoded (probably a "
        . 'truncated frame): ' . json_last_error_msg()
      );
    }
    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      throw new LuxProtocolException(
        "The <$expectedRootName> document did not parse into an array."
      );
    }

    return $data;
  }

  /**
   * Turn one section of the "Informationen" subtree into $dataArray.
   *
   * $data is one <item> child of the <Content> root: attributes, one (or on some
   * firmwares two) <name> children, and a list of <item> children.
   */
  private function collectItemData($data) {
    $currentDataName = trim(self::nodeName($data));

    if (in_array($currentDataName, self::containerItemNames, TRUE)) {
      // "Energiemonitor" holds further sections rather than values. Its children
      // are handled exactly like top level sections; the node itself contributes
      // nothing.
      foreach (self::childItems($data) as $currentDataItem) {
        $this->collectItemData($currentDataItem);
      }
      return;
    }

    $currentDataArray = [];

    if (in_array($currentDataName, self::simpleInformationItemNames, TRUE)) {
      $items = self::childItems($data);

      // Some firmwares report two children with the SAME name in one section:
      // "HD" appears both as a digital input (Aus/Ein) and as a pressure
      // (9.45 bar), "HUP" as on/off and as a modulation percentage. They used
      // to collapse onto one key and the later one silently won, losing the
      // other value entirely.
      //
      // The LAST occurrence keeps the bare key, so every Miniserver input keeps
      // the value it has always had; earlier occurrences get a _1, _2, ...
      // suffix. That makes this purely additive - nothing moves, the previously
      // discarded values simply start being published.
      $occurrences = [];
      foreach ($items as $currentDataItem) {
        $name = self::nodeName($currentDataItem);
        $occurrences[$name] = isset($occurrences[$name]) ? $occurrences[$name] + 1 : 1;
      }

      $index = [];
      foreach ($items as $currentDataItem) {
        // Deliberately NOT trimmed: a child name is a Miniserver input name, and
        // replaceCharacters() turns a trailing space into a trailing '_'.
        // Trimming here would rename inputs in existing installations.
        $name = self::nodeName($currentDataItem);
        $key  = $name;
        if ($occurrences[$name] > 1) {
          $index[$name] = isset($index[$name]) ? $index[$name] + 1 : 1;
          if ($index[$name] < $occurrences[$name]) {
            // rtrim only on the suffixed key - it is new, so it cannot move an
            // existing input, and "HD _1" would otherwise become "hd__1".
            $key = rtrim($name) . '_' . $index[$name];
          }
        }
        $currentDataArray[$key] = self::nodeValue($currentDataItem);
      }
      $this->dataArray[$currentDataName] = $currentDataArray;
    }
    elseif (in_array($currentDataName, self::listInformationItemNames, TRUE)) {
      // Error / shutdown logs: the controller puts the timestamp in <name> and
      // the message in <value>, so the two are swapped on purpose here.
      foreach (self::childItems($data) as $currentDataItem) {
        $currentDataArray[] = [
          'name'    => self::nodeValue($currentDataItem),
          'uhrzeit' => isset($currentDataItem['name']) && !is_array($currentDataItem['name'])
            ? $currentDataItem['name']
            : '-',
        ];
      }
      $this->dataArray[$currentDataName] = $currentDataArray;
    }
  }

  /**
   * The <name> of a node.
   *
   * Some firmwares emit two <name> children per item - the item's own name
   * first, the title of the parent page last - which simplexml turns into an
   * array. Keep the first. Not trimmed; callers that need a trimmed section name
   * trim explicitly.
   *
   * @return string
   */
  private static function nodeName($node) {
    if (!isset($node['name'])) {
      return '';
    }
    if (is_array($node['name'])) {
      $first = reset($node['name']);
      return is_string($first) ? $first : '';
    }
    return (string) $node['name'];
  }

  /**
   * The <value> of a node. Missing and empty values (which parse as arrays) are
   * exported as '-', never as null.
   */
  private static function nodeValue($node) {
    if (!isset($node['value']) || is_array($node['value'])) {
      return '-';
    }
    return $node['value'];
  }

  /**
   * The <item> children of a node, always as a list.
   *
   * simplexml collapses a single repeated element into the element itself, so a
   * node with exactly one <item> child - "GLT" is the known case - yields an
   * associative array instead of a list. Both are normalised here, which is what
   * lets GLT share the plain simpleInformationItemNames branch.
   *
   * @return array[]
   */
  private static function childItems($node) {
    if (!is_array($node) || !isset($node['item']) || !is_array($node['item'])) {
      return [];
    }
    $items = $node['item'];
    if ($items === []) {
      return [];
    }
    // A list has consecutive integer keys; a single collapsed child has string
    // keys ('@attributes', 'name', 'value', 'item').
    return array_key_exists(0, $items) ? $items : [$items];
  }

  private static function excerpt($string) {
    $string = trim(preg_replace('/\s+/', ' ', (string) $string));
    if ($string === '') {
      return '(empty frame)';
    }
    return strlen($string) > 120 ? substr($string, 0, 120) . '...' : $string;
  }

  private static function replaceCharacters($string) {
    $search  = ["Ä", "Ö", "Ü", "ä", "ö", "ü", "ß", "´", " ", "Ø", "."];
    $replace = ["Ae", "Oe", "Ue", "ae", "oe", "ue", "ss", "", "_", "ds", ""];

    return str_replace($search, $replace, $string);
  }

  private static function convertKeyString($string) {
    return strtolower(self::replaceCharacters($string));
  }

  /**
   * Apply the published-key convention to an array that did not come from the
   * WebSocket - notably the status lines, which arrive over a different
   * protocol and so never pass through transformKeys().
   *
   * Kept here so there is exactly one definition of what a published key looks
   * like: get this wrong and the section lands in MQTT as "Status_Zeile1 Code"
   * instead of "status_zeile1_code".
   */
  public static function toMqttKeys(array $array) {
    $result = [];
    foreach ($array as $key => $value) {
      $result[self::convertKeyString($key)] = is_array($value)
        ? self::toMqttKeys($value)
        : $value;
    }
    return $result;
  }

  /**
   * Rewrite every key recursively into its MQTT form.
   *
   * Two distinct labels can normalise to the same key ("Temp. Vorlauf" and
   * "Temp Vorlauf" both become "temp_vorlauf"). The last one wins, as it always
   * has - changing that would move a Miniserver input - but the collision is
   * recorded so it shows up in the log instead of a value silently vanishing.
   */
  private function transformKeys(&$array, $path = '') {
    $seen = [];
    foreach (array_keys($array) as $key):
      $value = &$array[$key];
      unset($array[$key]);
      $transformedKey = self::convertKeyString($key);
      if (isset($seen[$transformedKey])) {
        $this->warnings[] = sprintf(
          'MQTT key collision: "%s" and "%s" both become "%s%s"; only the last '
          . 'one is published.',
          $seen[$transformedKey], $key, $path, $transformedKey
        );
      }
      $seen[$transformedKey] = $key;
      if (is_array($value)) {
        $this->transformKeys($value, $path . $transformedKey . '_');
      }
      $array[$transformedKey] = $value;
      unset($value);
    endforeach;
  }
}
