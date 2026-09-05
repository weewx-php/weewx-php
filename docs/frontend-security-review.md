# Sicherheitsprüfung der Frontend-Schicht, 2026-09-05

Umfang: Frontend-Rezepte, Archivleser, Analytics-Cache/Worker, Änderungsmarkierungen,
CLI, Ausgabehelfer und Astronomie-Port. Grundlage: `security-review`-Skill und
OWASP-Prüfliste. Kein Deployment und kein PR.

| Bereich | Prüfung |
|---|---|
| Injection | Bezeichner streng validiert/gequotet; Werte gebunden; Aggregatnamen auf Katalog begrenzt. Keine Shell-, LDAP-, XPath- oder SQL-Ausdrücke aus Eingaben. |
| Authentifizierung | Keine neuen öffentlichen Endpunkte/Anmeldeverfahren. Bestehender Tick-Zugang bleibt maßgeblich. |
| Daten/Geheimnisse | Wetterdaten, Rezepte und Konfigurations-Hashes im Cache; keine Tokens/Passwörter. Datenverzeichnis außerhalb des Webroots. |
| XML/XXE | Keine XML-Verarbeitung hinzugefügt. |
| Zugriffskontrolle | SQLITE3_OPEN_READONLY; Archivwahl aus Konfiguration. `data.php` ist lokale vertrauenswürdige Theme-Software. |
| Fehlkonfiguration | Keine neuen externen Dienste oder Runtime-Installationen. Fehlerdarstellung liegt beim Theme. |
| XSS | Wertausgabe HTML-escaped; JSON maskiert HTML-relevante Zeichen. `format()` liefert bewusst reinen Text. |
| Deserialisierung | JSON mit Tiefenlimit und geprüften Rezepttypen; kein `unserialize` oder `eval`. Feste lokale Astronomiedateien. |
| Abhängigkeiten | `composer audit --locked --no-interaction --format=json`: keine Advisories, keine aufgegebenen Pakete, Exit 0. Git meldete beim Container-Mount einen Eigentümerhinweis. Keine Composer-Laufzeitpakete hinzugefügt. |
| Protokollierung | Bestehender Logger, `analytics status`, Wiederholungsfrist bei Fehlern. |
| Ressourcen | Begrenzte Quellabfragen, Seitenbudget, Serien-/Rezeptlimits. Persistierte Blöcke haben noch keine Speicherquote. |
| Konsistenz | Tick-Lock auch für Catchup/Rebuild; dauerhafte Änderungsmarkierung; revisionsgeprüfte Veröffentlichung in kurzer Cache-Transaktion; Wiederaufnahme unterbrochener Jobs. |

Tests prüfen Bezeichnerinjektion, übergroße Formate, Skript-JSON, Budgetgrenzen,
Fremdkorrekturen, Nachtintervalle, Wiederaufnahme ohne Doppelzählung und gemeinsam
genutzte historische Blöcke. Keine offenen kritischen oder hohen Befunde im
geprüften Änderungsumfang. Beliebige eigene PHP-Themes und fremde Dateien sind
damit nicht automatisch geprüft.

Abschlussprüfung: 256 PHP-Tests mit 1.459 Assertions bestanden; Formatprüfung und
PHPStan auf maximaler Stufe ohne Fehler. Alle Conformance-Prüfungen bestanden,
einschließlich 128 Frontend-Aggregatvergleichen, 28 Kalendergrenzen, 2.072
Astronomiefällen und dem Archiv mit mehr als einer Million Fünfminutensätzen.
