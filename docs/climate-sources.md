# Quellen für historische Klimavergleiche

Recherche und PHP-Prüfung: 6. September 2026. Anwendungsfall: langjährige
Vergleichswerte für Kalendertage außerhalb des eigenen Messzeitraums, weltweit.

| Quelle | Eignung | PHP auf Shared Hosting |
|---|---|---|
| Open-Meteo / ERA5 | Weltweite Tageswerte ab 1940 nach Koordinaten; modellgestützte Reanalyse. Festes ERA5-Modell für lange Vergleiche. | HTTPS + JSON, kleine Jahresabschnitte; hier als optionale Erweiterung umgesetzt. |
| DWD CDC | Gemessene deutsche Stationsreihen, Stationswahl und Metadaten erforderlich. | HTTPS-ZIP mit Semikolon-CSV; `ZipArchive::getStream()` ermöglicht zeilenweises Lesen. Geeignet für späteren Stationsadapter. |
| DWD 1991–2020 | Fertige Monats-/Jahresmittel und Klimaindizes für Stationen in Deutschland. | Kleine Textdateien; kein Ersatz für unabhängige Mittel jedes einzelnen Kalendertags. |
| Meteostat JSON API | Internationale Stations-/Punktdaten; Datenbasis und modellgefüllte Lücken beachten. | JSON über RapidAPI mit API-Key und Tarifkontingent. |
| Meteostat Bulk | Aktuell dokumentierter täglicher Export als jährliches Parquet, Beta, mit Modellergänzungen. | Zusätzlicher Parquet-Leser und große Jahresbestände; ungünstiger als kleine JSON-Abfragen für dieses Projekt. |
| WMO / NOAA CLINO | Offizielle Stationsnormalwerte aus vielen Ländern, Schwerpunkt Monats-/Jahresstatistiken. | Dateien sind abrufbar; räumliche Abdeckung und Aufbereitung passen weniger direkt zur gewünschten Tageskurve. |

Quellen: [Open-Meteo-Archiv](https://open-meteo.com/en/docs/historical-weather-api),
[DWD-Tageswerte](https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/),
[DWD-Normalwerte](https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/multi_annual/mean_91-20/),
[Meteostat JSON](https://dev.meteostat.net/api),
[Meteostat Bulk](https://dev.meteostat.net/data/bulk/daily),
[WMO bei NOAA](https://www.ncei.noaa.gov/products/wmo-climate-normals).

DWD trennt vollständig qualitätsgeprüfte historische Daten und vorläufige
aktuelle Daten. Stationen können verlegt worden sein; Dateien enthalten dafür
Metadaten. Ein nachbarlicher Stationsvergleich muss Standort, Höhe, Lücken und
Bezugszeiten berücksichtigen. Der echte lokale PHP-Test konnte den DWD-Index
abrufen; beispielsweise hatte das historische ZIP der Station 03379 für
1954–2025 rund 563 KB. Ein kompletter DWD-Import wurde nicht implementiert.
[Datensatzbeschreibung](https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/BESCHREIBUNG_obsgermany-climate-daily-kl_de.pdf),
[ZIP-Index](https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/historical/),
[PHP-ZIP-Streams](https://www.php.net/manual/en/ziparchive.getstream.php).

DWD CDC nennt CC BY 4.0. Open-Meteo veröffentlicht Daten ebenfalls unter CC BY 4.0,
beschränkt seinen kostenlosen API-Zugang aber auf nicht kommerzielle Nutzung.
Die aktuelle Meteostat-Rechtsseite nennt CC BY-NC 4.0; ältere Angaben zu CC BY 4.0
sollten nicht ungeprüft übernommen werden.
[DWD-Lizenz](https://opendata.dwd.de/climate_environment/CDC/Nutzungsbedingungen_German.pdf),
[Open-Meteo-Bedingungen](https://open-meteo.com/en/terms),
[Meteostat-Rechtsseite](https://meteostat.net/en/legal).

Entscheidung: Start mit Open-Meteo/ERA5 als eigenständigem Paket. DWD bleibt eine
sinnvolle spätere Quelle für regionale Stationsvergleiche. Der Core erhält
ausschließlich allgemeine Erweiterungsregistrierung, Theme-Tag-Aufruf und
zeitbegrenzte Worker-Anbindung. [Einrichtung und Tags](https://github.com/weewx-php/extension-climate).

Die Erweiterung hängt sich am vorhandenen Tick ein und benötigt keinen eigenen
Cronjob. Damit gelten die [allgemeinen Voraussetzungen der Tick-Ausführung](extensions.md#voraussetzung-des-bestehenden-http-ticks):
Bei HTTP-Ticks müssen die eingereihten Hintergrundprozesse auf dem Host tatsächlich
starten können. Der Datenimport bringt keinen zusätzlichen Scheduler mit.
