# Review: Theme-Cookbook, öffentliche Feeds und Widgets

Datum: 2026-09-05. Reviewer: `/root`.
Security-Sensitive: **YES**. OWASP-Kategorien geprüft: **10/10**.

## Umfang

- `src/Frontend/Api/{Feed,Endpoint,Response}.php`, `public/api/v1.php`.
- `Query::prepared()` erzwingt Cache-only-Lesen für ein bestehendes Rezept.
- `Weather::live()` wendet das Ausgabeprofil jetzt auch ohne Live-Datei an.
- `themes/cookbook/{data,feeds}.php`, `public/cookbook.php` und dessen Assets.
- Wiederverwendbarer Feed-Client, Custom Element und WordPress-Plugin.
- Dokumentation, Paketbau und zugehörige Tests.

## Sicherheitsgrenzen

Die API ist eine explizite Veröffentlichung öffentlicher Wetterdaten. Ohne
lokale Feed-Datei gibt es keine Feeds. Ausschließlich der Betreiber definiert
Archive, Beobachtungen, Einheiten, Aggregationen und Zeiträume. HTTP-Clients
wählen nur einen Feed und eine Teilmenge seiner freigegebenen Felder.
Der Include-Pfad stammt aus der lokalen Umgebung/Konfiguration, nie aus HTTP.

CORS ist keine Authentifizierung. Der Cookbook-Beispielexport erlaubt bewusst
beliebige Origins und enthält nur die aufgeführten Außenwerte. Für vertrauliche
Daten wäre eine separate authentifizierte Schnittstelle erforderlich.

Maximal 24 vorbereitete Rezepte und acht Live-Felder je Feed; höchstens 4.096
Punkte und 1 MiB Antwort. Archivrezepte werden im HTTP-Pfad nicht berechnet.
LOOP-Zugriffe lesen höchstens 512 Pakete je Feld im gemeinsamen Seitenbudget.
Eine serverweite HTTP-Ratenbegrenzung gehört bei öffentlichem Betrieb an den
Reverse Proxy; `pollSeconds` ist ausdrücklich nur eine Client-Empfehlung.

## OWASP-Prüfung

| Kategorie | Ergebnis | Prüfung |
|---|---|---|
| A01 Zugriffskontrolle | PASS | Veröffentlichung nur per lokaler Freigabe; Feldauswahl begrenzt; keine privaten Metadaten automatisch; Methoden GET/HEAD/OPTIONS |
| A02 Kryptografie/Datenschutz | PASS | Keine Credentials im Browser oder Feed; HTTPS für Produktion dokumentiert; lokale Tests auf Loopback |
| A03 Injection/XSS | PASS | Kein SQL-/Shell-/Include-Pfad aus Requestdaten; JSON mit HEX-Escaping, nosniff; DOM-Ausgabe ausschließlich mit textContent; WP-Attribute maskiert; ECharts-RichText-Tooltips |
| A04 Unsicherer Entwurf | PASS | Feste Rezepte, Cache-only-Zugriff, Mengenlimits, explizite Messzeit und Quelle; Live ersetzt keine Archivmengen |
| A05 Fehlkonfiguration/XXE | PASS | Ohne Feed-Datei geschlossen; generische Fehler, Vary: Origin auch bei Fehlern; keine XML-Verarbeitung; DocumentRoot public/ |
| A06 Komponenten | PASS | ECharts 6.1.0 aus offiziellem Tag, Lizenz/NOTICE/Hash mitgeliefert; npm und Composer ohne bekannte Advisories |
| A07 Authentifizierung | N/A | Öffentliche Leseschnittstelle ohne Sessions, Cookies oder Browser-Schlüssel; bestehende Admin-/Ingest-Authentifizierung nicht geändert |
| A08 Daten-/Softwareintegrität | PASS | Keine untrusted PHP-Deserialisierung; JSON-Version geprüft; Modul-URLs nur HTTP(S), keine eingebetteten Zugangsdaten; Plugin-ZIP übernimmt exakt die gepflegten Assets |
| A09 Logging/Monitoring | PASS | Fehlerdetails ausschließlich im Serverlog; HTTP-Status für Zugriff-/Validierungsfehler; Workerdiagnostik bleibt intern; Webserver-Access-Logging für Betrieb dokumentiert |
| A10 SSRF | N/A | Keine serverseitigen Requests zu Widget-URLs; Datenabruf direkt im Browser, credentials: omit |

