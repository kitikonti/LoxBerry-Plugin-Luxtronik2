# LoxBerry-Plugin-Luxtronik2

A LoxBerry Plugin: https://wiki.loxberry.de/plugins/luxtronik2/start

https://github.com/kitikonti/LoxBerry-Plugin-Luxtronik2

## Note when converting from PHP7 to PHP8
This plugin is currently developed for PHP7.4. As soon as Loxberry is converted to PHP8, some of the code or the packages used must be adapted. Specifically, this affects the package **phrity/websocket** which supports PHP7.x up to version 1.x and only supports PHP8.x from version 2.x onwards. The **Websocket\Client** Class provided by this package is used in **bin/fetch_heat_pump_data/src/LuxController.php**. This file already contains comments on what needs to be changed when switching to PHP8.x.

## TODO
* Validate credentials on save.
* Right now the plugin only fetches data. Maybe sending date is also possible.
* Update the composer integration and how composer packages are installed. Right now I only use composer packages in the /bin folder. I assume there is a Loxberry way to use composer packages, but have not found any documentation. To validate the credentials for example it would be required to use the same packages and classes in the /webfrontend folder.