# Demo-Theme

Ein responsives PHP-Theme mit Messwerten, Temperaturverlauf (24 Stunden oder
7 Tage), Niederschlag, Sonnenzeiten, Monatsvergleich und Rekorden. PHP, CSS,
SVG und kleines JSON-Polling ohne zusätzliche Bibliotheken.

## Starten

Voraussetzung ist eine konfigurierte, bereits vorhandene Archivdatenbank.
`frontend.php` liest `weewx-php.conf` im Projektverzeichnis oder den Pfad aus
`WEEWX_PHP_CONF`. Das Theme verwendet das erste konfigurierte Archiv.

Im Projektverzeichnis ausführen:

```sh
php bin/weewx-php analytics sync demo themes/demo/data.php
php bin/weewx-php analytics run
php -S 127.0.0.1:8087 -t public
```

Vorschau: <http://127.0.0.1:8087/>. Auf dem Webhost dient `public/` als
Document Root; der PHP-Entwicklungsserver ist nur für die lokale Vorschau.

Bei einer anderen Konfigurationsdatei die Umgebungsvariable für CLI und
Webserver setzen, beispielsweise in PowerShell:

```powershell
$env:WEEWX_PHP_CONF = 'D:\Wetter\weewx-php.conf'
```

Der reguläre Tick aktualisiert die registrierten Abfragen. Ohne laufenden
Tick lässt sich die Vorschau mit `analytics run` manuell aktualisieren.
Noch ausstehende Berechnungen erscheinen als Platzhalter, fällige Ergebnisse
mit einem Statushinweis. Größere Archive können mehrere Worker-Läufe benötigen.

## Anpassen

| Datei | Inhalt |
|---|---|
| `data.php` | Ausgabeprofil, Tags, Zeiträume und Aktualisierungstakte |
| `template.php` | HTML und SVG-Diagramme |
| `View.php` | Zusammenstellung, Datumsangaben und Diagrammkoordinaten |
| `../../public/data.php` | Fest definierter JSON-Datensatz aus vorbereiteten Ergebnissen |
| `../../public/assets/demo.js` | Aktualisierung und Live-Anzeige |
| `../../public/assets/demo.css` | Farben, Typografie und responsive Darstellung |
| `../../public/index.php` | Einstieg über `frontend.php` |

Zum Beispiel stehen in `data.php`:

```php
'temperature' => $wx->current('outTemp'),
'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
'rainYear' => $wx->year()->sum('rain')->nightly(),
'sunrise' => $wx->almanac()->sun()->rise()->nightly('00:05'),
```

Beim Laden gleicht das Theme seine Abfragen automatisch ab; alternativ `analytics
sync demo themes/demo/data.php` verwenden. Gemeinsam verwendete Ergebnisse bleiben
erhalten. Alle Wetterdaten kommen über die
[PHP-Tags](../../docs/frontend.md); das Theme führt keine eigenen SQL-Abfragen aus.

Die Ausgabe verwendet °C, km/h, hPa und mm, auch bei einem US-Archiv.
Tageswerte und Verläufe beziehen sich auf die letzte Archivmessung, deren
Datum sichtbar ist. Sonnenzeiten beziehen sich auf den heutigen Kalendertag
am Stationsort. Fehlende Werte erscheinen als „—“, Lücken unterbrechen die
Temperaturlinie. Der Regenverlauf umfasst genau sieben Kalenderdaten einschließlich
des Tages der letzten Archivmessung;
Monats- und Jahressummen umfassen die tatsächlich vorhandenen Messwerte.

Für die Temperatur stehen die Diagrammwerte außerdem in einer aufklappbaren
Tabelle. Alle Bedienelemente funktionieren mit der Tastatur.

Das Ausgabeprofil, die cachebaren Vergleiche und die Grenzen der Live-Anzeige
sind in [Frontend-Rezepte](../../docs/frontend-recipes.md) beschrieben. Bei kurzen
Archiven bleiben Monatsrekorde und Referenzjahre leer, wenn die notwendige
Abdeckung fehlt. Das sind fehlende Vergleichsdaten, kein Regenwert von null.
