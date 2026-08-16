# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A LoxBerry plugin (`luxtronik2`) that polls a Luxtronik 2 heat pump controller over its
WebSocket protocol and publishes the readings to the LoxBerry MQTT broker under the
`luxtronik2` topic, from where the MQTT Gateway relays them to a Loxone Miniserver.
See <https://wiki.loxberry.de/plugins/luxtronik2/start>.

The repo *is* the plugin archive — a LoxBerry plugin is just a ZIP with a fixed directory
layout, and installation scatters these directories across the LoxBerry tree:

| Repo path | Installed to | PHP global |
|---|---|---|
| `bin/` | `$LBPBIN/luxtronik2` (`/opt/loxberry/bin/plugins/…`) | `$lbpbindir` |
| `config/` | `$LBPCONFIG/luxtronik2` | `$lbpconfigdir` |
| `webfrontend/htmlauth/` | `$LBPHTMLAUTH/luxtronik2` (password-protected CGI) | `$lbphtmlauthdir` |
| `templates/lang/` | `$LBPTEMPL/luxtronik2` | `$lbptemplatedir` |
| `cron/crontab` | `$LBHOMEDIR/system/cron/cron.d/luxtronik2` | — |
| `icons/` | system icon dir (`icon.svg` wins over the PNGs on LB ≥ 4.0) | — |

There is no build step and no test suite. Reference for everything below:
[Plugin für den LoxBerry entwickeln](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start).

## PHP 7.4 constraint (important)

LoxBerry runs PHP 7.4 — even LoxBerry 4.0 (Debian 13, 2026) keeps
`update-alternatives --set php /usr/bin/php7.4` and only co-installs PHP 8.4 for testing. Write
7.4-compatible code; don't add PHP 8 syntax.

