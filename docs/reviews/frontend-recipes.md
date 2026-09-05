# Frontend-Erweiterungen: Prüfung

Stand: 5. September 2026. Implementierung zu
[Community-Anforderungen](../frontend-community-research.md) und den sechs
vereinbarten Verbesserungen. Verwendung: [Frontend-Rezepte](../frontend-recipes.md).

## Umgesetzter Umfang

| Bereich | Ergebnis |
|---|---|
| Zentrale Ausgabe | Theme-spezifisches Ausgabeprofil, Einheitengruppen und eigene Messgrößen, sichere Platzhalter, skalare und serielle Umrechnung einschließlich Temperaturdifferenzen |
| Zeitbezug | Archiv, Uhrzeit oder fester Zeitpunkt; Kalenderdaten gegenüber festen Dauern; gleiche Wahl für Astronomie |
| Theme-Verwaltung | Atomarer Abgleich, gemeinsamer Besitz, Freigabe exklusiver Registrierungen, CLI-Preflight und Vorbereitung |
| Vorbereitete Analysen | Monatsstände, qualifizierte Ranglisten, Trockenperioden, Ereignisse und genaue Quantile von Intervallaggregaten; mit Ausschlussgründen und Vergleichsjahren |
| Gemeinsame Berechnung | Exakte zusammenführbare Zustände für sichere skalare Aggregate; getrennte Rohdaten-/Tagesgewichtung; korrigierbare geschlossene Blöcke |
| Betrieb | Cache-only-Instanzen, Priorität und Alterung, fortsetzbarer Worker, Abfrage- und Workerdiagnose |
| Community-Ergänzungen | Messwertkatalog und Verfügbarkeit, semantische Serienstandards, gemeinsames Raster und Jahresüberlagerung, explizites Live-Journal und fester JSON-Datensatz |

Es gibt keine neue Dienst-Abhängigkeit. Der bestehende Tick bearbeitet die
Analytics-Datenbank. Öffentliche Seiten lösen keine Analyse-Nachbearbeitung aus.
Die fachlichen Grenzen zu Live-Ableitungen, Ereignisauflösung und Quantilpopulation
sind in der Anleitung benannt.

## Funktion und Leistung

- Vollständiger Unit-Testlauf unter PHP 8.1.34: 315 Tests erfolgreich.
- Frontend-Regressionsfälle: Ausgabeprofile, US-/metrische Einheiten, Pending,
  Delta-Umrechnung, Sommerzeit/Mitternacht, variable Archivintervalle,
  ET-Summen, Null-Lücken, Theme-Besitz und Rücknahme, geschlossene Zustände,
  nachträgliche Archivkorrektur, trockene/nasse Intervalle, Vergleichsjahre,
  Schaltjahrausschlüsse, Quantile, zwei Archive, Live-Ausfall und letzter gültiger Wert.
- PHPStan auf höchster Projektstufe: keine Fehler.
- WeeWX-Konformität: alle ausgeführten Checks erfolgreich, darunter 128
  Frontend-Aggregate und 28 Kalendergrenzen. Die optionalen weewx-evo-Vergleiche
  für Windy/Weathercloud/InfluxDB/MQTT wurden mangels eingehängter Quellen ausgelassen.
- Zehnjahresarchiv: 1.051.776 Archivzeilen und 3.652 Tageszusammenfassungen.
  Gemessener kalter Summenzugriff 57,1 ms, begrenzter Rohdaten-Miss 25,3 ms,
  warmer Zugriff 0,3 ms bei null Archiv-Lesebudget. Tagesrangliste über alle
  3.652 Tage: 30 fortgesetzte Workerläufe, zusammen 1.018 ms. Dies sind lokale
  Messungen in der Testumgebung, keine Laufzeitgarantie für jeden Webhost.
- Lokales Demo: Desktop und 390-Pixel-Mobileinstellung geprüft; kein
  horizontaler Überlauf, sieben Regenbalken, keine Browserfehler.
- JSON-Endpunkt: GET 200, sieben Tagesbalken und 168 Stundenpunkte bei `range=7d`;
  POST 405. Der vorherige Viewport wurde wiederhergestellt.

## Security Review

**Security-Sensitive:** YES. Review durch den implementierenden Agenten nach
`C:/Users/manuel/.agents/skills/security-review/SKILL.md`. Alle zehn Kategorien
geprüft; keine offenen hohen oder kritischen Befunde.

| Kategorie | Ergebnis |
|---|---|
| Injection | SQL-Werte gebunden; dynamische Messwert-/Tabellennamen durch vorhandenen Identifier-Prüfer begrenzt. Bedingungen, Prioritäten, Intervalle und Rezeptarten auf Positivlisten. Keine freien SQL-Rezepte. |
| Authentifizierung | Kein neuer Login oder Credential-Lebenszyklus. Diagnose/Manifestverwaltung nur lokale PHP-/CLI-Schnittstellen. |
| Vertrauliche Daten | JSON enthält den festen Wetterdatensatz, keine Rohpakete, Sender-Identitäten, Konfiguration, Pfade, Zugangsdaten oder Diagnoselogs. Generische HTTP-Fehlermeldungen. |
| XML/XXE | Kein XML-Parser oder DTD-Zugriff hinzugefügt. |
| Zugriffskontrolle | HTTP wählt ausschließlich die feste Demo-Definition; keine vom Client wählbaren Dateipfade oder Archive. Live-Mapping berücksichtigt die konfigurierten Sender. Nur GET am neuen Endpunkt. |
| Konfiguration | CSP auf eigene Styles/Scripts/Verbindungen begrenzt; nosniff, no-store. Archiv und Live-Journal nur lesend; Analytics separat. |
| XSS | Value/Theme-HTML wird escaped, JSON nutzt HEX-Flags; Polling setzt textContent, kein innerHTML. |
| Deserialisierung | JSON mit begrenzter Tiefe und geprüften Rezept-/Ergebnisstrukturen; kein unserialize. Theme-PHP stammt ausschließlich aus vertrauenswürdigen lokalen Dateien. |
| Komponenten | Keine zusätzliche Laufzeitabhängigkeit. `composer audit --locked --working-dir=/opt/build` einschließlich Entwicklungspaketen: keine bekannten Sicherheitsmeldungen. |
| Logging/Monitoring | Workerfehler und nächste Versuche gespeichert; Schritte zeigen Quellen, Cachewiederverwendung und Budgetverbrauch. HTTP meldet Details nur im Serverlog. |

Zusätzliche Prüfung der Betriebsgrenzen: keine gleichzeitigen Browser-Polls,
acht Sekunden Request-Timeout, Pause im versteckten Tab; feste Abfrage-/Punktlimits;
Worker veröffentlicht nicht während einer markierten Archivmutation oder bei
geändertem Quelltoken. Gemeinsam verwendete Registrierungen werden beim
Theme-Abgleich nicht entfernt. Cache-State-Zusammenführung akzeptiert nur eine
exakte disjunkte Partition und verwendet keine gemittelten Mediane.

**Security Review Status:** PASS.
