# Anforderungen aus der WeeWX-Community

Recherche: 5. September 2026. Grundlage sind gezielt ausgewählte öffentliche
Usergroup-Beiträge, GitHub-Issues samt Antworten und die Dokumentation mehrerer
Skins. Dies ist eine qualitative Stichprobe, keine repräsentative Nutzerumfrage
und keine Rangliste nach Installationszahlen. Ältere und geschlossene Fehler
dienen als Anwendungsfälle, nicht als Behauptung über aktuelle Versionen.

## Beobachtungen und Ableitungen

| Beleg | Beobachtung | Konsequenz für unsere Schicht |
|---|---|---|
| [Belchertown #924](https://github.com/poblabs/weewx-belchertown/issues/924), Januar 2024, geschlossen | Mehrere Nutzer mit alten Datenbankschemata betroffen: abgeleitete Messwerte wurden während der Berichtserzeugung berechnet; der Autor berichtet von einem nach 600 Sekunden abgebrochenen Lauf. | Schema und Ableitbarkeit vorab prüfen; teure Ableitungen im Worker vorbereiten; Kosten und fehlende Voraussetzungen anzeigen. |
| [Usergroup: historische Diagramme mit Lücken](https://groups.google.com/g/weewx-user/c/XYTvS6lA1Z0), Oktober 2021 | November-/Dezemberwerte erschienen in Januar-/Februarkategorien, wenn frühere Monate fehlten. | Gemeinsames Kalenderraster mit expliziten Zeitgrenzen und null; nie Kategorien aus der Position der vorhandenen Werte ableiten. |
| [NeoWX #88, Lösungsbericht](https://github.com/neoground/neowx-material/issues/88#issuecomment-2804120751), April 2025 | Sehr kleine ET-Einzelwerte erschienen als 0,0; ein Nutzer beschreibt die Umstellung auf Periodensummen. | Darstellungsaggregation an Messwertart koppeln: Intervallmengen summieren, Zustände zeitlich mitteln, Zähler gesondert behandeln. Erst für die Ausgabe runden. |
| [NeoWX #93](https://github.com/neoground/neowx-material/issues/93), Oktober 2024 | Eigene Luftqualitätssensoren erscheinen trotz Formatvorgaben mit sechs Nachkommastellen. | Ein gemeinsames Ausgabeprofil für HTML, Tabellen und Diagramme, einschließlich eigener Messgrößen. |
| [NeoWX #104 samt Antworten](https://github.com/neoground/neowx-material/issues/104), März 2026 | Uneinheitliche Sensor-/Diagrammbezeichner erschwerten die Bodenfeuchteanzeige. In den Antworten wird eine Behebung im seehase-Fork berichtet und vom Fragesteller bestätigt. | Ein Messwertkatalog und dieselben Serienrezepte auf allen Seiten; Vorabprüfung statt still verschwundener Diagramme. |
| [Usergroup: zweite Datenquelle](https://groups.google.com/g/weewx-user/c/0hTWSxyolXI), Mai 2026 | Innenwerte aus einer zweiten Instanz waren als Einzelwerte sichtbar, die Diagramme fehlten. Der Maintainer verweist auf einen Seasons-Fehler; der Nutzer bestätigt den Workaround. | Archivwahl für Wert und Serie identisch; mehrere Archive auf gemeinsame Zeitpunkte ausrichten können. |
| [NeoWX #97](https://github.com/neoground/neowx-material/issues/97), Februar 2025 | Expliziter Wunsch nach automatischer Aktualisierung für Kioskbildschirme. | Aktualisierbare Datensätze und Datenalter liefern; vorhandene Live-Daten anbinden. Transport und Seitenlayout getrennt halten. |
| [Usergroup: letzter Regen](https://groups.google.com/g/weewx-user/c/s7im4XEckDk), März 2021, und [Tage seit letztem Regen](https://groups.google.com/g/weewx-user/c/1vtUflRv5ys), ab August 2014 | Nutzer suchen Zeitpunkt, vergangene Dauer und längste Trockenperiode. Eine Antwort beschreibt Tageszusammenfassungen zur Eingrenzung des anschließenden Archivzugriffs. | Ereignissuche und zusammenhängende Zeiträume als eigene cachebare Rezepte mit Schwellen- und Lückenregeln. |
| [Usergroup: JAS](https://groups.google.com/g/weewx-user/c/C-sWs_zMQBM/m/qFCh8XywAwAJ), 2022 | Explizites Interesse an überlagerten Jahren/Monaten auf einfachem Shared Hosting; auch übertragene Datenmenge wird diskutiert. | Kalenderausrichtung, Auflösung und Punktlimit im Datenvertrag festlegen; historische Vergleiche vorbereiten. |
| [WeeWX #877](https://github.com/weewx/weewx/issues/877), Juli 2023, geschlossen | Eine Verfügbarkeitsprüfung erkannte berechenbare XTypes ohne Archivspalte nicht. | Existierende Spalte, grundsätzlich berechenbarer Wert und tatsächlich verfügbare Messung unterscheiden. |
| [WeeWX #867](https://github.com/weewx/weewx/issues/867), Mai 2023, geschlossen | Wunsch nach unterschiedlicher Locale je Bericht. | Sprache und Zahlen-/Zeitformat pro Theme oder Ausgabeprofil; keine globale Änderung durch ein Theme. |

Die ursprünglichen Issue-Texte #924, #88, #93, #97 und #104 sowie die Antworten
zu #88 und #104 wurden zusätzlich über die öffentliche GitHub-API gelesen.
Die Suchansicht allein zeigt nicht zuverlässig, ob ein Fork einen Fehler bereits
behoben hat. Aktuelle eigene Projektvorschläge des Accounts `hilman2` wurden
nicht als unabhängiger Beleg für Community-Bedarf gezählt.

## Input aus Skins und Erweiterungen

| Projekt | Für die Abstraktionsschicht relevant |
|---|---|
| [Belchertown](https://github.com/poblabs/weewx-belchertown) und [New Belchertown](https://github.com/uajqq/weewx-belchertown-new) | Live-Aktualisierung, frei konfigurierte Diagramme, Rekorde und Kioskansichten. |
| [NeoWX, seehase-Fork](https://github.com/seehase/neowx-material) | Mehrere Achsen, Telemetrie/Batterie, sensorabhängige Auflösung und einheitenabhängige Trendanzeigen. |
| [JAS, aktuelles Repository](https://github.com/weewx-extensions/jas) | Daten für Diagramme und Tabellen; historische Daten müssen nicht bei jedem Archivintervall neu erzeugt werden. |
| [AganetWX](https://github.com/aganet/weewx-aganetwx) | Entdeckt zusätzliche Sensoren. Jahresvergleiche werden standardmäßig täglich zwischengespeichert; Monatsrekorde verwenden dieselbe Aggregation. Bei geänderten Einstellungen kann eine manuelle Cache-Erneuerung nötig sein. Das spricht für versionierte Theme-Registrierungen bei uns. |
| [time_since](https://github.com/tkeffer/weewx-time_since) | Liefert letzten Zeitpunkt und vergangene Dauer für Bedingungen. Bei uns sollten geprüfte Bedingungen an die Stelle freier SQL-Ausdrücke treten. |
| [GTS](https://github.com/roe-dl/weewx-GTS) | Zusätzliche Fachrezepte für Solarenergie, Vegetationssummen, Verdunstung und abweichende Tagesgrenzen. Beleg für spezialisierte Anwendungen, kein Nachweis einer Mehrheitsanforderung. |

## Vorschlag für die Umsetzung

Die folgenden Punkte sind unsere Ableitung aus den Quellen, noch keine neue API.

1. Vorhandenen Messwertkatalog bis ins Frontend durchreichen: Einheit, Bedeutung,
   Format, zulässige Aggregate, Abhängigkeiten, Verfügbarkeit und Datenalter.
   Eigene Sensoren brauchen dieselbe Behandlung wie Standardwerte.
2. Einheitlichen Datensatz für PHP-Ausgabe, Diagramm und Aktualisierung definieren.
   Die vorhandene Live-Datenbank anbinden; Live-Wert, Archivwert und letzter
   gültiger Messwert bleiben explizit unterscheidbar. Für Shared Hosting genügt
   zunächst begrenztes JSON-Polling. Live- und Archivüberlappungen dürfen weder
   Regen doppelt zählen noch Tagesextreme verlieren.
3. Serien auf ein gemeinsames Raster bringen: Kalendergrenzen, Lücken, Abdeckung,
   mehrere Messgrößen/Archive und geeignete Auflösung. Rendering und Auswahl
   einer Diagrammbibliothek bleiben beim Theme.
4. Ereignisse und Vergleiche ergänzen: letzter Regen, Trockenperioden, Zeit über
   Schwellen, Monatsstände bis zum selben Kalendertag und Jahresüberlagerung.
   Vergleichsbasen schließen unvollständige Zeiträume nach einer sichtbaren
   Regel aus. Sonderfall 29. Februar ausdrücklich festlegen.
5. Theme-Registrierungen abgleichen und vorbereiten; Schema-/Rezeptänderungen
   versionieren. Bestehendes Seitenbudget und historischen Blockcache ausbauen,
   inklusive Diagnose und nachvollziehbarer Aktualisierungstakte.

Für jede Stufe das Demo-Theme als Abnehmer verwenden. Besonders geeignete
Prüffälle: Sensor ohne Messung, echtes Regen-null versus Regen-0, ET-Summe,
gemischte Archivintervalle, Sommerzeit, November als erster vorhandener Monat,
zwei Archive mit unterschiedlichem Datenstand und Ausfall des Live-Senders.

Vorhersagen und externe Wetterdienste sind in mehreren Skins vorhanden. Dafür
später einen separaten Anbieterzugang mit Herkunft und eigenem Aktualisierungstakt
vorsehen. Die vorhandenen Astronomieberechnungen bleiben die gemeinsame Basis.