Keine offenen kritischen oder hohen Sicherheitsbefunde im geprüften Umfang.
Security Review Status: **PASS**.

## Prüfungen

- Frontend-PHP-Suite: **47 Tests, 285 Assertions**, PHP 8.1.34, erfolgreich.
  Enthält sieben API-Tests für erlaubte Felder, schädliche Parameter,
  CORS/Preflight, Cachemiss ohne Archivzugriff, 0 gegenüber null, ETag/HEAD,
  Live-Messzeit, Statuswechsel und Einheiten bei fehlendem Live-Journal.
- JavaScript: **vier Tests**, erfolgreich. Millisekunden, Kalenderdatum am
  Sommerzeitwechsel, unterbrochene kumulierte Mengen, gemeinsames Polling,
  ETags, Timeout, Wiederholung, Sichtbarkeit und idempotentes Aufräumen.
- WordPress: PHP-Syntax und eigenständiger Hook-/Escaping-Smoke-Test erfolgreich.
  Registrierung, Shortcode, klassisches Widget, Optionen, URL-Schemata,
  Modul-Tag und Schutz vor Attributinjektion geprüft. Kein vollständiger
  WordPress-Installationstest in dieser Umgebung durchgeführt.
- Browser: vier echte ECharts-Instanzen, Desktop und 390 px Mobilbreite;
  kein horizontaler Seitenüberlauf. Aufklappbare Tabelle mit 295 Datenzeilen
  bedienbar. Keine Warnungen/Fehler in der Browserkonsole.
- Zweite Origin: HTML auf Port 8088 liest API auf 8087; Widget mit gefilterten
  Feldern sichtbar. HTTP GET 200, bedingtes GET 304, OPTIONS 204 und
  nicht freigegebenes Feld 400 nachgewiesen.
- WeeWX-Konformität: alle ausgeführten Prüfungen erfolgreich, einschließlich
  Frontend-Aggregaten und Test mit 1.051.776 Archivzeilen. Optionale
  weewx-evo-Uploadvergleiche mangels eingebundener Quellen ausgelassen.
- PHP-CS-Fixer für die zehn PHP-Dateien dieses Umfangs: erfolgreich.
- PHPStan auf maximaler Stufe für das gesamte Projekt: erfolgreich.
- npm-Audit der gepinnten ECharts-Abhängigkeiten: **0 Schwachstellen**.
  Composer-Audit der vorhandenen Entwicklungsabhängigkeiten: keine Advisories.
- Cookbook-Dateilinks und identische gemeinsame Assets im Plugin-ZIP geprüft.

## Gleichzeitige Änderungen im Arbeitsverzeichnis

Während dieser Arbeit wird der Ingest-/Replay-Bereich unabhängig weiterentwickelt.
Der letzte Gesamtlauf hatte 336 Tests und 3.584 Assertions; ein Fehler trat in
`NativeReplayTest::testLiveDeliveryIsProtectedWhenTheScheduledTickStopsForDays`
auf: MappingError für das automatisch erzeugte Testarchiv bei `Archiver::open()`.
Dieser Test und die dazugehörigen Ingest-Dateien wurden hier nicht geändert.

Der vollständige Formatierungslauf meldete außerdem Unterschiede in
`CollectorCommand.php`, `Archiver.php`, `CollectorStore.php`, `ReplayStore.php`
und `NativeReplayTest.php`. Die oben genannte Prüfung des Cookbook-/API-Umfangs
ist davon unabhängig erfolgreich. Der Gesamtstand ist damit bei diesem Lauf
nicht als vollständig grün ausgewiesen.

## Lokale Demo

Die Galerie verwendet das bestehende Archiv der Demo-Konfiguration unverändert.
Nur der separate Analytics-Cache und eine explizite lokale Feed-Datei wurden
für die Vorschau eingerichtet. Die Quelle enthält einige Augusttage 2026,
keine vollständigen historischen Vergleichsjahre und keinen aktiven LOOP-Eingang.
Fehlende Livewerte/Vergleiche werden angezeigt, nicht durch Beispieldaten ersetzt.
Der temporäre zweite Testserver wurde nach dem Cross-Origin-Test beendet.
