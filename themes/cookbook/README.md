# Cookbook-Beispiele

Vollständige Anleitung: [Theme-Cookbook](../../docs/theme-cookbook.md).

`data.php` definiert den vorbereiteten Bedarf. `feeds.php` veröffentlicht
ausdrücklich die drei öffentlichen Beispiel-Feeds `sidebar`, `live` und `charts`.
Einbindung erfolgt über eine lokale `public-feeds.php` neben der Stationskonfiguration.
Die Seite `public/cookbook.php` zeigt vier Apache-ECharts-Diagramme, eine
Datentabelle und zwei unabhängig einbettbare Widgets.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
```

Bei einem großen Erstaufbau weitere Workerläufe zulassen. Die Seite startet
keine Berechnungen. Live-Werte verlangen einen vorhandenen LOOP-Eingang;
historische Vergleiche ausreichend vollständige Vorjahre.
