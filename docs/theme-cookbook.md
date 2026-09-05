# Theme-Cookbook: PHP-Tags, Apache ECharts, API und WordPress

Dieses Cookbook führt vom ersten PHP-Theme bis zum eingebetteten Live-Widget.
Die Beispiele verwenden die vorhandene Frontend-Schicht. Sie enthält die
WeeWX-/xaggs-Aggregate, astronomische Abfragen, cachebare Vergleiche und
explizite Live-Werte. Apache ECharts zeichnet die fertig aufbereiteten Reihen.

**Lauffähige Beispiele:** `public/cookbook.php`,
[`themes/cookbook/data.php`](../themes/cookbook/data.php),
[`chart-recipes.js`](../public/assets/chart-recipes.js) und
das [WordPress-Plugin](../examples/wordpress/weewx-weather/README.md).
Die Galerie verwendet echte Daten der konfigurierten Station.
Ohne veröffentlichte Feeds bleibt die API geschlossen; die Einrichtung folgt unten.

## Inhalt

1. [Arbeitsweise und Dateistruktur](#1-arbeitsweise-und-dateistruktur)
2. [Ein erstes PHP-Theme](#2-ein-erstes-php-theme)
3. [Ausgabe und Einheiten](#3-ausgabe-und-einheiten)
4. [Zeiträume und Zeitzonen](#4-zeiträume-und-zeitzonen)
5. [Messwerte, Reihen und Datenqualität](#5-messwerte-reihen-und-datenqualität)
6. [Rekorde und Vergleiche](#6-rekorde-und-vergleiche)
7. [Astronomie und Theme-Einstellungen](#7-astronomie-und-theme-einstellungen)
8. [Cache, Aktivierung und Betrieb](#8-cache-aktivierung-und-betrieb)
9. [Apache ECharts](#9-apache-echarts)
10. [Öffentliche API](#10-öffentliche-api)
11. [Einbettung auf beliebigen Websites](#11-einbettung-auf-beliebigen-websites)
12. [WordPress-Sidebar-Widgets](#12-wordpress-sidebar-widgets)
13. [Prüfen und Fehler finden](#13-prüfen-und-fehler-finden)

## 1. Arbeitsweise und Dateistruktur

Ein Theme beschreibt **welche Daten es benötigt**. Ein `Query` ist zunächst nur
ein Rezept. Erst `get()`, `value()`, `series()`, `report()` oder die direkte
Textausgabe liest das Ergebnis. Der Worker berechnet teure Rezepte im Hintergrund.
Die Datenbank und ihre Spaltenstruktur bleiben hinter `Weather` verborgen.

```text
frontend.php                    Einstieg: liefert Weather
themes/mein-theme/
  data.php                      benannte Rezepte, keine HTML-Ausgabe
  template.php                  HTML und Darstellung
  settings.json                 optional: Einstellungen für die Verwaltung
  locales/de.json               optional: Texte für die Verwaltung
public/
  mein-theme.php                HTTP-Einstieg
  assets/mein-theme.css          Gestaltung
  assets/mein-theme.js           Interaktion und ECharts
  api/v1.php                    gemeinsamer öffentlicher Datenendpunkt
/etc/weewx-php/
  station.conf                  Stationskonfiguration außerhalb des Webroots
  public-feeds.php               explizit veröffentlichte Datenpakete
```

Der Webserver bekommt ausschließlich `public/` als DocumentRoot. Konfiguration,
SQLite-Dateien, Logs und Theme-PHP liegen außerhalb. Theme-Dateien und
`public-feeds.php` sind ausführbarer, vertrauenswürdiger lokaler Code.
Ein URL-Parameter darf niemals direkt einen Include-Pfad bestimmen.

## 2. Ein erstes PHP-Theme

In `themes/mein-theme/data.php`:

```php
<?php
declare(strict_types=1);

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Weather;

if (!isset($wx) || !$wx instanceof Weather) {
    throw new LogicException('Weather fehlt');
}
$wx = $wx->output(new Output('de', units: [
    'group_temperature' => 'degree_C',
    'group_rain' => 'mm',
    'group_speed' => 'km_per_hour',
]))->reference('archive');

return [
    'temperature' => $wx->current('outTemp'),
    'rainToday' => $wx->day()->sum('rain'),
    'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
];
```

In `public/mein-theme.php`:

```php
<?php
declare(strict_types=1);

/** @var \WeewxPhp\Frontend\Weather $wx */
$wx = (require dirname(__DIR__) . '/frontend.php')->cacheOnly();
try {
    $queries = require dirname(__DIR__) . '/themes/mein-theme/data.php';
    $wx->syncTheme('mein-theme', $queries);
    $data = $wx->dataset($queries)->get();
    require dirname(__DIR__) . '/themes/mein-theme/template.php';
} finally {
    $wx->close();
}
```

In `themes/mein-theme/template.php`:

```php
<!doctype html>
<html lang="de">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wetter</title>
<h1>Wetter</h1>
<dl>
  <dt>Temperatur</dt><dd><?= $data['temperature'] ?></dd>
  <dt>Niederschlag heute</dt><dd><?= $data['rainToday'] ?></dd>
</dl>
</html>
```

`Value` und einzelne `Query`-Ergebnisse sind bei direkter Ausgabe HTML-sicher.
Eigene Texte wie Stationsnamen mit `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')` ausgeben. Reihen und Reports gezielt darstellen;
sie lassen sich nicht sinnvoll als einzelner Textwert ausgeben.

Einrichtung aus dem Projektverzeichnis:

```sh
php bin/weewx-php --config /etc/weewx-php/station.conf analytics preflight themes/mein-theme/data.php
php bin/weewx-php --config /etc/weewx-php/station.conf analytics sync mein-theme themes/mein-theme/data.php
php bin/weewx-php --config /etc/weewx-php/station.conf analytics run
```

Für den Webprozess `WEEWX_PHP_CONF=/etc/weewx-php/station.conf` setzen.
Anschließend `/mein-theme.php` öffnen. Ein leeres Cache-Ergebnis zeigt einen
Platzhalter; bei umfangreicher Vorbereitung können mehrere Workerläufe nötig sein.
Für ein vollständiges PHP-Theme mit Fehlerbehandlung siehe [Demo](../themes/demo/README.md).

Für den Umstieg von Cheetah:

| Cheetah-Ausdruck | PHP-Rezept |
|---|---|
| `$current.outTemp` | `$wx->current('outTemp')` |
| `$day.rain.sum` | `$wx->day()->sum('rain')` |
| `$month.outTemp.max` | `$wx->month()->max('outTemp')` |
| `$year.outTemp.maxtime` | `$wx->year()->aggregate('outTemp', 'maxtime')` |
| `$alltime.rain.sum` | `$wx->alltime()->sum('rain')` |

Die Ketten beschreiben dieselbe fachliche Auswahl. In PHP bleibt das Rezept
bis zur Ausgabe unverändert und kann benannt, vorbereitet und wiederverwendet werden.

## 3. Ausgabe und Einheiten

Ein Ausgabeprofil gilt für Einzelwerte, Reihen und Report-Werte:

```php
$wx = $wx->output(new \WeewxPhp\Frontend\Output(
    language: 'de',
    units: [
        'group_temperature' => 'degree_C',
        'group_speed' => 'km_per_hour',
        'group_rain' => 'mm',
        'group_pressure' => 'mbar',
    ],
    decimals: ['group_percent' => 0, 'group_pressure' => 0, 'rain' => 2],
    missing: '—',
    dateFormat: 'd.m.Y H:i',
));

$temperature = $wx->current('outTemp')->value();
echo $temperature;                         // z. B. 21,7 °C, HTML-sicher
$text = $temperature->format();            // Klartext für JSON, Mail usw.
$number = $temperature->raw;               // ungerundete Zahl oder null
$fahrenheit = $temperature->to('degree_F');
$labelFree = $temperature->format(label: false);

$series = $wx->last('24h')->series('outTemp', '15m')->series();
$fahrenheitSeries = $series->to('degree_F');
$labels = $series->formatted();
```

`format()` liefert **keinen** HTML-geschützten Text; `html()` tut das.
Keine formatierten Zahlen wie `"1.013,2"` an ECharts geben. Diagramme rechnen mit
Zahlen; Beschriftungen werden erst an Achse oder Tooltip formatiert.
`null` bedeutet fehlend, `0` ist ein gemessener Wert. In PHP deshalb
`$value->raw !== null`, in JavaScript `value !== null` prüfen, nicht `if (value)`.

Die Umrechnung ist Darstellung und erzeugt keine zusätzliche Cacheberechnung.
Ein ausstehender Wert bleibt bei `->to(...)` ein Platzhalter. Eine bekannte,
inkompatible Einheit wird zurückgewiesen. Temperaturdifferenzen in Reports
werden ohne Celsius-/Fahrenheit-Nullpunktversatz umgerechnet.

Aktuell unterstützte Ausgabesprachen: `de`, `en`. Messwertnamen bleiben intern
stabil (`outTemp`); sichtbare Bezeichnungen gehören in Theme-/Feed-Texte.

## 4. Zeiträume und Zeitzonen

| Gewünschte Bedeutung | Rezept |
|---|---|
| Heutiger Tag nach Uhrzeit | `$wx->today()` |
| Tag des letzten Archivintervalls | `$wx->reference('archive')->day()` |
| Gleitende 24 Stunden bis jetzt | `$wx->reference('clock')->last('24h')` |
| Letzte 24 Stunden mit Archivdaten | `$wx->reference('archive')->last('24h')` |
| Sieben Kalenderdaten einschließlich heute | `$wx->reference('clock')->days(7)` |
| Genau 168 Stunden | `$wx->reference('clock')->last('7d')` |
| Ganzer September 2024 | `$wx->on('2024-09')` |
| Ganzes Jahr 2024 | `$wx->on('2024')` |
| Eigene Grenzen | `$wx->between('2024-09-01', '2024-10-01')` |
| Reproduzierbarer historischer Bezug | `$wx->reference('fixed', '2024-09-15 12:00:00')` |

Kalendergrenzen verwenden die Archiv-Zeitzone. Ein Sommerzeittag kann 23 oder
25 Stunden lang sein. `last('24h')` meint dagegen immer 86.400 Sekunden.
Ein Archivdatensatz um 00:00 beendet das vorige Messintervall und gehört bei
Archivbezug zum vorigen Tag. Bei Uhrzeitbezug beginnt um 00:00 der neue Tag.

Archive verwenden Intervalle `(start, end]`. Eine Regen-Tagesreihe sollte daher
mit **`start` als Kalenderdatum** beschriftet werden. Sonst landet der Regen
vom Montag unter Dienstag. Für Verlaufskurven ist `end` meist sinnvoll.

`current()` bedeutet letzte Archivmessung im Archivkontext. Bei expliziter
Uhrzeit gelten Zeitpunktsuche und `maxDelta`; das ist kein automatischer
LOOP-Livezugriff. `latest()` sucht den letzten gültigen Messwert. `live()`
liest das Live-Journal. Alle drei behalten ihren eigenen Messzeitpunkt.

Ein `fixed`-Bezug begrenzt dynamische Zeiträume; `on()` und `between()` verlangen
ausdrücklich ihre vollständigen Grenzen. Ohne Auswahl gilt der kompatible
`legacy`-Bezug: Wetter am Archiv, Astronomie an der Uhrzeit.

## 5. Messwerte, Reihen und Datenqualität

### Welche Messwerte gibt es?

```php
$columns = $wx->observations();
$sensor = $wx->measurement('extraTemp1');
// stored, derivable, dependencies, missingDependencies, availability,
// unit, group, kind, asOf, age, defaultAggregate, output, aggregates
```

Das ist ein begrenzter Schema-/Verfügbarkeitszugriff für die Entwicklung.
Eine vorhandene Spalte garantiert keine Messwerte. Eine bekannte Formel
garantiert keine vollständigen Eingangsdaten. Die öffentliche API veröffentlicht
diesen Katalog bewusst nicht automatisch: Innenwerte bleiben privat, bis der
Betreiber sie ausdrücklich in einen Feed aufnimmt.

### Das richtige Aggregat

```php
$wx->day()->sum('rain');
$wx->day()->min('outTemp');
$wx->day()->max('outTemp');
$wx->day()->aggregate('outTemp', 'maxtime');
$wx->day()->aggregate('wind', 'vecdir');
$wx->last('24h')->series('rain', 'hour');        // Intervallsummen
$wx->last('24h')->series('outTemp', 'hour');     // zeitgewichtete Mittel
$wx->last('24h')->series('outTemp', 'hour', 'max');
```

Ohne explizites Aggregat wählt `series()` die Messwertsemantik: Regen, ET und
Intervallenergie werden summiert; Zustände zeitgewichtet gemittelt; kumulative
Regenzähler liefern ihren letzten Stand. Für Zählerdifferenzen braucht es eine
Reset-Regel. Windrichtung braucht eine vektorielle Auswertung, kein arithmetisches
Mittel aus Gradangaben. `avg()` behält die dokumentierte WeeWX-Semantik.

Alle weiteren WeeWX-/xaggs-Namen stehen in der [Tag-Referenz](frontend.md).
Die allgemeine Schreibweise ist `aggregate('Messwert', 'Aggregat', threshold, unit)`.
Damit können auch dynamisch gewählte **lokal freigegebene** Aggregate verwendet werden.

### Reihenstruktur und Status

```php
$series = $wx->days(7)->series('rain', 'day')->series();
foreach ($series->points as $point) {
    // start, end: Unix-Sekunden
    // value: Zahl oder null
    // coverage: gemessener Anteil des Intervalls, 0..1 oder null
}
$pairs = $series->pairs(time: 'end', milliseconds: true);
$json = $series->json(time: 'end', milliseconds: true); // nur Koordinatenpaare
```

`json_encode($series)` enthält dagegen das vollständige Objekt mit Einheit,
Status und Abdeckung. Für Fremdseiten den versionierten Feed-Endpunkt verwenden.

| Status | Bedeutung für das Theme |
|---|---|
| `ready` | Berechnung liegt gemäß Aktualisierungsregel vor |
| `pending` | Noch kein fertiges Ergebnis; Platzhalter zeigen |
| `stale` | Bisheriges Ergebnis verfügbar, Aktualisierung fällig; bei Live: Messung zu alt |
| `unavailable` | Quelle oder Messwert derzeit nicht verfügbar |

`ready` sagt nichts darüber aus, ob die Station seit Tagen offline ist.
`asOf` zeigt die zugrunde liegende Messzeit, `computedAt` den Berechnungszeitpunkt.
Beides auseinanderhalten. Live-Felder der API setzen `computedAt` auf `null`,
da sie unmittelbar aus dem Journal gelesen werden.

### Vollständigkeit und mehrere Reihen

```php
$completeDays = $wx->year()->series('rain', 'day')->completed(0.95);
$aligned = $wx->dataset([
    'temperature' => $wx->reference('clock')->days(7)->series('outTemp', 'hour'),
    'humidity' => $wx->reference('clock')->days(7)->series('outHumidity', 'hour'),
])->aligned();
```

`completed(0.95)` verlangt abgeschlossene Intervalle mit mindestens 95 %
Abdeckung. Ein noch laufender Tag ist dadurch ausgeschlossen.
`coverage(0.95)` setzt die Abdeckungsgrenze ohne zusätzlich vollständigen
Kalenderabschluss zu verlangen. Tagesbalken für „heute“ als Teilintervall
kennzeichnen; ein lückenhafter Wert ist keine sichere volle Tagesmenge.

`aligned()` verbindet exakte Intervallgrenzen und erhält fehlende Werte als
`null`. Unterschiedliche überlappende Raster werden zurückgewiesen.
Für mehrere Archive denselben Zeitbezug, dieselbe Auflösung und dieselbe
Zeitzone verwenden. Eine gemeinsame Listenposition ist kein Zeitabgleich.

## 6. Rekorde und Vergleiche

Diese Rezepte gehören in `data.php`. Sie werden einschließlich Rangbildung,
Vergleich und Qualitätsprüfung als vollständiger `Report` gecacht.

```php
return [
    'wettestMonth' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->rank(1)->nightly(),
    'driestMonth' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->rank(1, ascending: true)->nightly(),
    'wettestDay' => $wx->alltime()->series('rain', 'day')
        ->completed(0.95)->rank(1)->nightly(),
    'driestSeptember' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->calendarMonth(9)->rank(1, ascending: true)->nightly(),
    'monthComparison' => $wx->reference('clock')->month()->sum('rain')
        ->compareYears(0.95)->nightly('02:00'),
    'drySpell' => $wx->alltime()->series('rain', 'day')
        ->longestSpell(0, 'le', 'mm')->nightly(),
    'lastRainDay' => $wx->alltime()->series('rain', 'day')
        ->lastEvent(0, 'gt', 'mm')->nightly(),
    'rainP95' => $wx->alltime()->series('rain', 'day')
        ->completed(0.95)->quantile(0.95)->nightly(),
];
```

Der Monatsvergleich vergleicht beispielsweise den 15. September bis 12 Uhr mit
dem gleichen lokalen Zeitpunkt früherer Jahre. Das aktuelle Jahr zählt nicht
zum Referenzmittel. Ein ganzer historischer Monat wäre eine andere Frage.

```php
$report = $queries['monthComparison']->report();
echo $report->value('current');
echo $report->value('mean');
echo $report->value('difference');
echo $report->value('percentOfMean');
$years = $report->referenceYears();
$exclusions = $report->meta['excluded'] ?? [];
$historicalPeriods = $report->periods;
```

Referenzjahre und Ausschlüsse mit dem Vergleich anbieten. Im Report stehen
Gründe für Datenlücken, unvollständige Intervalle und nicht vorhandene
Schaltjahrestermine. Ranglisten enthalten die ausgewählten Perioden und die
Größe der gültigen Grundgesamtheit. Gleichstände werden nach frühestem Beginn
geordnet; der „trockenste Tag“ ist häufig einer von vielen Tagen mit 0 mm.

Trockenperioden verlangen 100 % gemessene Zeitabdeckung. Datenlücken unterbrechen
die Folge. Die Auflösung bestimmt die Aussage: `day` liefert Tage, keine
sekundengenaue letzte Regenzeit. Feinere Auflösung mit begrenztem Zeitraum wählen.

Quantile verwenden R7 auf den gewählten Intervallwerten. Das 95-%-Quantil
von Tagesmengen ist kein Quantil sämtlicher 5-Minuten-Rohmessungen. Fertige
Monatsmediane lassen sich nicht durch Mittelwertbildung korrekt zusammenführen.

## 7. Astronomie und Theme-Einstellungen

Astronomische Rezepte können im selben Manifest stehen:

```php
$sky = $wx->reference('clock')->almanac();
$sunrise = $sky->sun()->rise()->nightly('00:05');
$sunset = $sky->sun()->set()->nightly('00:05');
$moonrise = $sky->moon()->tag('next_rising')->nightly();
$fullMoon = $sky->tag('nextFullMoon')->nightly();
$jupiterAltitude = $sky->body('jupiter')->altitude()->refresh('15m');
$siriusAzimuth = $sky->body('Sirius')->azimuth()->refresh('15m');
```

Stationskoordinaten, Höhe und Zeitzone konfigurieren. Auf-/Untergänge können
je nach Ort und Datum fehlen; Platzhalter zulassen. Ereignisse verändern sich
seltener als momentane Himmelspositionen. Weitere Körper, Sterne, Horizontregeln
und Zeitreihen: [Astronomie-Referenz](frontend.md#astronomie-aus-unseren-vorhandenen-berechnungen).

Theme-Einstellungen sind von Wetterabfragen getrennt:

```php
$theme = \WeewxPhp\Frontend\Theme::configured(
    '/etc/weewx-php/station.conf', id: 'demo', language: 'de'
);
$range = $theme->extras['default_range'] ?? '24h';
```

Die registrierten, typisierten Einstellungen sind in `extras` verfügbar.
Als Beispiel dienen [settings.json](../themes/demo/settings.json) und
[de.json](../themes/demo/locales/de.json). Eigene Eingaben wie ein Bereichsschalter
auf feste Rezepte abbilden:

```php
$range = ($_GET['range'] ?? null) === '7d' ? '7d' : '24h';
$selected = $queries[$range === '7d' ? 'temperature7d' : 'temperature24h'];
```

Beide Varianten im Manifest registrieren. So entstehen durch URL-Variationen
keine unbegrenzt neuen Cacheeinträge.

## 8. Cache, Aktivierung und Betrieb

`analytics.sdb` ist der gemeinsame, wiederaufbaubare Ergebniscache.
Unterschiedliche Themes können dieselbe Berechnung teilen. Geschlossene
Serienblöcke und zusammenführbare Zwischenstände werden wiederverwendet.
Ein Theme braucht keine eigene Cache-Datenbank und keinen eigenen Worker.

| Ergebnis | Beispielregel |
|---|---|
| Letzte Archivwerte, aktuelle Tageswerte | Standard `archive` |
| Häufig benötigte Position/Trend | `refresh('15m')` |
| Monatsvergleich, Langzeitrekorde | `nightly('02:00')` |
| Selten geänderte Übersicht | `refresh('weekly')` oder `refresh('monthly')` |
| Explizit einmal vorbereiteter fester Zeitraum | `refresh('once')` |
| LOOP-Livewerte | unmittelbarer begrenzter Journalzugriff, kein Aggregatjob |

Abgeschlossene Ergebnisse bleiben gültig, solange ihre Quelldaten und
Berechnungsregeln unverändert sind. Nach Korrekturen, Nachimporten oder einer
geänderten Quellrevision kann eine Neuberechnung fachlich erforderlich sein.
„Nie wieder anfassen“ gilt deshalb nicht für nachträglich geänderte Daten.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
php bin/weewx-php --config station.conf analytics status
php bin/weewx-php --config station.conf analytics deactivate cookbook
```

`sync` gleicht den kompletten benannten Bedarf atomar ab. Entfallene exklusive
Registrierungen werden freigegeben; andere Themes und manuell angeheftete
Abfragen behalten ihre Ergebnisse. Nach Änderungen erneut synchronisieren.
Abfragen in `data.php` nur definieren; dort keine Daten schon mit `get()` laden.

Der bestehende Tick führt den Worker nach der Datenverarbeitung aus. Er muss
regelmäßig laufen, auch wenn niemand die Website besucht. `analytics run`
arbeitet mit Laufbudget und ist keine Garantie, den gesamten Erstaufbau in
einem einzigen Aufruf abzuschließen. Die Galerie startet selbst keinen Worker.

`cacheOnly()` verhindert Archivabfragen und Berechnungen beim Rendern;
`Query::prepared()` erzwingt dasselbe für ein einzelnes bestehendes Rezept.
Die öffentliche API nutzt das immer. Cachezugriffe und Registrierungen finden
weiterhin statt; der Modus bedeutet nicht „überhaupt kein SQLite-Zugriff“.

Prioritäten reichen von -10 bis 10. Aktuelle Werte haben Vorrang; große
historische Rezepte erhalten beispielsweise `priority(-5)`. Lange wartende
Arbeit altert nach vorn. Das gemeinsame Seitenbudget begrenzt optionale
Inline-Abfragen; ein Worker baut große Ergebnisse schrittweise auf.

## 9. Apache ECharts

Die Galerie enthält **Apache ECharts 6.1.0** lokal, mit LICENSE, NOTICE und
Prüfsumme in [vendor/echarts](../public/assets/vendor/echarts/README.md).
Für den Start ist weder ein CDN noch ein Node-Build nötig. ECharts übernimmt
Darstellung und Interaktion; die aggregierten Wetterreihen kommen aus PHP.

### Galerie starten

Neben die durch `WEEWX_PHP_CONF` ausgewählte Stationskonfiguration eine
`public-feeds.php` mit folgendem Inhalt legen; Projektpfad anpassen:

```php
<?php
return require '/opt/weewx-php/themes/cookbook/feeds.php';
```

Dieses Beispiel veröffentlicht ausdrücklich drei Feeds für beliebige Origins:
`sidebar`, `live` und `charts`. Enthalten sind nur die in der Datei benannten
Außenwerte und Diagramme. Für eigene Veröffentlichungen Abschnitt 10 verwenden.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
```

Dann `/cookbook.php` öffnen. Die aktuelle lokale Demo liegt unter
`http://127.0.0.1:8087/cookbook.php`. Ihre Quelle enthält nur einige Archivtage,
keine langjährige Vergleichsbasis und keinen aktiven LOOP-Eingang. Leere
Vergleichsjahre und fehlende Live-Werte sind hier echte Zustände.

### Erstes Diagramm: PHP-Serie → ECharts

HTML und CSS:

```html
<link rel="stylesheet" href="assets/charts.css">
<script defer src="assets/vendor/echarts/echarts.min.js"></script>
<script type="module" src="assets/temperature-chart.js"></script>
<div id="temperature" class="chart"></div>
```

```css
.chart { width: 100%; height: 320px; min-width: 0; }
```

`assets/temperature-chart.js`:

```js
import {subscribe} from './feed-client.js';
import {timeFormat} from './chart-recipes.js';

const node = document.querySelector('#temperature');
const chart = echarts.init(node);
const size = new ResizeObserver(() => chart.resize());
size.observe(node);
const stop = subscribe('api/v1.php?feed=charts&fields=temperature24h', feed => {
    if (!feed) return;
    const values = feed.data.temperature24h;
    chart.setOption({
        animation: false,
        tooltip: {trigger: 'axis', renderMode: 'richText'},
        xAxis: {type: 'time', axisLabel: {formatter: timeFormat(feed.timezone)}},
        yAxis: {type: 'value', name: '°C'},
        series: [{id: 'outside', type: 'line', name: 'Außen',
            showSymbol: false, connectNulls: false,
            data: values.points.map(p => [p.end * 1000, p.value])}],
    });
});
// Bei Entfernen des Diagramms, etwa beim SPA-Routenwechsel:
// stop(); size.disconnect(); chart.dispose();
```

**URL-Auflösung:** `subscribe()` löst relative API-URLs gegen die HTML-Seite
auf, nicht gegen die JavaScript-Datei. Das Beispiel passt zu einer Seite direkt
in `public/`. Bei Seiten in Unterverzeichnissen den Pfad entsprechend anpassen.
Absolute HTTPS-URLs sind für externe Einbettungen eindeutig.

ECharts benötigt einen Container mit messbarer Größe. Bei Tabs/Accordions
erst nach Sichtbarwerden initialisieren oder danach `resize()` aufrufen.
Ein `ResizeObserver` reagiert auch auf veränderte Sidebarbreiten, nicht nur
auf Fenstergrößen. [Offizielles Größenkonzept](https://echarts.apache.org/handbook/en/concepts/chart-size/).

### Dataset und zwei Achsen

Die lauffähige Funktion `temperatureHumidity(data, zone)` in
[`chart-recipes.js`](../public/assets/chart-recipes.js) zeigt zwei Datensätze:

```js
const option = {
    dataset: [{
        id: 'outside',
        dimensions: ['time', 'temperature'],
        source: feed.data.temperature24h.points.map(p => [p.end * 1000, p.value]),
    }],
    xAxis: {type: 'time'},
    yAxis: [{type: 'value', name: '°C'}, {type: 'value', name: '%', min: 0, max: 100}],
    series: [{
        id: 'temperature', type: 'line', datasetId: 'outside',
        encode: {x: 'time', y: 'temperature'}, yAxisIndex: 0,
        connectNulls: false,
    }],
};
```

`dataset` hält Zahlen getrennt von Diagrammoptionen; `encode` ordnet Dimensionen
explizit zu. Für Luftfeuchte einen eigenen Datensatz und `yAxisIndex: 1`
ergänzen, wie in der Galerie. Eine gemeinsame y-Achse für °C und Prozent
wäre fachlich falsch. [ECharts Dataset](https://echarts.apache.org/handbook/en/concepts/dataset/).

### Tagesregen als Balken

```js
import {dailyRain} from './chart-recipes.js';
chart.setOption(dailyRain(feed.data.rain7d, feed.timezone));
```

Diese Funktion beschriftet `start` in der Stationszeitzone. Sie erzeugt
Kalenderkategorien statt gleich langer Millisekunden-Balken: Ein Tag bleibt
auch bei Sommerzeitwechsel ein Balken. Fehlende Werte bleiben `null`.
Die Galerie bietet die gemessene Abdeckung zusätzlich in der Datentabelle.

### Kumulierten Regen korrekt zeichnen

```js
import {rainAccumulation} from './chart-recipes.js';
chart.setOption(rainAccumulation(feed.data.rain24h, feed.timezone));
```

Die Funktion summiert die bereits vorbereiteten Intervallmengen und zeichnet
eine Treppenlinie. Das ist kleine Darstellungsarbeit über höchstens einige
hundert Punkte. Beim ersten fehlenden oder unvollständig gemessenen Intervall
endet die gesicherte kumulierte Summe; spätere Punkte bleiben `null`.
Eine Datenlücke als 0 mm zu behandeln würde eine zu niedrige Gesamtsumme erfinden.

### Monatsvergleich und Ranglisten

```js
import {monthlyComparison} from './chart-recipes.js';
chart.setOption(monthlyComparison(feed.data.rainComparison, feed.timezone));
```

Der Report enthält bereits qualifizierte historische Vergleichsperioden.
ECharts muss keine zehn Jahre Rohdaten herunterladen oder Ranglisten bilden.
`report.values.current`, `mean` und `difference` können daneben als Kennzahlen
erscheinen. `report.meta.excluded` erklärt die ausgeschlossenen Referenzen.
Bei fehlenden Referenzjahren eine leere Auswertung kennzeichnen, keine 0 zeichnen.

Für „nasseste zehn Monate“ im Manifest eine Monatsserie mit
`completed(0.95)->rank(10)->nightly()` definieren. Die Balken erhalten
`report.periods.points`; die Kategorien sind Monat **und Jahr** des Beginns.

### Jahreskurven übereinanderlegen

Eine Monats- oder Tagesserie im Worker vorbereiten. In PHP:

```php
$series = $wx->between('2022-01-01', '2026-01-01')
    ->series('outTemp', 'day')->series();
$overlay = $series->overlay('Europe/Berlin');
// Schlüssel: Monat-Tag Uhrzeit; Werte: Jahr => Messwert/null
```

Für ECharts die Schlüssel als `category`-Achse und jedes Jahr als Linie nutzen.
Nach Kalenderdatum zuordnen, nicht nach dem 365. Listenindex. Der 29. Februar
bleibt eine eigene Kategorie. Für stündliche Overlays ist die doppelte lokale
Stunde bei der Zeitumstellung mehrdeutig; die vorhandene Funktion weist das
zurück. Tages- oder Monatsauflösung vermeidet diese Mehrdeutigkeit.

### Aktualisierung, Tooltip und Zugänglichkeit

`feed-client.js` teilt einen Polling-Zyklus je identischer URL, nutzt ETags,
begrenzt Requests auf acht Sekunden, pausiert in versteckten Tabs und verzögert
Wiederholungen bei Fehlern. Keine neuen `setInterval()`-Schleifen pro Kennzahl
anlegen. ECharts mit `setOption()` aktualisieren; stabile Serien-IDs erhalten
Zuordnungen. [Dynamische Daten](https://echarts.apache.org/handbook/en/how-to/data/dynamic-data/).

Numerische Koordinaten sind Unix-Millisekunden; API-Zeitstempel sind
Unix-Sekunden. Nur einmal mit 1000 multiplizieren. Mit `Intl.DateTimeFormat`
die Stationszeitzone ausdrücklich setzen, auch wenn der Besucher im Ausland
sitzt. Detaillierte Zeitangaben einschließlich Zeitzone vermeiden Unklarheiten
bei der doppelten Herbststunde.

Tooltip `renderMode: 'richText'` verwenden und keine fremden Texte in
HTML-Formatter konkatenieren. `connectNulls: false` lässt Messlücken sichtbar.
Für echte Trendkurven `smooth` nicht ohne fachlichen Grund einschalten:
Glättung kann Messverläufe suggerieren, die nie beobachtet wurden.

Ein Diagramm sollte eine verständliche Beschriftung und eine Datentabelle haben.
Die Galerie zeigt Intervallbeginn, -ende, Einheit und Abdeckung in einer
aufklappbaren Tabelle. ECharts unterstützt außerdem ARIA-Beschreibungen und
Muster; sie ersetzen keine zugängliche Tabelle.
[ECharts-Zugänglichkeit](https://echarts.apache.org/handbook/en/best-practices/aria/).

## 10. Öffentliche API

### Einen Feed veröffentlichen

`public/api/v1.php` lädt ausschließlich eine lokal konfigurierte Feed-Datei:
`WEEWX_PHP_FEEDS`, falls gesetzt; sonst `public-feeds.php` neben der durch
`WEEWX_PHP_CONF` gewählten Stationskonfiguration. Ohne diese Datei gibt es
keine veröffentlichten Feeds. Die lokale Cookbook-Demo besitzt eine solche Datei.

Ein eigenständiges Beispiel für `public-feeds.php`:

```php
<?php
use WeewxPhp\Frontend\Api\Feed;
use WeewxPhp\Frontend\Output;

// $wx wird vom Endpunkt bereitgestellt.
$wx = $wx->output(new Output('de', units: [
    'group_temperature' => 'degree_C', 'group_speed' => 'km_per_hour',
]));

return [
    'garten-live' => new Feed(
        $wx,
        live: ['temperature' => 'outTemp', 'humidity' => 'outHumidity', 'wind' => 'windSpeed'],
        labels: ['temperature' => 'Temperatur', 'humidity' => 'Luftfeuchte', 'wind' => 'Wind'],
        origins: ['https://blog.example.org'],
        pollSeconds: 15,
        liveMaxAge: 120,
        title: 'Garten',
    ),
    'archiv' => new Feed(
        $wx,
        queries: ['temperature' => $wx->reference('archive')->current('outTemp')],
        labels: ['temperature' => 'Temperatur'],
        origins: ['https://blog.example.org'],
        pollSeconds: 60,
    ),
];
```

Für Archiv-/Diagrammfeeds Rezepte vorzugsweise aus dem gemeinsamen Theme-Manifest
übernehmen und dessen Bedarf mit `analytics sync` verwalten, wie
[`themes/cookbook/feeds.php`](../themes/cookbook/feeds.php) zeigt.
Der API-Aufruf registriert fehlende feste Rezepte zwar ebenfalls, berechnet
sie aber nie inline. Unabhängige API-Rezepte können ein eigenes `data.php`
und einen eigenen Besitzer, etwa `api-public`, erhalten. Bei Deaktivierung
diesen Besitzer mit `analytics deactivate api-public` freigeben.

### Vertrag v1

```text
GET /api/v1.php?feed=garten-live
GET /api/v1.php?feed=garten-live&fields=temperature,humidity
HEAD /api/v1.php?feed=garten-live
OPTIONS /api/v1.php?feed=garten-live
```

`fields` darf ausschließlich Felder des gewählten Feeds enthalten.
Es gibt keine HTTP-Parameter für SQL, Messwertnamen, Archivpfade, freie Zeiträume,
Aggregation, Einheiten oder Callback-Funktionen. Weitere Varianten sind weitere
freigegebene Rezepte/Feeds. Unbekannte Parameter werden zurückgewiesen.

Beispielantwort; Werte dienen hier nur zur Darstellung des Schemas:

```json
{
  "version": 1,
  "feed": "garten-live",
  "title": "Garten",
  "timezone": "Europe/Berlin",
  "pollSeconds": 15,
  "data": {
    "temperature": {
      "type": "value",
      "source": "live",
      "label": "Temperatur",
      "value": 21.7,
      "unit": "degree_C",
      "group": "group_temperature",
      "formatted": "21,7 °C",
      "status": "ready",
      "asOf": 1787734200,
      "computedAt": null,
      "coverage": null,
      "delta": false
    }
  }
}
```

`type=series` liefert `points` mit `start`, `end`, `value`, `coverage` sowie
Einheiten- und Statusmetadaten. `type=report` liefert `periods`, `values`,
`meta` und `status`; die Mess-/Berechnungszeiten liegen in den enthaltenen
Reihen/Werten. `source` ist `live`, `archive` oder `astronomy`.
Die gezeigten Wetterfelder enthalten Zahlen oder `null`. Andere Tags können
boolesche Werte, Texte oder Windvektoren als `{real, imag}` liefern. Für
skalare Winddiagramme ausdrücklich Geschwindigkeit oder `vecdir` auswählen.
Feldnamen und ihre fachliche Bedeutung innerhalb eines Feeds stabil halten.
Für eine inkompatible Änderung einen neuen Feed-Namen veröffentlichen.

| HTTP-Status | Bedeutung |
|---|---|
| 200 | Vertrag geliefert; einzelne Felder können fehlen/ausstehen |
| 304 | Inhalt unverändert zu `If-None-Match`; bisherige Antwort weiterverwenden |
| 204 | CORS-Preflight akzeptiert, kein Body |
| 400 | Ungültige Parameter, Felder oder Preflight-Header |
| 403 | Browser-Origin nicht freigegeben |
| 404 | Feed unbekannt oder API nicht eingerichtet |
| 405 | Methode nicht unterstützt |
| 503 | Quelle/Definition vorübergehend nicht nutzbar; Details nur im Serverlog |

Fehler haben `{"version":1,"error":"…"}`. HEAD und 304 haben keinen Body.
Erfolgreiche Antworten tragen einen ETag sowie
`Cache-Control: public, max-age=0, must-revalidate` und `Vary: Origin`.
Der ETag spart Datenübertragung, ist kein Ersatz für Worker oder Abfragelimits.

### Freigabe, Grenzen und Betrieb

**CORS ist keine Anmeldung.** Diese API liefert öffentliche Wetterdaten.
Auch ein Feed mit enger Origin-Liste ist mit einem Server-Client abrufbar.
Keine privaten Innenwerte oder Geheimnisse allein durch CORS schützen.
`origins: ['*']` erlaubt bewusst die Einbettung auf beliebigen Websites.
Browser-Credentials und Autorisierungsheader werden nicht verwendet.

Jeder Feed erlaubt maximal 24 vorbereitete Rezepte und acht Live-Felder,
eine Antwort höchstens 4.096 Serienpunkte und 1 MiB JSON. Feldnamen, Feed-Namen,
Methoden und Parameter sind begrenzt. Live liest pro Feld höchstens 512
Journalpakete im gemeinsamen Lesebudget. Bei Erschöpfung erscheint `pending`.
Ein Live-Paket ersetzt keine archivierten Regenmengen oder Rekorde.

Die Grenzen verhindern unbegrenzte Archivarbeit, aber nicht beliebig viele
HTTP-Requests. Bei öffentlichem Betrieb am Reverse Proxy ein angemessenes
Request-Limit und HTTPS konfigurieren. `pollSeconds` ist eine Client-Empfehlung,
keine serverseitige Ratenbegrenzung. Webserver-Zugriffsprotokolle zeigen
HTTP-Fehlerquote und Anfrageaufkommen. Der API-Prozess braucht Leserechte auf
Live-/Archivdateien und die vorhandenen Zugriffsrechte für den Analytics-Cache.

Feed-Dateien dürfen niemals über URL-Parameter ausgewählt oder aus Uploads
ausgeführt werden. Keine Stationskonfiguration oder Diagnostik öffentlich
ausgeben. API-Fehler enthalten keine internen Pfade oder SQL-Texte.

## 11. Einbettung auf beliebigen Websites

### Zwei HTML-Zeilen, Assets vom Wetterserver

```html
<script type="module" src="https://wetter.example.org/assets/weather-widget.js"></script>
<weewx-weather api="https://wetter.example.org/api/v1.php?feed=garten-live" fields="temperature,humidity,wind" title="Wetter"></weewx-weather>
```

Die JavaScript-Datei einmal pro Seite laden. Danach sind beliebig viele
`weewx-weather`-Elemente möglich. Leere Felderauswahl zeigt alle Einzelwerte
des Feeds. Diagrammreihen werden von diesem kleinen Widget nicht dargestellt.

**Für externe ES-Module auch die statischen Assets per CORS freigeben.**
Die API-Header allein reichen nicht. Beispiel für Nginx im Wetter-vHost:

```nginx
location ~ ^/assets/(weather-widget\.js|feed-client\.js|weather-widget\.css)$ {
    add_header Access-Control-Allow-Origin "*" always;
    add_header X-Content-Type-Options nosniff always;
    try_files $uri =404;
}
```

JavaScript mit korrektem MIME-Typ ausliefern. Bei Apache die entsprechenden
Header für genau diese Dateien setzen. CSP auf der einbettenden Seite muss
die Wetter-Origin für `script-src`, `style-src` und `connect-src` erlauben.
Auf einer HTTPS-Seite auch HTTPS-URLs für alle Wetterressourcen verwenden.

### Assets auf der einbettenden Website

Alternativ drei Dateien in einen eigenen Ordner kopieren:
`weather-widget.js`, `feed-client.js`, `weather-widget.css`. Ihre relativen
Pfade zueinander beibehalten. Dann nur den Script-Pfad ändern:

```html
<script type="module" src="/weather-assets/weather-widget.js"></script>
<weewx-weather api="https://wetter.example.org/api/v1.php?feed=garten-live"></weewx-weather>
```

Jetzt braucht nur noch die JSON-API CORS. Diese Variante verwendet auch das
WordPress-Plugin. Eine vollständige HTML-Datei liegt in [examples/embed.html](../examples/embed.html).

### Verhalten und Gestaltung

Das Widget zeigt Wert, Quelle, Messzeit und gegebenenfalls Status.
Fehlende Werte erscheinen als Platzhalter; bei Verbindungsfehlern bleiben
bisherige Daten mit Fehlerhinweis erhalten. Es gibt keinen automatischen
Wechsel von „Live“ auf alte Archivmessungen.

Styles sind mit Shadow DOM vom Seitentheme getrennt. Anpassen über CSS-Variablen:

```css
weewx-weather {
  --weather-background: #fff;
  --weather-text: #183e37;
  --weather-muted: #52665f;
  --weather-border: #d6e1dc;
}
```

Für eigene Snippets reicht auch `fetch()`:

```js
const response = await fetch('https://wetter.example.org/api/v1.php?feed=garten-live&fields=temperature', {
    credentials: 'omit',
});
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const feed = await response.json();
document.querySelector('#outside').textContent = feed.data.temperature.formatted;
```

Das ist ein einmaliger Abruf. Für regelmäßige Aktualisierung den mitgelieferten
`subscribe()`-Client übernehmen; er behandelt Timeout, Pausen und Wiederholung.
Von der API gelieferte Texte über `textContent` einsetzen, nicht `innerHTML`.

## 12. WordPress-Sidebar-Widgets

Ein installierbares Plugin liegt als Quellpaket unter
[`examples/wordpress/weewx-weather`](../examples/wordpress/weewx-weather).
ZIP im Projektverzeichnis erzeugen:

```sh
python examples/wordpress/package.py
```

Ergebnis: `data/artifacts/weewx-weather.zip`. In WordPress unter
**Plugins → Installieren → Plugin hochladen** auswählen und aktivieren.
Die ZIP enthält die gemeinsamen Widget-Assets lokal; kein CDN, kein
WordPress-Proxy und kein zusätzlicher WordPress-Cronjob sind erforderlich.

Im Block-Widget-Editor einen **Shortcode-Block** in die Sidebar einsetzen:

```text
[weewx_weather api="https://wetter.example.org/api/v1.php?feed=garten-live" fields="temperature,humidity,wind" title="Wetter am Haus"]
```

Derselbe Shortcode funktioniert in Beiträgen und Seiten. Klassische Themes
erhalten zusätzlich das Widget **WeeWX Wetter** mit API-URL, Titel und
kommagetrennter Felderauswahl. Das Plugin verwendet die bestehende Sidebar des
Themes; es registriert keine zusätzliche Sidebar.

JavaScript wird über WordPress eingereiht und als Modul geladen. Widgetformulare
bereinigen Optionen; die Ausgabe maskiert Attribute. Browserzugriffe auf die
API benötigen keine Zugangsdaten. Die WordPress-Origin muss im Feed zugelassen
sein, zum Beispiel `https://blog.example.org` ohne Pfad und ohne Slash am Ende.

Seiten-Caches dürfen das Widget-HTML speichern: Messwerte werden danach im
Browser aktualisiert. Optimierungsplugins dürfen ES-Module und ihre relativen
Imports nicht in klassische Skripte umwandeln. Falls nötig den Handle
`weewx-weather` vom Zusammenfassen ausschließen. Bei einer CSP genügt für
externe Wetterdaten die passende Origin in `connect-src`, da Assets lokal liegen.

Grundlagen: [WordPress WP_Widget](https://developer.wordpress.org/reference/classes/wp_widget/),
[Scripts einreihen](https://developer.wordpress.org/reference/functions/wp_enqueue_script/).

## 13. Prüfen und Fehler finden

### API und Cache prüfen

```sh
curl -i 'https://wetter.example.org/api/v1.php?feed=garten-live'
curl -i -H 'Origin: https://blog.example.org' 'https://wetter.example.org/api/v1.php?feed=garten-live'
curl -i -X OPTIONS -H 'Origin: https://blog.example.org' \
  -H 'Access-Control-Request-Method: GET' -H 'Access-Control-Request-Headers: If-None-Match' \
  'https://wetter.example.org/api/v1.php?feed=garten-live'
```

Im lokalen Theme bei Bedarf `$wx->diagnostics()` ansehen: Datenquelle,
Zeilen, Cachetreffer, nächster Termin und Fehler. Diese Ausgabe bleibt intern.
Bei ausschließlich vorbereiteten Query-Klonen liegt die Diagnostik am jeweiligen
Klon; für den Gesamtbetrieb `analytics status` verwenden.

| Symptom | Prüfen |
|---|---|
| Alles `pending` | Manifest synchronisiert? Worker läuft? Mehrere Erstaufbau-Läufe nötig? |
| `stale`, Werte bleiben gleich | Workerstatus und Messzeit prüfen; Station liefert möglicherweise nichts Neues |
| API 404 | `WEEWX_PHP_CONF`, Feed-Dateipfad und Feed-Name prüfen |
| API 403 | Origin einschließlich Schema und Port exakt freigegeben? |
| API 503 | Serverlog, Definition, SQLite-Zugriff und Antwortgrenzen prüfen |
| Live leer, Archiv gefüllt | LOOP-Eingang, Senderzuordnung und Live-Journal prüfen |
| ECharts leer | Containerhöhe, API-Status, Punkte und Browserkonsole prüfen |
| Diagramm liegt 1970 | Sekunden nicht in Millisekunden umgerechnet |
| Regen um einen Tag verschoben | Tagesbalken mit `start` beschriften |
| Lücken werden zu 0 | Keine `value || 0`-Ausdrücke oder unbedachte Füllwerte verwenden |
| Widget nur auf Fremdseite defekt | API-CORS, Modul-/CSS-CORS, MIME, CSP und HTTPS prüfen |
| Keine Vergleichsjahre | Report-Abdeckung und Ausschlussgründe prüfen; nicht als 0 interpretieren |

### Eigene Themes testen

Mindestens einen vollständigen Tag, eine Datenlücke, einen echten Nullwert,
einen fehlenden Sensor und einen Sommerzeitwechsel verwenden. Einen kalten
Cache aufrufen: Die Seite muss mit Platzhaltern rendern. Dann Worker laufen
lassen und dieselbe Seite erneut prüfen. Einen geänderten Manifestbedarf
synchronisieren und kontrollieren, dass andere Themes ihre Ergebnisse behalten.

Die mitgelieferten Prüfungen:

```sh
docker compose -f tests/docker/compose.yml run --rm unit
docker compose -f tests/docker/compose.yml run --rm lint
node --test --test-isolation=none tests/chart-recipes.test.mjs tests/feed-client.test.mjs
php tests/wordpress-smoke.php
```

PHP-Tests prüfen unter anderem Cache-only-Zugriff, CORS, ETags, Feldfreigaben,
Live-Messzeit und fehlende Daten. JavaScript-Tests prüfen Zeitumrechnung,
Datenlücken, Kalenderbeschriftung und Polling. Der WordPress-Smoke-Test prüft
Hooks, Shortcode, Widgetoptionen und Escaping mit einem lokalen API-Doppel;
ein vollständiger WordPress-Installationstest bleibt ein separater Integrationscheck.

Ergänzende Referenzen: [vollständige Tags](frontend.md),
[Rezepte und Cache-Verhalten](frontend-recipes.md),
[Stationskonfiguration](configuration.md), [CLI](commands.md).
