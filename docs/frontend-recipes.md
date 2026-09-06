# Frontend: Ausgabe, Zeitbezug und vorbereitete Auswertungen

Die Beispiele ergänzen [die Tag-Referenz](frontend.md). Eine Abfrage ist ein
unveränderliches Rezept. `get()` liefert `Value`, `Series` oder `Report`.
Umrechnung und Formatierung ändern weder das Rezept noch seinen Cacheeintrag.

## Ein Ausgabeprofil pro Theme

For visitor-selectable units, use `$theme->output()` instead of fixed unit
overrides. See [the integration example](theme-cookbook.md#visitor-unit-selection)
and [profile reference](display-units.md). The example below defines a fixed
output profile explicitly.

```php
use WeewxPhp\Frontend\Output;

$wx = (require '/pfad/weewx-php/frontend.php')->output(new Output(
    language: 'de',
    units: ['group_temperature' => 'degree_C', 'group_rain' => 'mm',
        'group_speed' => 'km_per_hour', 'group_pressure' => 'mbar'],
    decimals: ['group_pressure' => 0, 'group_percent' => 0, 'ET' => 3],
    missing: '—',
    dateFormat: 'd.m.Y H:i',
));

echo $wx->current('outTemp');                  // HTML-sicher, inklusive Einheit
$series = $wx->last('24h')->series('outTemp', '15m')->series();
$fahrenheit = $series->to('degree_F');
$numbers = $series->pairs();                  // Zahlen ungerundet, null bleibt null
$table = $series->formatted();                // Texte nach demselben Profil
echo $wx->on('1990')->min('outTemp')->value()->to('degree_C');
```

Auch ein ausstehender Wert ohne bekannte Ausgangseinheit lässt sich umrechnen:
er bleibt ein Platzhalter. Bei bekannten, inkompatiblen Einheiten wird ein Fehler
gemeldet. `format()` erzeugt Text, `html()` und die direkte Ausgabe erzeugen
HTML-sicheren Text. Explizite printf-Formate überschreiben das Zahlenprofil.
Sprachen sind zunächst `de` und `en`; keine globale Prozess-Locale wird verändert.
Einheiten können nach Messwert oder Einheitengruppe, Nachkommastellen zusätzlich
nach Einheit überschrieben werden. Temperaturdifferenzen werden ohne Nullpunktversatz
umgerechnet.

## Zeitbezug ausdrücklich wählen

```php
$now = $wx->reference('clock');
$archive = $wx->reference('archive');
$snapshot = $wx->reference('fixed', '2024-09-15 12:00:00');

$now->day();                // heutiger Kalendertag, einschließlich exakt 00:00
$wx->today();               // dasselbe, unabhängig vom sonstigen Zeitbezug
$archive->day();            // Tag, zu dem der letzte Archivdatensatz gehört
$now->last('24h');          // exakt 86400 vergangene Sekunden
$now->last('7d');           // exakt 168 Stunden
$now->days(7);              // genau sieben Kalenderdaten, einschließlich heute
$wx->on('2024-09');         // expliziter voller Monat
```

Beim Archivbezug gehört ein Datensatz um Mitternacht zum gerade abgeschlossenen
Tag, entsprechend WeeWX. Kalenderarithmetik verwendet die Archiv-Zeitzone:
sieben Kalendertage können bei Sommerzeitwechsel 167 oder 169 Stunden umfassen.
`clock` und `fixed` gelten auch für Astronomie. Ein fester Bezug begrenzt dynamische
Wetterperioden auf diesen Zeitpunkt; `between()` und `on()` fragen ausdrücklich
ihre vollständigen Grenzen ab.

Ohne Auswahl bleibt `legacy` kompatibel: Wetter orientiert sich am letzten
Archivdatensatz, Astronomie an der Uhrzeit. `current()` liest den letzten
Archivdatensatz im Standard-/Archivkontext; mit explizitem Zeitpunkt gelten die
vorhandenen `maxDelta`-Regeln. `latest()` sucht rückwärts den letzten gültigen
Wert und liefert dessen Messzeit in `asOf`. `live()` liest ausdrücklich das
Live-Journal. Diese drei Zugriffe ersetzen einander nicht automatisch.

## Messwerte und gemeinsame Datensätze

```php
$sensor = $wx->measurement('extraTemp1');
// stored, derivable, dependencies, missingDependencies, availability,
// kind, unit, group, asOf, age, defaultAggregate, output, aggregates

$wx->day()->series('ET', 'hour');       // standardmäßig Summe
$wx->day()->series('rain', 'hour');     // Summe
$wx->day()->series('outTemp', 'hour');  // zeitgewichtetes Mittel
$wx->day()->avg('outTemp');            // bestehende WeeWX-Semantik

$data = $wx->dataset([
    'outside' => $wx->reference('clock')->days(7)->series('outTemp', 'hour'),
    'other' => $wx->archive('zweiter_ort')->reference('clock')->days(7)->series('outTemp', 'hour'),
]);
$results = $data->get();
$grid = $data->aligned();
echo $data->json();
```

`series()` ohne Aggregat verwendet Messwertsemantik: Intervallmengen wie Regen,
ET und Energie werden summiert; Zustände zeitlich gewichtet; kumulative
Regenzähler liefern den letzten Stand. Eine Zählerdifferenz braucht eine explizite
Reset-Regel und wird deshalb nicht automatisch als Regenmenge interpretiert.
Für Windrichtungen `aggregate('wind', 'vecdir')` verwenden. Ein angegebenes Aggregat
überschreibt diese Auswahl. Eigene Messgrößen verwenden denselben bestehenden
Messwertkatalog und ihre konfigurierten Einheitengruppen.

`measurement()` prüft Schema und aktuelle Messung begrenzt. `stored` bedeutet
Spalte vorhanden, nicht Messung vorhanden. `derivable` bedeutet bekannte Formel;
das ist keine Garantie für vollständige Eingangsdaten. `dependencies` führt die
bekannten Messwert-Voraussetzungen auf; koordinaten-/historienabhängige Formeln
müssen zusätzlich mit den Stationsdaten berechenbar sein.

`aligned()` verbindet exakte Intervallgrenzen, füllt fehlende Werte mit `null`
und weist überlappende, unterschiedlich aufgelöste Raster zurück. Für mehrere
Archive denselben Zeitbezug, dieselbe Zeitzone und dieselbe Auflösung wählen.
Es gibt keine stillschweigende Interpolation. `Series::overlay($timezone)` ordnet
vorhandene Werte nach Monat, Tag und Uhrzeit statt ihrer Listenposition; fehlende
Jahre erhalten `null`, der 29. Februar behält seine eigene Kategorie.

## Vergleiche, Rekorde und Ereignisse als Rezepte

```php
$month = $wx->reference('clock')->month()->sum('rain')
    ->compareYears(minimumCoverage: 0.95)->nightly('02:00');

$wettest = $wx->alltime()->series('rain', 'month')
    ->completed(0.95)->rank(1)->nightly();
$driestSeptember = $wx->alltime()->series('rain', 'month')
    ->completed(0.95)->calendarMonth(9)->rank(1, ascending: true)->nightly();
$dry = $wx->alltime()->series('rain', 'day')
    ->longestSpell(threshold: 0, operator: 'le', unit: 'mm')->nightly();
$lastWetDay = $wx->alltime()->series('rain', 'day')
    ->lastEvent(threshold: 0, operator: 'gt', unit: 'mm')->nightly();
$p95 = $wx->alltime()->series('rain', 'day')->completed(0.95)
    ->quantile(0.95)->nightly();

$report = $month->report();
echo $report->value('current');
echo $report->value('mean');
echo $report->value('difference');
echo $report->value('percentOfMean');
echo $report->value('percentile');
$years = $report->referenceYears();
$exclusions = $report->meta['excluded'] ?? [];
```

Alle diese Rezepte werden einschließlich ihrer Nachbearbeitung im Worker
berechnet und als vollständiger `Report` gespeichert. Eine Seite liefert bei
fehlendem Ergebnis `pending`, bei fälliger Aktualisierung den bisherigen Report
mit `stale`. `periods` enthält berücksichtigte Intervalle mit Abdeckung;
`meta` nennt Regeln und Ausschlüsse. Bei Ranglisten ist `periods` die ausgewählte
Rangliste und `populationCount` die Anzahl aller gültigen Kandidaten. Gleichstände
werden deterministisch nach dem frühesten Beginn aufgelöst.

Der Monatsvergleich verwendet den aktuellen Referenzmonat bis zur selben lokalen
Kalenderzeit in jedem vorherigen Archivjahr. Das Referenzjahr selbst gehört nicht
zum Vergleichsmittel. Beispiel: 15. September, 12 Uhr wird mit 15. September,
12 Uhr der Vorjahre verglichen. Nicht vorhandene Schaltjahresdaten werden mit
Grund ausgeschlossen. Bei null Vergleichsmittel bleibt der Prozentvergleich null.

Trockenperioden verlangen vollständige Intervalle mit 100 % gemessener
Zeitabdeckung. Lücken und nicht zusammenhängende Grenzen unterbrechen die Folge.
`start`, `end`, `duration`, `intervals`, `last`, `since` und `matchingDuration`
liefern Beginn, Ende, Länge, Anzahl, letzte Übereinstimmung, Abstand und gesamte
übereinstimmende Dauer. `lastEvent()` hat die gewählte Intervallauflösung:
ein Tagesrezept liefert das Ende des letzten nassen Tages, keine behauptete
sekundengenaue Regenzeit. Für feinere Suche eine feinere Auflösung und einen
passend begrenzten Zeitraum wählen. Nur `gt`, `ge`, `lt`, `le` sind zulässig.

Quantile verwenden exakt R7 auf den ausgewählten Intervallaggregaten. Das
95-%-Quantil von Tagesniederschlägen ist keine Statistik aller Rohmessungen.
Monatsmediane werden niemals gemittelt oder als Rohdatenmedian ausgegeben.

## Theme-Bedarf und Worker

```php
// data.php enthält ausschließlich vertrauenswürdigen lokalen Theme-Code.
$queries = require __DIR__ . '/data.php';
$wx->syncTheme('mein-theme', $queries); // aktivieren oder geänderte Liste abgleichen
$wx->deactivateTheme('mein-theme');    // exklusive Registrierungen freigeben

$prepared = $wx->cacheOnly();          // keine Berechnung und keine Archivabfragen beim Rendern
$urgent = $wx->current('outTemp')->priority(10);
$history = $wx->alltime()->max('outTemp')->priority(-5)->nightly();
```

```sh
php bin/weewx-php --config station.conf analytics preflight themes/demo/data.php
php bin/weewx-php --config station.conf analytics sync demo themes/demo/data.php
php bin/weewx-php --config station.conf analytics run
php bin/weewx-php --config station.conf analytics status
php bin/weewx-php --config station.conf analytics deactivate demo
```

`sync` ist atomar und bei unverändertem Bedarf idempotent. Andere Themes und
manuell mit `register` angeheftete Abfragen behalten ihre Ergebnisse. Die
Aktivierung stellt die Berechnungen dem bestehenden Tick/Worker bereit;
`analytics run` stößt die Vorbereitung sofort innerhalb des Laufbudgets an.
Ein großer Erstaufbau benötigt mehrere Workerläufe. Der normale Tick führt den
Worker bereits nach Ingest/Archivierung aus. Keine zusätzliche Cache-Datenbank
pro Theme und kein neuer Serverdienst sind erforderlich.

Prioritäten reichen von -10 bis 10. Aktuelle Messwerte starten mit 10;
lange wartende Aufgaben erhalten Vorrang durch Alterung. Ein Job bekommt nur
einen begrenzten Zeilen-, Statement- und Zeitschritt pro Lauf und kann fortgesetzt
werden. `nightly()`, `weekly`, `monthly`, `yearly`, feste Dauern und `once` legen
die Publikationsfrequenz fest. Ein geschlossenes explizites Intervall wird nur
nach einer betroffenen Daten-/Konfigurationsänderung wieder berechnet.

## Wiederverwendung und Diagnose

Der gemeinsame Cache speichert zusätzlich Anzahl, Summe, gewichtete Summe,
Zeitgewicht sowie Minima/Maxima mit Zeitpunkten. Verschiedene Aggregate können
denselben Zustand nutzen. Disjunkte, lückenlos passende Zustände können zu einem
größeren Zeitraum zusammengeführt werden. Beispiel: Tageszustände → Monat → Jahr.
Rohdaten- und Tageszusammenfassungszustände werden getrennt gehalten, ebenso
Beobachtungen und Archive. Ein begrenzter Zusammenführungsversuch fällt bei
fehlender vollständiger Partition auf den fortsetzbaren Quellzugriff zurück.

Geschlossene Serienblöcke bleiben ebenfalls wiederverwendbar. Intervallweise
Archivkorrekturen löschen betroffene Zustände und Blöcke. Fremde Schreibzugriffe
ohne Änderungsprotokoll invalidieren vorsichtshalber breiter. „Abgeschlossen“
bedeutet daher ohne neue Quelldaten/Korrekturen dauerhaft gültig.

`$wx->diagnostics()` liefert pro Abfrage Cachetreffer, Datenquellen, gelesene
Zeilen/Statements, Laufzeit, nächste Aktualisierung und Fehler. `analytics status`
enthält zusätzlich Besitzeranzahl, Priorität, Bauzustand und die Diagnose des
letzten Rechenschritts. Die Werte sind Schritt-/Abfragemessungen, keine Schätzung
sämtlicher künftiger Kosten. Diagnoseausgaben gehören in lokale Entwicklung oder
geschützte Administration, nicht in einen öffentlichen JSON-Endpunkt.

Normale Serien sind auf 2048 Punkte begrenzt, Analysen auf 50000 Intervalle,
Datensätze auf 128 Abfragen und der Cache auf 1000 registrierte Rezepte. Bei
größeren Datenmengen die Auflösung erhöhen. Punktgrenzen erzeugen einen sichtbaren
Fehler, keine still abgeschnittenen Statistiken.

## Live und Demo

`live('outTemp', maxAge: 120)` liest höchstens 512 aktuelle Journalpakete,
wendet bestehende Senderzuordnung, Einheiten, Kalibrierung und Qualitätsregeln an
und liefert Messzeit und `ready`/`stale`/`unavailable`. Ein fehlendes Live-Journal
wird nicht angelegt. Noch nicht im Paket vorhandene, historienabhängige Größen
werden hier nicht neu abgeleitet. Live-Werte werden explizit angezeigt; sie
werden nicht zu Archivmengen addiert. Tagesextreme bleiben die vom vorhandenen
Archivprozess einschließlich LOOP-Extremen gespeicherten Werte.

Das Demo gleicht seinen Bedarf beim Laden ab, verwendet das Ausgabeprofil,
sieben Kalenderdaten und die drei neuen Analysebeispiele. `public/data.php`
exportiert ausschließlich diesen festen Datensatz aus vorbereiteten Ergebnissen,
zuzüglich des ausdrücklich angefragten Live-Werts. Es akzeptiert weder SQL noch
Dateipfade oder frei formulierte Abfragen. Polling alle 15 Sekunden pausiert in
versteckten Tabs und überlappt nicht. Diagramme werden bei geändertem vorbereiteten
Stand aktualisiert. Der Worker muss unabhängig davon regelmäßig laufen.
