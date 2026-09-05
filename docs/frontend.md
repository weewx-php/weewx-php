# PHP-Tags für Themes

Neu: [Ausgabeprofile, Zeitbezug, Theme-Verwaltung und vorbereitete
Auswertungen](frontend-recipes.md). `series()` ohne Aggregat verwendet jetzt
Messwertsemantik; mit ausdrücklich angegebenem Aggregat bleibt die Auswahl unverändert.

Ein lauffähiges [Demo-Theme](../themes/demo/README.md) zeigt Messwerte,
Temperatur- und Regenverläufe sowie Sonnenzeiten mit diesen Tags.

```php
$wx = require '/pfad/weewx-php/frontend.php';
echo $wx->current('outTemp')->to('degree_C');
echo $wx->day()->sum('rain');
echo $wx->year()->max('outTemp');
echo $wx->on('2025-09')->sum('rain');
echo $wx->last('6h')->avg('windSpeed');
echo $wx->trend('barometer', over: '3h');
$json = $wx->week()->series('outTemp', 'hour')->json(milliseconds: true);
```

`frontend.php` verwendet `weewx-php.conf` oder den Pfad aus `WEEWX_PHP_CONF`.
Alternativ: `Weather::open($configPath, $archiveId)`. `archive('garten')` wählt
ein weiteres konfiguriertes Archiv. Alle so erzeugten Instanzen teilen sich
das Seitenbudget. Die Archivdatei muss bereits existieren.

## Bedarf anmelden und vorbereiten

Eine Abfrage ist zunächst ein Rezept. `get()`, `value()`, `series()`, `raw()`,
Formatierung oder Ausgabe fordern das Ergebnis an und melden den Bedarf
automatisch an. Für die Vorbereitung ohne Seitenbesuch gibt es `data.php`:

```php
// themes/mein-theme/data.php; $wx kommt vom Theme oder vom CLI.
return [
    'temperature' => $wx->day()->series('outTemp', 'hour'),
    'rainMonths' => $wx->alltime()->series('rain', 'month', 'sum')
        ->completed(0.95)->nightly('03:00'),
    'rainRecord' => $wx->alltime()->maxsum('rain')->nightly(),
    'sunrise' => $wx->almanac()->sun()->rise()->nightly('00:10'),
];
```

```sh
php bin/weewx-php analytics register themes/mein-theme/data.php
php bin/weewx-php analytics run
php bin/weewx-php analytics status
php bin/weewx-php analytics catalog
```

Registrierungen bleiben bis `analytics forget <id>` angeheftet. Automatisch
entdeckte Rezepte werden nach 30 Tagen ohne Verwendung nicht mehr aktualisiert.
Maximal 1.000 Rezepte können registriert sein. Bei Änderungen an `data.php`
alte IDs entfernen und neu registrieren. Die Datei ist vertrauenswürdige
Theme-Software, kein Uploadformat.

Der bestehende Tick erledigt Hintergrundarbeit nach Archivierung und Uploads
mit höchstens zwei Sekunden seines verbleibenden Budgets. Lange Berechnungen
setzen beim nächsten Tick fort. `analytics run` verwendet das konfigurierte
Zeitbudget. Ein zusätzlicher Daemon ist nicht erforderlich.

## Takte, Ergebnisse und historische Blöcke

| Aufruf | Aktualisierung |
|---|---|
| Standard / `refresh('archive')` | Archivintervall |
| `refresh('15m')`, `refresh('6h')` | Entsprechend lange Zeitfenster |
| `nightly('03:00')` | Täglich zur lokalen Uhrzeit |
| `refresh('daily')` | Nächste lokale Mitternacht |
| `refresh('weekly')` | Nächster Wochenbeginn, Montag |
| `refresh('monthly')` / `refresh('yearly')` | Monats-/Jahresbeginn |
| `refresh('once')` | Einmal; Änderungen können eine Reparatur auslösen |

Abgeschlossene explizite Zeiträume haben keinen zeitlichen Verfall. Ein fertiger
September 2025 bleibt erhalten. Variable Abfragen wie `year()` behalten ihren
Takt, weil sie später ein anderes Jahr bezeichnen. Serien speichern abgeschlossene
Blöcke separat und verwenden sie über mehrere Rezepte und Takte hinweg wieder.
Formatierung und Einheitenumrechnung erzeugen keine zusätzliche Berechnung.

