# LoxBerry-Plugin-Luxtronik2

A LoxBerry plugin that reads a heat pump with a **Luxtronik 2 controller** (Alpha Innotec,
Novelan, Buderus and others) and publishes its values over MQTT to a Loxone Miniserver.

https://github.com/kitikonti/LoxBerry-Plugin-Luxtronik2

The plugin connects to the controller's web interface on a schedule, reads the whole
**Informationen** section, and publishes it as JSON on the MQTT topic `luxtronik2`. LoxBerry's
MQTT Gateway then forwards the values to the Miniserver. The MQTT subscription is registered
automatically — there is nothing to set up in the Gateway.

The plugin only reads. It never changes a setting on the heat pump.

## Requirements

* **LoxBerry 3.0 or newer** — the MQTT Gateway is part of LoxBerry from 3.0, so no separate
  gateway plugin is needed
* An MQTT broker configured and running in LoxBerry
* A Luxtronik 2 controller reachable over the network
* **Controller firmware V3.81 or newer.** From that version the web interface is served over
  WebSocket on **port 8214**. Older firmware is not supported.
* Optional, for the numeric status codes: **port 8889** (port 8888 on firmware below 3.81). If
  that port is not reachable everything else keeps working — only the `status_*` fields are
  missing.

## Installation

Use the normal LoxBerry plugin installation, with the release ZIP from
[the latest release](https://github.com/kitikonti/LoxBerry-Plugin-Luxtronik2/releases/latest).

The plugin supports LoxBerry's **automatic updates** (release and pre-release channel).
Installation runs composer to fetch the PHP dependencies, so the LoxBerry needs internet access
while installing.

## Configuration

The configuration page is reachable from the LoxBerry main menu.

| Setting | Meaning |
|---|---|
| **IP** | IP address or hostname of the heat pump |
| **Port** | Port of the web interface, default `8214` |
| **Password** | Password of the web interface, `999999` from the factory |
| **Fetch data automatically** | Creates or removes the cronjob |
| **Polling cycle** | 1, 3, 5, 10, 15, 30 or 60 minutes |

**Test connection** reads once from the heat pump using the values currently in the form,
without saving them — so a wrong address can be corrected before it becomes the cronjob's
problem.

> The password is not really being checked. The controller accepts **any** password for a
> read-only login, so the test answers "is the controller reachable and speaking the protocol",
> which is what actually goes wrong in practice.

> **A short polling cycle loads the controller's small web server.** There are field reports of
> it becoming unresponsive under sustained polling, needing the heat pump to be power-cycled
> (the pump itself keeps running). If in doubt, choose 5 minutes.

## Using the data in Loxone Config

The values reach the Miniserver through the MQTT Gateway. The general procedure is described in
[MQTT – Schritt für Schritt: MQTT → Loxone](https://wiki.loxberry.de/konfiguration/widget_help/widget_mqtt/mqtt_gateway/mqtt_schritt_fur_schritt_mqtt_loxone).
Short version for this plugin:

1. Open the MQTT Gateway's **Incoming Overview** and expand **HTTP Virtual Inputs**. Every value
   the plugin delivers is listed there, recognisable by the `luxtronik2_` prefix.
2. Copy the name you want with the **Copy** button.
3. In Loxone Config, under *Virtuelle Eingänge*, create a **Virtueller Eingang** (a **Virtueller
   Texteingang** for text values).
4. Paste the copied name as the **Bezeichnung**. It must match character for character.
5. Set **"Als Digitaleingang verwenden" to NO** — including for values that only ever hold 0 or 1.
6. Give the LoxBerry user configured on the Miniserver **rights on the new input**. Without them
   the MQTT Gateway reports `HTTP 403 Possibly Access Denied`.

> An underscore that is part of the value name appears as `##_` in the virtual input name, so
> `status_modus_code` becomes `luxtronik2_status_modus##_code`. Always copy the name from the
> Incoming Overview rather than typing it.

### Units and text

Many values arrive **including their unit**: `44.8°C`, `1210 l/h`, `26356h`. The plugin passes
them through unchanged so nothing is distorted.

* For display, a **Virtueller Texteingang** works.
* For logic, prefer the numeric status codes below, or create a **Conversion** in the MQTT
  Gateway to turn known texts into numbers —
  [Conversions erstellen](https://wiki.loxberry.de/konfiguration/widget_help/widget_mqtt/mqtt_gateway/mqtt_schritt_fur_schritt_mqtt_loxone#conversions_erstellen).

## MQTT status codes

Everything the plugin publishes is the controller's own value, verbatim, with one
exception noted at the end. Section and key names are lowercased and transliterated
(`Wärmemenge` → `waermemenge`, spaces → `_`); the MQTT Gateway then renders an
underscore inside a key as `##_`, so `status_modus_code` reaches Loxone as
`luxtronik2_status_modus##_code`.

### `status_modus##_code` — what the pump is doing now

Read verbatim from `ID_WEB_WP_BZ_akt`. **`5` means the pump is standing; any other
value means it is working.**

| Code | Meaning |
|---|---|
| 0 | Heizbetrieb |
| 1 | Warmwasser |
| 2 | Schwimmbad/Solar |
| 3 | EVU-Sperre |
| 4 | Abtauen |
| 5 | **Keine Anforderung** (standing) |
| 6 | Heizen Ext. Energiequelle |
| 7 | Kühlbetrieb |

This enum has 8 values, while the controller's display draws on a larger vocabulary
(`Pumpenvorlauf`, `Schaltspielzeit`, `Sperrzeit`, `Netzeinschaltverzögerung`,
`Estrich Programm`, `Thermische Desinfektion`, `Durchflussüberwachung`, `ZWE 1 aktiv`
and others). Those states have **no** code here — during them the pump reports the
mode it is heading for. In particular, during `Pumpenvorlauf` the code already reads
`1` or `0` while the compressor is still off.

### `status_abschaltung##_code` — why it last stopped

Read verbatim from `ID_WEB_Switchoff_file_Nr4`. While the pump runs this refers to a
past event.

| Code | Meaning | | Code | Meaning |
|---|---|---|---|---|
| 0 | WP-Fehler | | 13 | Überhitzungs-Pause |
| 1 | Anlagenfehler | | 14 | Inverter-Pause |
| 2 | Betriebsart ZWE | | 15 | Enthitzer-Pause |
| 3 | EVU-Sperre | | 16 | Betriebsart Umschaltung |
| 5 | Luftabtauen | | 17 | Andere Abschaltung |
| 6 | Max. Einsatztemperatur | | 18 | Min. Durchfluss Kühlung |
| 7 | Min. Einsatztemperatur | | 19 | PV max |
| 8 | Untere Einsatzgrenze | | 20 | Heissgas-Pause |
| 9 | **Keine Anforderung** | | 21 | Überhitzung Heissgas |
| 10 | Externe Energiequelle | | 23 | Min. WQ-Austritt Kühlung |
| 11 | Durchfluss | | 24 | LPC |
| 12 | Niederdruck-Pause | | 25 | Neustart |

Firmwares report codes beyond this table — a V3.90.3 controller uses **26** for
`TR Erh max`. Treat unknown codes as opaque; the controller's own wording for the same
event is published verbatim as `abschaltungen_0_name`.

### `status_steht##_seit` / `status_steht##_seit##_sekunden`

**The one derived value.** Present only while `status_modus##_code` is `5`. The
controller publishes *when* it last stopped, not *how long ago*, so this is
`now − abschaltungen_0_uhrzeit` — the same arithmetic the controller uses for its own
display timer.

While the pump runs, the controller's own counter is published verbatim as
`ablaufzeiten_wp##_seit`. Note that counter only advances while running and holds a
stale value in between, so use it only when `status_modus##_code != 5`.

### Text

There is deliberately no composed status text. The controller's own words are already
published verbatim — `anlagenstatus_betriebszustand` (`WW`, `Heizen`) and
`abschaltungen_0_name` (`keine Anf.`, `TR Erh max`) — and any sentence the plugin built
from the 8-value enum would read `Warmwasser` while the display said `Pumpenvorlauf`.

### Missing values

An empty reading from the controller is published as `-` (never `null`). This is the
plugin's marker, not the controller's; it means the controller sent an empty value, not
that the value is zero.

### Duplicate names

Some firmware reports two values with the **same name** inside one section — `HD` appears both
as a switch state (`Aus`) and as a pressure (`15.30 bar`), `HUP` as on/off and as a modulation
percentage. So that nothing is lost, the last occurrence keeps the plain name and earlier ones
get a `_1`, `_2`, … suffix:

    luxtronik2_eingaenge_hd        15.30 bar
    luxtronik2_eingaenge_hd##_1    Aus

### Which values exist

That depends on firmware and model — the **Incoming Overview** is authoritative, since it shows
exactly what your own system delivers. Note the MQTT Gateway only transmits **changes**.

## Troubleshooting

* **Test connection** on the configuration page shows immediately whether the controller is
  reachable, and otherwise prints the concrete error.
* The plugin writes a log (*Fetch*), viewable through the plugin management widget or LoxBerry's
  logfile viewer. The log level can be set there.
* If the plugin logs cleanly but nothing arrives at the Miniserver, it is almost always the
  Loxone side: a mistyped virtual input designation, or missing rights for the LoxBerry user
  (`HTTP 403` in the Incoming Overview).
* If the heat pump is unreachable the plugin publishes **nothing** and logs the error. The last
  value stays on the broker, so the timestamps in the Incoming Overview tell you whether the
  data is still current.

## Development notes

### PHP 7.4

This plugin is developed for PHP 7.4, which is still what LoxBerry runs: even
LoxBerry 4.0 (Debian 13) sets `update-alternatives --set php /usr/bin/php7.4` and
only co-installs PHP 8.4 for testing. So there is no migration pressure yet.

The dependency itself is *not* the blocker. The pinned **phrity/websocket 1.7.3**
declares `"php": "^7.4 | ^8.0"` and runs fine on PHP 8 — the 1.x → 2.x split is an
**API** change, not a PHP version gate. In 2.x the constructor options array is
replaced by setters (`addHeader()`, `setTimeout()`), `receive()` returns a
`Message` object instead of a string, `close()` reconnects instead of just
closing, and the exceptions move into the `WebSocket\Exception\` namespace.
**bin/fetch_heat_pump_data/src/LuxController.php** carries a comment at each
affected line describing the 2.x equivalent.

## TODO
* Right now the plugin only fetches data. Writing is possible in principle — the
  WebSocket protocol has `SET;set_<id>;<raw>` followed by `SAVE;1`, and the
  configured password only starts to matter there (a read-only login is accepted
  with any password). It has deliberately not been built: item ids are valid only
  for the current connection, so a write means navigating `Einstellungen` live, and
  a mistake changes how the heating actually runs rather than just publishing a
  wrong number.
* There is no page for this plugin on the LoxBerry wiki
  (`wiki.loxberry.de/plugins/luxtronik2/start` does not exist). Until there is, this
  README is the only documentation.