The pin is on the *library*, not the language: `phrity/websocket` is held at `^1.0` (lock:
**1.7.3**) because 2.x changes the `Client` API — options array → setters, `receive()` returns a
`Message` instead of a string, `close()` reconnects instead of closing, exceptions move to
`WebSocket\Exception\`. Note 1.7.3 itself declares `"php": "^7.4 | ^8.0"`, so the dependency is
*not* what would block a PHP 8 move. `LuxController.php` carries the 2.x equivalent as a comment
at each affected line.

## Local development

DDEV provides a PHP 7.4 container (`.ddev/config.yaml`, project name `lux`):

```bash
ddev start
ddev composer install -d bin/fetch_heat_pump_data   # or: ddev exec php bin/composer.phar install --working-dir=bin/fetch_heat_pump_data
```

`bin/composer.phar` is committed because LoxBerry itself invokes it from `postinstall.sh`;
`bin/fetch_heat_pump_data/vendor/` is gitignored.

### Testing parsing changes without a heat pump

There is no test suite, but `LuxController` is pure parsing and can be exercised offline. Real
protocol captures live at
[tombrk/luxtronik2-exporter/proto](https://github.com/tombrk/luxtronik2-exporter/tree/master/proto)
(`login.xml` = the `<Navigation>` reply, `get.xml` = the `<Content>` reply). Stub
`WebSocket\Client` with a class that replays those frames, run it under PHP 7.4
(`docker run --rm -v "$PWD":/w -w /w php:7.4-cli php …` — the host's PHP has no simplexml), and
diff `json_encode()` of the result against the previous version's. That is how the single-GET
refactor was shown to be payload-identical. Caveat: tombrk's `login.xml` has only one top-level
`<item>`, which simplexml collapses — real controllers report several, so synthesise a
multi-node Navigation when testing the id lookup.

### Running for real

Neither entry point runs outside a LoxBerry host — both `require` the LoxBerry PHP SDK
(`loxberry_io.php`, `loxberry_log.php`, `loxberry_web.php`, `Config/Lite.php`,
`phpMQTT/phpMQTT.php`, all on LoxBerry's include path) and rely on the `$lbp*dir` globals.
End-to-end testing means installing the plugin on a LoxBerry box and running
`php $lbpbindir/fetch_heat_pump_data/fetch.php`. Its log lands in `$lbplogdir/fetch.log` —
that directory is a RAM disk wiped on every boot, which is why `LBLog::newLog()` must create
the file rather than the plugin shipping one. `CUSTOM_LOGLEVELS=true` in `plugin.cfg` means
the user picks a loglevel in the Plugin Management widget.

Never hardcode `/opt/loxberry` — the installer warns the user when it finds that string in any
shipped text file. Use the `$LBP*` environment variables (shell), the `$lbp*dir` globals (PHP),
or `REPLACELBHOMEDIR`-style tags, which the installer substitutes in *all* shipped text files.

## Architecture

Two entry points with no shared code (see the README TODO — composer is wired up only under
`bin/`, which is why the web frontend can't reuse `LuxController` to validate credentials):

- **`bin/fetch_heat_pump_data/fetch.php`** — the cron-driven data path. Reads IP/port/password
  from `$lbpconfigdir/pluginconfig.cfg`, asks `LuxController` for a data array, and publishes it
  as a single retained JSON payload to topic `luxtronik2`, using broker host/port/credentials
  from `mqtt_connectiondetails()` rather than any plugin-local broker config.
- **`webfrontend/htmlauth/index.php`** — the settings page, and the only writer of the plugin's
  cronjob (see below).

Both are plain scripts, so **their top-level variables *are* PHP globals — and the LoxBerry SDK
keeps its own state in globals.** Never name a variable `$cfg`: `LBSystem::read_generaljson()`
parses `general.json` into `global $cfg`, and `mqtt_connectiondetails()`
(`loxberry_io.php`) and `LBWeb::lbheader()` (`loxberry_web.php`) read `$cfg->Mqtt->…` and
`$cfg->Base->Theme` back out of it. Assigning `$cfg = new Config_Lite(...)` replaces it and the
broker host silently comes back empty. Both files use `$pluginconfig`. Other SDK globals to
avoid: `$cfgwasread`, `$miniservers`, `$binaries`, `$lbversion`, `$log`.

### Data flow to Loxone

`config/mqtt_subscriptions.cfg` (one line: `luxtronik2/#`) is an *injected subscription* — the
MQTT Gateway reads that file out of every plugin's config dir and subscribes on the plugin's
behalf, no user setup required. The Gateway then flattens the topic tree plus the JSON body into
underscore-joined names (`luxtronik2_temperaturen_vorlauf`), and those are what the Miniserver
sees. Ref: [MQTT am LoxBerry](https://wiki.loxberry.de/entwickler/mqtt/start).

This is why `LuxController::transformKeys()` matters: it rewrites every key recursively
(umlauts transliterated, spaces → `_`, `.` and `´` dropped, lowercased), so `Wärmemenge` and
`Betriebsstunden` become `waermemenge` / `betriebsstunden`. **Changing `replaceCharacters()`
renames Miniserver inputs in every existing installation** — treat it as a breaking change.

### LuxController (`bin/fetch_heat_pump_data/src/LuxController.php`)

Namespace `Luxtronic2` — note the spelling (`c`, not `k`) in the namespace, the PSR-4 mapping and
the composer package name, while the plugin name, MQTT topic and repo use `luxtronik2`.

Protocol flow in `getData()`: open `ws://ip:port` with subprotocol header `Lux_WS`, send
`LOGIN;<password>`, receive the `<Navigation>` tree, find the id of the `Informationen` node,
then issue **one** `GET;<id>` — that returns the whole subtree with every section's values in a
single `<Content>` document. Don't reintroduce a GET per section; that was the old shape and
cost ~11 round-trips per poll against a controller whose web server hangs under sustained load.

Item ids are heap pointers valid **only for the current connection** — never cache them across
runs. Always LOGIN → parse Navigation → GET.

Failures surface as `LuxConnectionException` (transient: pump off, wrong address, timeout) or
`LuxProtocolException` (sticky: firmware/language changed the section names). `fetch.php` maps
those to log severity and exit codes and, critically, **publishes nothing on failure** so the
last good retained message survives rather than being overwritten with `null`. Non-fatal
oddities go to `getWarnings()` instead of throwing, keeping `LuxController` free of any LoxBerry
dependency.

**Duplicate child names are real.** Firmwares report two children with the same name in one
section — `HD` as both a digital input (`Aus`) and a pressure (`9.45 bar`), `HUP` as on/off and
as a modulation `%`, `Wärmepumpen Typ` twice. Confirmed on two independent firmwares, including
the FW 3.82.4 reference capture. The last occurrence keeps the bare key so existing Miniserver
inputs never move; earlier ones get `_1`, `_2`, … suffixes. Don't "simplify" that back into a
plain assignment — it silently drops values.

What gets exported is keyed off the German section names the controller reports:
`simpleInformationItemNames` (flat name→value maps), `listInformationItemNames` (error/shutdown
logs, mapped to `{name, uhrzeit}` rows), plus special cases for `GLT` (single item) and
`Energiemonitor` (recursed into). **These constants are the feature surface** — adding a data
point to the payload is usually just adding its German label to the right array. Missing/empty
values (which parse as arrays) are emitted as `-`, never null.

A `-` in the payload is **not** a parsing gap — don't "fix" it. Verified on FW V3.90.3 by
dumping the raw `<Content>`: the affected items carry either an empty `<value/>` or no `<value>`
element at all, and none of them have child items being silently dropped. Which items are empty
is transient — `Betriebszustand` is empty while the compressor is off, and `Inverter` / `WP IO` /
`HZ IO` / `Bedienteil` were empty in one snapshot and populated in the next.

### The controller's display text is NOT obtainable

Do not try to publish "Wärmepumpe steht / seit hh:mm:ss / Keine Anforderung" again without
reading this. Both protocols were checked on FW V3.90.3 and neither carries it:

- **WebSocket (8214)**: the complete navigation tree is `Informationen`, `Einstellungen`,
  `Zeitschaltprogramm`, `Zugang: Benutzer`, `Fernsteuerung` — no status text anywhere. Its only
  operating-state field is `Anlagenstatus/Betriebszustand`, which is empty whenever there is no
  demand. hansmi/wp2reg-luxws reads that same single field.
- **Binary protocol (8889, request 3004)**: `ID_WEB_HauptMenuStatus_Zeile1/2/3/Zeit` are at
  indices 117–120 and read **0, 0, 0, 0** on this firmware. The array is definitely aligned —
  100–104 match the error codes, 105 the error count, 106–110 the shutdown reasons and 111–115
  the shutdown timestamps, all verified against the published payload. The fields are simply not
  populated. They were observed non-zero exactly once and zero every time since; do not trust a
  single reading.

The displayed timer is **computed, not stored**: it is `now - Switchoff_file_Time4` (index 115,
the most recent shutdown). Verified — 1786892140 + 12762 s landed exactly on the wall clock when
the display read `seit 03:32:42`.

Everything needed to compose the text is already published from the WebSocket:
`abschaltungen_0_name` is the shutdown reason (`keine Anf.`), `abschaltungen_0_uhrzeit` is when,
and `ausgaenge_verdichter` says whether it is running. Composing it is a judgement call for the
maintainer — the plugin would be inventing a field the controller never sent — so it belongs in
Loxone logic unless someone decides otherwise.

### LuxStatus — the one derived value

`LuxStatus::compose()` publishes exactly one thing: **how long the pump has been standing**,
as `status_steht_seit` / `_sekunden`, and only while `Modus Code` is 5. The controller publishes
*when* it last stopped, not *how long ago*, and parsing `16.08.26 16:55:40` in Loxone Config is
painful — that is the entire justification. The arithmetic is the controller's own, verified to
the second against a real display.

It requires the mode code rather than guessing from the compressor: during the controller's
*Pumpenvorlauf* phase the compressor is still off, so a heuristic calls a starting pump
"standing".

**Do not reintroduce composed status text.** It was tried and removed: the mode enum has 8 values
where the display draws on 16 (`Pumpenvorlauf`, `Schaltspielzeit`, `Sperrzeit`,
`Netzeinschaltverzögerung`, `Thermische Desinfektion`, `Durchflussüberwachung`, `ZWE 1 aktiv`…),
so any sentence built from it reads `Warmwasser` while the display says `Pumpenvorlauf`. It also
duplicated values already published verbatim — `status_seit` was identical to
`ablaufzeiten.wp_seit`, `status_grund` to `abschaltungen[0].name`. Labels for the codes live in
README.md, for users, not in the payload.

### LuxCalculations — numeric state codes (binary protocol)

German text is awkward to switch on in Loxone Config, so two enum codes are read from the binary
protocol (port 8889, request 3004): **index 80** `WP_BZ_akt` (current operation mode) and
**index 110** `Switchoff_file_Nr4` (most recent shutdown reason). Published as code + label.

Codes only — no labels in the payload. The controller's own text is already published verbatim
(`anlagenstatus.betriebszustand`, `abschaltungen[0].name`); a label here would be our invention.
Meanings are documented in README.md. Index 80 is also the authoritative running/standing signal
passed into `LuxStatus::compose()`.
It reports an active mode during the *Pumpenvorlauf* phase, where the compressor is still off and
`Betriebszustand` alone cannot tell "standing" from "about to start" — the controller shows a
third state there, `Wärmepumpe kommt / in mm:ss`, whose timer counts **down**.

**Verified by watching it change**, not by one reading: 80 went `5` (Keine Anforderung, pump
standing) → `1` (Warmwasser) when hot water was forced, while `Betriebszustand` went `-` → `WW`.
That is the standard this file's earlier `HauptMenuStatus` mistake failed — a field frozen at a
plausible value is indistinguishable from a live one until you see it move. **Do not add an index
here without observing a transition.**

`Warmwasser` and `Keine Anforderung` are confirmed verbatim against the display. Codes outside
the table degrade to `Code n`: this controller reports shutdown reason **26**, which
python-luxtronik's table does not list at all — the same robustness argument that makes text
pass-through right for `status_grund`.

The elapsed timer comes from a different source in each state, and mixing them up is easy:
`abschaltungen[0].uhrzeit` while standing, `ablaufzeiten.wp_seit` while running. **`wp_seit`
only advances while the pump runs** and holds a stale value in between — it read `00:00:09`
across two idle samples three hours apart, which briefly looked like a dead field. Read it in
the running branch only. Index 73 is compressor *standstill*, not runtime — not the same thing.

Note the key naming: `status_grund` is the CURRENT reason ("Warmwasser" while running), while
`status_abschaltung_code`/`_text` are the last SHUTDOWN reason, which is a past event while the
pump runs. They were briefly both called "Grund", which implied one was the numeric form of the
other.

### Cronjob handling

`cron/crontab` must exist in the archive — its *content* is irrelevant, but shipping it is the
precondition for the whole mechanism, so don't delete it as dead weight. It is installed to
`$LBHOMEDIR/system/cron/cron.d/luxtronik2`, which the plugin may read but, for security, may not
write. `update_crontab()` in `index.php` therefore writes a temp file and calls
`sudo $lbhomedir/sbin/installcrontab.sh luxtronik2 <tmpfile>`; this is a standard LoxBerry sbin
routine, so no plugin `sudoers/` file is needed. Syntax is `/etc/cron.d`-style with a user field,
and the user must be `loxberry` (installcrontab rewrites it to `loxberry` regardless). The
installed crontab survives plugin updates and is removed on uninstall. Allowed intervals live in
`$croncycle_options`; adding one needs a matching `CRONCYCLE.*` key in both language files.
Ref: [Eigene Cronjobs im Plugin-Code pflegen](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/eigene_cronjobs_im_plugin_code_pflegen).

## Install / upgrade hooks

Order enforced by the installer (upgrade-only steps marked ∆):
`plugin.cfg` → `preroot.sh` → ∆`preupgrade.sh` → ∆**delete of the old installation** → file
copying → `postinstall.sh` → ∆`postupgrade.sh` → `postroot.sh`. Hooks run as user `loxberry`
and receive `<tempfolder> <name> <folder> <version> <basefolder>`; exit 1 warns, exit 2 aborts
the installation.

Because the old installation is wiped mid-upgrade, `preupgrade.sh`/`postupgrade.sh` copy
`config/plugins/luxtronik2/` out to `/tmp` and back — that is what preserves user settings.
`postinstall.sh` runs `composer install`, and since it runs on upgrades too, dependencies are
always reinstalled after the wipe.

Only the `LBP*`/`LBS*` variables (`LBPBIN`, `LBPCONFIG`, …) come from `/etc/environment`; the
short `PBIN`/`PCONFIG` forms are local variables each script must derive itself
(`PBIN=$LBPBIN/$PDIR`). Both hook scripts now do — `postupgrade.sh` previously used `$PBIN`
without deriving it, so its composer line silently expanded to `php /composer.phar`.

## Releasing

There are **two independent channels**, and LoxBerry polls both over raw.githubusercontent from
`main`. The user picks the channel in the Plugin Management widget:

- `release.cfg` → the stable channel, everyone with autoupdate on
- `prerelease.cfg` → only users who opted into prereleases

Each holds `VERSION` plus `ARCHIVEURL`/`INFOURL` pointing at the `v<version>` GitHub tag;
`plugin.cfg` → `[PLUGIN] VERSION` is what an installed copy reports about itself, so it always
carries the newest shipped version regardless of channel.

To ship: bump `plugin.cfg` + the target channel's cfg, commit, push `main`, then
`git tag -a vX.Y.Z && git push origin vX.Y.Z` and `gh release create` (add `--prerelease` for
the prerelease channel). The tag must exist or the `ARCHIVEURL` 404s — GitHub generates the zip
from the tag. Version strings must parse as valid LoxBerry versions or autoupdate skips the
plugin. Commit style: `Update to version X.Y.Z`.

Risky changes go to the prerelease channel first: bump `prerelease.cfg` only and leave
`release.cfg` behind. Promoting later is a one-line change to `release.cfg` (`VERSION` +
`ARCHIVEURL`/`INFOURL`) and dropping `--prerelease` from the GitHub release — no new tag.


`[AUTHOR] NAME`/`EMAIL` and `[PLUGIN] NAME`/`FOLDER` identify the plugin for updates — changing
any of them makes LoxBerry treat it as a different plugin and install a second copy alongside.

## Translations

`templates/lang/language_de.ini` and `language_en.ini`, read via `LBSystem::readlanguage()` into
`$L`; English is the fallback language. Every new UI string needs a key in both files.