`.completed()` entfernt angeschnittene und laufende Blöcke. Das Argument ist
die Mindestabdeckung mit gültigen Messintervallen; Standard 1 bedeutet vollständig.
Lücken sind `null`, ein trockener Zeitraum hat den Messwert 0.

`ready` bedeutet gemäß vereinbartem Takt bereit, `pending` noch kein Ergebnis,
`stale` ein vorhandenes Ergebnis mit fälliger Neuberechnung. `asOf` ist der
Bezugszeitpunkt, `computedAt` der Berechnungszeitpunkt. Das Theme entscheidet
über Platzhalter und erneutes Laden. `ready` bestätigt keine aktuelle Verbindung
zur Station; die Archivmessungen können selbst veraltet sein.

Änderungen unseres Archivers invalidieren betroffene Tage und abhängige Ergebnisse.
Korrekturen dürfen auch abgeschlossene Blöcke erneuern. Konfigurationsänderungen
und erkannte fremde Schreibzugriffe invalidieren vorsichtshalber breiter.
Fremde Schreibprogramme melden keine Änderungsbereiche und können deshalb mehr
Neuberechnungen verursachen. Nach externen Importen explizit invalidieren:

```sh
php bin/weewx-php analytics invalidate kirchdorf 2025-09-01 2025-10-01
```

Die separate `analytics.sdb` ist ersetzbar und gehört außerhalb des Webroots.
Das Frontend öffnet WeeWX-Archive ausschließlich lesend. Historische Cache-Blöcke
haben keine TTL und wachsen mit dem verwendeten Bedarf. Eine automatische
Bereinigung nach Speicherquote ist noch nicht implementiert.

## Leistungsgrenzen

Kleine Erstberechnungen dürfen auf der Seite laufen. Das gemeinsame Standardbudget
beträgt 12.000 gelesene Zeilen, 128 Quellabfragen und ungefähr 200 ms. Archivabfragen
sind nach Primärschlüsseln begrenzt; Berechnungen arbeiten in Portionen von 512
Zeilen. PHP 8.1 bietet keinen SQLite-Progress-Handler: die Zeitgrenze wird zwischen
Statements, Zeilen und Astronomieschritten geprüft, nicht als harte Unterbrechung
einer laufenden Operation. Eine Datenbanksperre wartet höchstens 25 ms.

Eine Serie enthält höchstens 2.048 Punkte. Für Jahrzehnte Monats-/Jahresblöcke
wählen; Tagesrekorde können direkt über die Tagesaggregate abgefragt werden.
Ungültige Abfragen werfen `QueryError`; Budgetüberschreitungen erzeugen einen
Hintergrundjob. Keine frei formulierten SQL-Ausdrücke. Das Budget begrenzt diese
Bibliothek, nicht beliebigen PHP-Theme-Code oder die Auslastung des Hosts.

Tageszusammenfassungen werden nur benutzt, wenn Version 4.0 und `lastUpdate`
den benötigten Zeitraum abdecken. Andernfalls verwenden einfache Aggregate die
budgetierten Rohdaten; reine Tagesaggregate verlangen intakte Zusammenfassungen.
Gemischte Einheitensysteme im Roharchiv müssen vor Aggregation normalisiert werden.

## WeeWX und xaggs

Grundlage: WeeWX 5.5 und xaggs 1.0, Commit
`d36145689b6d4bd363a792432df94215d69f5026`. `analytics catalog` liefert die Namen.
Der Zweck ist eine PHP-API mit entsprechenden Funktionen, kein Cheetah-Interpreter.

