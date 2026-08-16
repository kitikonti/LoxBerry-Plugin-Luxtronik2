# LoxBerry-Plugin-Luxtronik2

A LoxBerry Plugin: https://wiki.loxberry.de/plugins/luxtronik2/start

https://github.com/kitikonti/LoxBerry-Plugin-Luxtronik2

## Note when converting from PHP7 to PHP8
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


## TODO
* Right now the plugin only fetches data. Writing is possible in principle — the
  WebSocket protocol has `SET;set_<id>;<raw>` followed by `SAVE;1`, and the
  configured password only starts to matter there (a read-only login is accepted
  with any password). It has deliberately not been built: item ids are valid only
  for the current connection, so a write means navigating `Einstellungen` live, and
  a mistake changes how the heating actually runs rather than just publishing a
  wrong number.
