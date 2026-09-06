# Erweiterungen und Theme-Tags

Erweiterungen sind optionale lokale PHP-Pakete außerhalb von `src/`. Der Core
lädt ihre Registrierung, stellt einen Archivkontext bereit und ruft ihre
Hintergrundarbeit auf. Anbieter, Fachlogik und Datenformate gehören ins Paket.
Die [Klimaerweiterung](https://github.com/weewx-php/extension-climate) ist ein Beispiel.

## Aktivierung

Im Admin unter **Erweiterungen** ein Paket aus dem
[geprüften Katalog](https://github.com/weewx-php/extension-catalog) installieren
und anschließend aktivieren. Updates werden ebenfalls dort ausdrücklich
ausgelöst. Die Klimaerweiterung liegt im eigenen
[Repository](https://github.com/weewx-php/extension-climate).

Für manuell kopierte Pakete bleibt die Dateikonfiguration verfügbar:

```ini
[Extensions]
    [[climate]]
        enabled = true
        entry = extensions/climate/extension.php
        [[[options]]]
            archives = kirchdorf
            start_year = 1991
            end_year = 2020
            window_days = 5
```

`entry` ist ein lokaler PHP-Pfad, relativ zur Konfigurationsdatei oder absolut.
Pakete werden nur mit `enabled = true` geladen. IDs bestehen aus Kleinbuchstaben,
Ziffern und Unterstrichen und beginnen mit einem Buchstaben. `options` gehört
vollständig dem Paket. Bei Klima bedeutet eine fehlende Archivliste alle
aktivierten Archive.

PHP-Pakete sind vertrauenswürdiger Anwendungscode mit den Rechten des
PHP-Prozesses. Installation und Aktivierung erfolgen durch den Betreiber über
Dateien und Konfiguration. Es gibt keinen öffentlichen Upload oder über HTTP
wählbaren Codepfad. Remote-Wrapper und Netzwerkpfade werden abgewiesen.
Konfigurationsprüfung führt keinen Erweiterungscode aus. Fehlende Pakete oder
fehlgeschlagene Registrierungen verhindern den Archivbetrieb nicht; der Worker
meldet sie im Log und unter `extensions` im Tick-Ergebnis.

## Registrierung

Eine Einstiegsdatei gibt einen Callable zurück. Eigene Klassen können über einen
paketeigenen Autoloader oder `require_once` geladen werden. Kein Composer auf
dem Webhost erforderlich.

```php
<?php
declare(strict_types=1);

use WeewxPhp\Config\Section;
use WeewxPhp\Extension\Context;
use WeewxPhp\Extension\Registration;
use WeewxPhp\Frontend\Value;

return static function (Registration $tags, Section $options): void {
    $tags->tag('example', static function (Context $context, array $args): Value {
        return new Value(20.0, 'degree_C', 'group_temperature', observation: 'outTemp');
    });
};
```

Unter der ID `sample` registriert dies `sample.example`. Doppelte Tagnamen werden
abgewiesen; eine fehlgeschlagene Registrierung wird vollständig verworfen.
Andere Pakete und bestehende Core-Methoden behalten ihre Namen.

```php
if ($wx->hasTag('sample.example')) {
    echo $wx->tag('sample.example')->to('degree_F')->html();
}
```

Ein Reader erhält `Context` und ein Array mit tagabhängigen Optionen. Er liefert
`Value`, `Series` oder `Report`. `$wx->output(...)` wendet das Ausgabeprofil auch
auf diese Ergebnisse an. Archivwechsel über `$wx->archive(...)` gelten ebenso.
Der Reader validiert Optionen und darf ausschließlich vorbereitete lokale Daten
lesen: keine Downloads, Registrierung neuer Jobs oder Archivschreibzugriffe
beim Seitenaufruf. Dies ist ein Vertrag für vertrauenswürdigen PHP-Code, keine
Sandbox. Der Core liefert dem Reader keinen Netzwerkclient.

`Context` enthält `archive`, `now`, `directory` und `options`. `options` enthält
die globalen Paketoptionen mit den Ausnahmen des aktuellen Archivs (API 2).
`now` ist die aktuelle Uhrzeit;
ein historischer Bezug wird als Tagoption übergeben. `directory` liegt unter
`data/extensions/<paket>/<archiv-hash>/` und ist je Paket und Archiv getrennt.
Lesen legt dieses Verzeichnis nicht an.

## Am Tick einhängen

```php
use WeewxPhp\Archive\Budget;
use WeewxPhp\Upload\Http\HttpClient;

$tags->worker(static function (Context $context, Budget $budget, HttpClient $http): array {
    // Einen begrenzten, fortsetzbaren Schritt ausführen.
    // Rückgabe z. B. ['status' => 'pending'] oder ['status' => 'ready'].
    return ['status' => 'ready'];
});
```

Mit `worker(...)` hängt sich ein Paket in den bestehenden Tick ein. Es benötigt
keinen eigenen Cronjob, Zeitplan oder öffentlichen Endpunkt. Der normale Tick
ruft den Worker pro aktiviertem Archiv auf; bei eingereihten Ticks übernimmt dies
die Hintergrundspur `services`. Das gilt auch für den Besucher-Tick und die durch
Stationseingänge ausgelösten Ticks. Pro Paket ist ein Worker registrierbar.
Er läuft außerhalb
der Archivsperre, besitzt eine eigene Sperre pro Paket/Archiv und teilt sich das
verbleibende Zeitbudget. Unter zwei Sekunden Restbudget startet kein Worker.
Der übergebene HTTP-Client begrenzt das Anfrage-Timeout auf das Restbudget.
Das Paket muss außerdem Verarbeitung, Antwortgrößen und Wiederholungen begrenzen.

Ergebnisse erscheinen als `extensions["climate/kirchdorf"]`. Fehler eines Pakets
werden protokolliert, ohne andere Archive oder Pakete zu stoppen. Pakete dürfen
keine Geheimnisse in Exceptions oder Statusdaten übernehmen.

```bash
php bin/weewx-php extensions tags
php bin/weewx-php extensions run
```

`run` ist ein optionaler manueller Diagnoseaufruf und führt einen Schritt pro
Archiv aus, mit denselben Sperren und Limits wie der Tick. Für den automatischen
Betrieb genügt der bereits laufende Tick.

Die allgemeinen Analytics-Abfragen und `syncTheme()` bleiben für Messdaten
zuständig; externe Tags lesen ihren eigenen vorbereiteten Datenbestand direkt.

### Voraussetzung des bestehenden HTTP-Ticks

HTTP-Aufrufe reihen Arbeit ein und starten die vorhandenen PHP-Hintergrundprozesse
über `exec`. Meldet der Dispatcher `launcher = external-required`, konnte er
diese Prozesse nicht starten. Dann muss die allgemeine Tick-Ausführung auf dem
Host eingerichtet werden; beispielsweise über den CLI-Befehl `tick`. Dies betrifft
alle eingereihten Arbeiten und ist keine zusätzliche Anforderung einer Erweiterung.
Ein synchroner HTTP-Fallback ist derzeit nicht vorhanden.

## Katalog und Installation

Der Admin lädt `catalog.json` aus `weewx-php/extension-catalog`, mit sechs Stunden
lokalem Cache. **Katalog aktualisieren** lädt ihn erneut. Installation und
Aktivierung prüfen die Freigabe immer mit einem frischen Abruf; ein alter Cache
wird dabei nicht als Ersatz verwendet. Bei einem zwischenzeitlich geänderten
Katalogeintrag muss die angezeigte Version neu geladen werden.

Ein Eintrag benennt Paket-ID, Version, Erweiterungs-API, PHP-Mindestversion,
benötigte PHP-Module, Repository, festen Commit, Einstiegsdatei, Prüfbericht und
SHA-256 jeder ausgelieferten Datei. Der Installer unterstützt API 1 und 2 und lädt
ausschließlich Dateien von `raw.githubusercontent.com/weewx-php/...` mit einem
40-stelligen Commit-SHA. Metadaten führen keinen Code aus. Die Aufnahme in den
Katalog setzt eine dokumentierte Codeprüfung voraus; die Prüfsummen sichern die
Identität der ausgelieferten Dateien. Der Katalog ist Teil der Vertrauensgrenze.

Installationen erfolgen unter `data/extension-store/packages/<id>/<freigabe-hash>/`.
Alle Dateien werden zuerst zwischengespeichert und geprüft. Erst danach wird die
Konfiguration atomar auf das vollständige Paket umgestellt. Neue Pakete sind
zunächst deaktiviert; Updates behalten Aktivierungsstatus und Optionen. Der
Installer lädt niemals PHP-Code des Pakets und hält während HTTPS-Anfragen keine
Archivsperre. Er benötigt weder ZIP, Git, Composer noch Shellzugriff.

Grenzen: 64 Dateien je Paket, 512 KiB je Datei, 4 MiB je Paket und maximal
20 Sekunden pro Installationsversuch, zusätzlich begrenzt durch das PHP-Limit.
Gültige Teildownloads bleiben für den nächsten Versuch erhalten. Pfadwechsel,
Symlinks in Paketverzeichnissen, doppelte Dateinamen und falsche Prüfsummen
werden abgewiesen. Der Installer übernimmt keine selbst eingegebenen Download-URLs.

**Deaktivieren** beendet die Registrierung für neue Tick- und Theme-Aufrufe.
**Entfernen** entfernt die Paketkonfiguration. Daten und bisherige Paketversionen
bleiben erhalten, damit laufende Ticks fertig werden können und eine spätere
Neuinstallation dieselben Daten nutzen kann. Es gibt keine automatische
Codeaktualisierung oder Fernabschaltung. Aus dem Katalog entfernte Pakete können
nicht neu installiert oder aktiviert, aber weiterhin deaktiviert und entfernt
werden. Manuell konfigurierte Pakete werden nicht vom Installer überschrieben.

## Einstellungen im Admin (API 2)

Ein Katalogeintrag kann `settings: "settings.json"` registrieren. Die Datei muss
mit ihrer SHA-256-Prüfsumme im Paket stehen. Der Admin zeigt anschließend einen
Menüeintrag unter **Erweiterungen** und eine Schaltfläche **Einstellungen** an.
Das funktioniert auch bei deaktiviertem Paket und führt dessen PHP nicht aus.

```json
{
  "schema": 1,
  "scope": "archive",
  "fields": [
    {"key": "days", "type": "integer", "label": {"de": "Tage", "en": "Days"},
     "default": 7, "min": 1, "max": 16}
  ]
}
```

`scope: global` bietet eine gemeinsame Einstellung; `archive` ergänzt Ausnahmen
pro Archiv. Globale Werte liegen in `options`, Ausnahmen in
`archive_options/<archiv-id>`. Worker und Tags lesen die zusammengeführten Werte
aus `$context->options`. Der Registrierungs-Callback erhält weiterhin nur die
globalen Optionen. Paketcode muss seine Laufzeitwerte selbst validieren, weil
die Konfiguration auch direkt bearbeitet werden kann.

Feldtypen: `text`, `integer`, `boolean`, `secret`, `archives`. `label.en` ist
verpflichtend, weitere Sprachen sind optional. Zahlen haben `min` und `max`;
`max: previous_year` erlaubt nur abgeschlossene Jahre. `format: api_key` begrenzt
Werte auf 256 Buchstaben, Ziffern, Bindestriche und Unterstriche. Ganzzahlregeln
`rules: [{"from": "start_year", "to": "end_year", "min": 9, "max": 79}]` prüfen
die Differenz. Maximal 40 Felder und 64 KiB JSON je Paket.

Geheimnisse bleiben im Formular leer. Leer speichern erhält den bestehenden
Wert; **Gespeicherten Schlüssel löschen** entfernt ihn. Formularvollständigkeit,
Schema-Prüfsumme, Konfigurationsrevision, CSRF und bestehende Admin-Anmeldung
werden vor dem Speichern geprüft. Konfigurationsbackups bleiben vertraulich.

## Sicherung und Wiederherstellung

Installationsbackups enthalten die Konfiguration, aber keine Erweiterungsdateien
oder Erweiterungscaches. Pakete separat kopieren und nach einer Wiederherstellung
deren `entry`-Pfade prüfen. Die Klimaerweiterung kann ihre Daten erneut laden.
Bei über den Admin installierten Paketen erkennt der Katalog die gespeicherte
`managed_release` auch nach einem Verlust der Paketdateien; **Installieren**
stellt die aktuell freigegebene Version wieder her und behält Optionen bei.
Erweiterungen mit unersetzlichen Daten benötigen eine eigene Sicherung; der
aktuelle Erweiterungsvertrag enthält noch keinen Backup-Hook.