| Familie | PHP-Zugang |
|---|---|
| current, latest, trend | `current('outTemp')`, `latest('outTemp')`, `trend('outTemp')` |
| hour/day/yesterday/week/month/season/year/rainyear/seasonsyear/alltime | Gleichnamige Methoden, `ago: 1` für den vorherigen Zeitraum |
| Freie Spannen | `between(start, end)`, `last('6h')`, `on('2025-09')` |
| Messwerte und eigene Spalten | Beobachtungsname als Argument; `observations()` |
| Aggregate | `aggregate('rain', 'maxsum')` oder `maxsum('rain')` |
| Schwellen | `year()->avg_ge('outTemp', 25, 'degree_C')` |
| Kalenderiteration | `periods('day')` |
| Serien | `series('rain', 'day', 'sum')`; Aggregat `cumulative` für laufende Summe |
| Rohserien | `records('outTemp')` |
| Wertehelfer | `to()`, `format()`, `html()`, `raw()`, `json()` |
| Station/Units/Labels | `station()`, `unit('outTemp')`, `Theme::label()` |
| gettext/pgettext | `Theme::text(message, context)` bzw. `html()` |
| Skin/Report/Extras/SummaryBy* | `Theme` und `Theme::tags()`; Renderkontext vom Theme |
| Python-Helfer wie jsonize, to_int, to_bool, to_list | PHP-Typen, Casts und JSON-Funktionen |

Der Kernkatalog enthält 38 Namen: Min/Max samt Zeitpunkten, erste/letzte Werte,
Differenzen, Ableitungen, RMS, Windvektoren, Tagesextrem-Mittel, Extrema von
Tagessummen, Schwellenzählungen und Verfügbarkeitsprüfungen. `windvec` und
`windgustvec` liefern `Vector` mit Ost-/Nordkomponente, Betrag und Richtung.
Heiz-/Kühl-/Wachstumsgradtage verwenden Tagesmittel. Fehlende ableitbare Spalten
verwenden unsere vorhandenen Wetterformeln unter demselben Lesebudget.

Alle sieben `historical_*`-Aggregate von xaggs betrachten einen Kalendertag über
alle Archivjahre: `on('2025-09-05')->historical_avg('outTemp')`.
`avg_ge`, `avg_gt`, `avg_le`, `avg_lt` zählen Tage anhand ihres Tagesmittels.

Archivspannen verwenden WeeWXs `(Start, Ende]`; Mitternacht gehört zum vorherigen
Archivtag. Kalenderblöcke verwenden die Archivzeitzone einschließlich Sommerzeit.
Bei der doppelt vorkommenden Herbststunde bleibt der übergebene UTC-Zeitpunkt
erhalten. `season()` ist meteorologisch. `calendar(weekStart: 0, rainYearStart: 10)`
stellt Wochenbeginn (0 = Montag, 6 = Sonntag) und Regenjahr ein.

## Astronomie aus unseren vorhandenen Berechnungen

```php
echo $wx->almanac()->sun()->rise();
echo $wx->almanac()->moon()->tag('next_rising');
echo $wx->almanac()->tag('nextFullMoon');
echo $wx->almanac()->body('jupiter')->altitude();
echo $wx->almanac()->body('Sirius')->azimuth();
echo $wx->almanac()->separation('moon', 'venus');
echo $wx->almanac()->observer(horizon: -6, pressure: 0)->sun()->center()->rise();
$path = $wx->almanac()->body('mars')->series(
    'altitude', '2026-09-05', '2026-09-06', '15m'
)->series();
```

Planetenberechnungen/Bahndaten stammen aus `wetter`, Mondpositionen, Mondphasen
und Jahreszeiten aus `weewx-evo`. `Weewx\Sun` bleibt die Sonnenbasis. Python und
PyEphem dienen ausschließlich als Testreferenzen. Herkunft: `src/Astronomy/SOURCES.md`.

Sonne, Mond, acht weitere Körper einschließlich Pluto und der PyEphem-Sternkatalog
stehen zur Verfügung: Auf-/Untergänge, Transits/Antitransits, vorige/nächste
Ereignisse, Sichtbarkeitsdauer und Änderung, Koordinaten, Winkelabstände,
Beleuchtung, Mondalter/-namen, Sternzeit, Entfernungen und Jahreszeitenereignisse.
Die alten Winkel-Kurznamen werden akzeptiert. Winkel sind einheitlich Grad;
Zeiten Unix-Sekunden, umrechenbar mit `to('dublin_jd')`.

Nicht anwendbare Werte bleiben `null`. `mag` ist derzeit nur für Sonne und
Katalogsterne verfügbar. Satelliten-/Kometenbahnen aus zusätzlichen Katalogen
und deren spezielle Felder sind noch nicht angebunden. `almanac($timestamp)`
legt den Zeitpunkt fest und wird dauerhaft gespeichert; ohne expliziten Zeitpunkt
gilt die aktuelle Uhrzeit mit fünf Minuten Standardtakt. Beobachterparameter
gehören zum Rezept.

