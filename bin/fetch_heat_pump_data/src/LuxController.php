<?php

namespace Luxtronic2;

use WebSocket\Client;

class LuxController {

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
  ];

  const listInformationItemNames = [
    'Fehlerspeicher',
    'Abschaltungen',
  ];

  private $ip;
  private $port;
  private $password;
  private $dataArray;
  private $client;

  public function __construct($ip, $port, $password) {
    $this->ip = $ip;
    $this->port = $port;
    $this->password = $password;
  }

  private function replaceCharacters($string) {
    $search  = ["Ä", "Ö", "Ü", "ä", "ö", "ü", "ß", "´", " ", "Ø", "."];
    $replace = ["Ae", "Oe", "Ue", "ae", "oe", "ue", "ss", "", "_", "ds", ""];

    return str_replace($search, $replace, $string);
  }

  private function convertKeyString($string) {
    return strtolower($this->replaceCharacters($string));
  }

  private function transformKeys(&$array) {
    foreach (array_keys($array) as $key):
      $value = &$array[$key];
      unset($array[$key]);
      $transformedKey = $this->convertKeyString($key);
      if (is_array($value)) {
        $this->transformKeys($value);
      }
      $array[$transformedKey] = $value;
      unset($value);
    endforeach;
  }

  private function connectToClient() {
    // support for php7.2 (phrity/websocket:1.5)
    $this->client = new Client("ws://$this->ip:$this->port", [
      'headers' => [
        'Sec-WebSocket-Protocol' => 'Lux_WS',
      ],
    ]);
    // Use the following two lines instead after switching to
    // php8.x (phrity/websocket:latest)
    //    $this->client = new Client("ws://$this->ip:$this->port");
    //    $this->client->addHeader("Sec-WebSocket-Protocol", "Lux_WS");
    $this->client->text("LOGIN;$this->password");
  }

  private function collectData() {
    // Use the following two lines instead after switching to
    // php8.x (phrity/websocket:latest
    //    $message = $this->client->receive();
    //    $xmlData = $message->getContent();
    // support for php7.2 (phrity/websocket:1.5)
    $xmlData = $this->client->receive();

    $data    = json_decode(json_encode((array) simplexml_load_string($xmlData)), TRUE);
    $ids     = [];

    foreach ($data['item'] as $item) {
      if ($item['name'] === "Informationen") {
        foreach ($item['item'] as $infoItem) {
          $ids[] = $infoItem['@attributes']['id'];
        }
      }
    }

    foreach ($ids as $id) {
      $this->client->text('GET;' . $id);
      // Use the following two lines instead after switching to
      // php8.x (phrity/websocket:latest
      //      $idMessage = $this->client->receive();
      //      $idXmlData = $idMessage->getContent();
      // support for php7.2 (phrity/websocket:1.5)
      $idXmlData = $this->client->receive();

      $idData    = json_decode(json_encode((array) simplexml_load_string($idXmlData)), TRUE);
      $this->collectItemData($idData);
    }
  }

  private function collectItemData($data) {
    if (is_array($data["name"])) {
      $data["name"] = reset($data["name"]);
    }

    $currentDataName = trim($data['name']);

    if ($currentDataName === "Energiemonitor") {
      foreach ($data['item'] as $currentDataItem) {
        $this->collectItemData($currentDataItem);
      }
    }

    $currentDataArray = [];

    if (in_array($currentDataName, LuxController::simpleInformationItemNames)) {
      foreach ($data['item'] as $currentDataItem) {
        $currentDataArray[$currentDataItem['name']] = $currentDataItem['value'];
      }
      $this->dataArray[$currentDataName] = $currentDataArray;
    }
    elseif ($currentDataName === 'GLT') {
      $this->dataArray[$currentDataName] = [$data['item']['name'] => $data['item']['value']];
    }
    elseif (in_array($currentDataName, LuxController::listInformationItemNames)) {
      foreach ($data['item'] as $currentDataItem) {
        $currentDataArray[] = [
          'name'    => $currentDataItem['value'],
          'uhrzeit' => $currentDataItem['name'],
        ];
      }
      $this->dataArray[$currentDataName] = $currentDataArray;
    }
  }

  public function getData() {
    $this->connectToClient();
    $this->collectData();
    $this->transformKeys($this->dataArray);
    $this->client->close();
    return $this->dataArray;
  }
}
