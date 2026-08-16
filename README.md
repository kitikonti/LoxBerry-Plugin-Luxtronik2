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

## TODO
* Right now the plugin only fetches data. Maybe sending data is also possible —
  note that the controller accepts *any* password for a read-only login, so the
  configured password only starts to matter once `SET`/`SAVE` are used.
* Update the composer integration and how composer packages are installed. Right now I only use composer packages in the /bin folder. I assume there is a Loxberry way to use composer packages, but have not found any documentation. To reach the heat pump from the settings page, for example, it would be required to use the same packages and classes in the /webfrontend folder.