Die Ereignissuche umfasst 48 Stunden und kann einen Aufgang am Folgetag finden,
bei dem PyEphem zunächst `NeverUp` meldet. `visible` misst tatsächliche Sichtbarkeit
im lokalen Tag einschließlich 23-/25-Stunden-Tagen. Die Näherungsverfahren sind
keine bitgenaue Nachbildung von PyEphem. Die Vergleichsmatrix umfasst 2.072 Fälle
aus 2000, 2024, 2026 und 2035 an drei Orten: größte beobachtete Abweichung 0,02725°
und 62 s. Pluto ist für 1885–2099 ausgelegt.

## Niederschlagsvergleiche

```php
$months = $wx->alltime()->series('rain', 'month', 'sum')
    ->completed(0.95)->nightly()->series();
$wettest = $months->rank(5);
$driest = $months->rank(5, ascending: true);
$septembers = $months->calendarMonth(9, 'Europe/Berlin');
$median = $septembers->quantile(0.5);
$comparison = $septembers->compare($wx->on('2025-09')->sum('rain')->value());
echo $comparison['mean'];
echo $comparison['difference'];
echo $comparison['percentOfMean'];
echo $comparison['percentile'];

// Originale WeeWX-Tagesrekorde:
echo $wx->alltime()->maxsum('rain')->nightly();
echo $wx->alltime()->maxsumtime('rain')->nightly();
echo $wx->alltime()->minsum('rain')->nightly();
```

Ranglisten behalten Zeitspanne und Datenabdeckung. Gleichstände ordnen sich nach
dem früheren Zeitraum. Quantile verwenden lineare Interpolation (R7), Perzentile
den mittleren Rang bei Gleichständen. Fehlende Messungen nehmen nicht teil.
`compare()` verwendet alle Messwerte der übergebenen Referenzserie, gegebenenfalls
auch den Vergleichsmonat selbst. Für unabhängige Referenzen Grenzen mit `between()`
festlegen. Laufende Monate nur mit gleich langen historischen Ausschnitten
vergleichen; diese Ausrichtung erfolgt noch nicht automatisch.

Weitere definierte Rezeptarten, noch nicht implementiert:

| Auswertung | Fachregel | Takt |
|---|---|---|
| Monatsstand bis heute über frühere Jahre | Gleicher lokaler Kalendertag; Regel für 29. Februar | Täglich; historische Tagesblöcke bleiben |
| Längste Trocken-/Nassperiode | Regenschwelle, Abdeckung, Lücken unterbrechen | Nach Tagesabschluss; Präfix/Suffix zusammenführen |
| Regenereignisse | Trockene Pause, Mindestmenge, Intensität | Archivintervall; geschlossene Ereignisse bleiben |
| Klimanormalen/Anomalien | Referenzjahre, Abdeckung, Gewichtung | Monats-/Jahresabschluss |
| Frost-, Hitze-, Tropennächte | Lokales Tagesfenster und Schwellen | Täglich |
| Gleitende Quantile/Windrose | Fenster, Zeitgewichtung, Windstille | Je Rezept; keine Quantile aus gemittelten Quantilen |

Neue Aggregate benötigen Einheiten, Null-/Lückenregel, Zusammenführungsregel,
Datenabhängigkeiten und Takt. Themes behalten dadurch dieselbe kleine API.

## Prüfungen und Quellen

`tests/Unit/Frontend` sowie die Conformance-Prüfungen `frontend`, `astronomy`
und `frontend_scale` testen die Schicht. Der Skalierungstest erzeugt 1.051.776
Fünfminutenzeilen und 3.652 Tageszusammenfassungen. Ein gemessener Lauf benötigte
45,3 ms für die erste Gesamtsumme, 0,3 ms für den Cachezugriff und 16,3 ms bis zur
budgetbedingten Unterbrechung einer Rohdatenabfrage. Keine zugesicherte Host-Latenz.

Quellen: [WeeWX-Tags](https://weewx.com/docs/latest/custom/cheetah-generator/),
[WeeWX-Entwicklerhinweise](https://weewx.com/docs/latest/devnotes/),
[weewx-xaggs](https://github.com/tkeffer/weewx-xaggs).
