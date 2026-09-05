# WeeWX PHP Weather

Den Inhalt der erzeugten ZIP-Datei als Plugin installieren und aktivieren.
Benötigt WordPress ab 5.8, PHP ab 7.4 und einen Browser mit ES-Modulen,
Custom Elements und Shadow DOM.

## Sidebar und Beiträge

Im Widget-Editor einen **Shortcode-Block** einsetzen:

```text
[weewx_weather api="https://wetter.example.org/api/v1.php?feed=live" fields="temperature,humidity,wind" title="Wetter am Haus"]
```

Für klassische Sidebars steht auch **WeeWX Wetter** mit denselben drei
Einstellungen zur Verfügung. Leere Felderauswahl zeigt alle Einzelwerte des Feeds.
Mehrere Widgets teilen sich bei identischer API-URL eine Abfrage.

`feed=live` zeigt ausschließlich LOOP-Messungen. `feed=sidebar` im Cookbook
zeigt die letzte vorbereitete Archivmessung. Fehlt ein Live-Journal, erscheint
„Keine Daten“. Es gibt keinen stillen Rückfall auf Archivwerte.

## Einrichtung

1. Den Feed auf dem Wetterserver veröffentlichen; siehe `docs/theme-cookbook.md`.
2. Die WordPress-Origin dort exakt freigeben, z. B. `https://blog.example.org`,
   oder den Feed ausdrücklich mit `origins: ['*']` öffentlich einbetten lassen.
3. Die vollständige HTTPS-API-URL in Widget oder Shortcode eintragen.

Der Browser lädt die Wetterdaten direkt. Es gibt keinen WordPress-Cronjob,
kein Server-Proxy und keinen API-Schlüssel im HTML. Seiten-Caches dürfen das
Widget-HTML speichern; die Messwerte aktualisieren sich unabhängig davon.
Ein CSP-Plugin muss die Wetter-Origin unter `connect-src` erlauben. JavaScript
und CSS liegen lokal im Plugin; keine ECharts-Abhängigkeit für das Widget.

Fehler erhalten die letzten angezeigten Daten und ergänzen „Verbindung
unterbrochen“. Zeitstempel und Status bleiben sichtbar. Unsichtbare Tabs
pausieren, Fehler verzögern Wiederholungen; normale Abfragen folgen `pollSeconds`.

## Gestaltung

```css
weewx-weather {
  --weather-background: #ffffff;
  --weather-text: #183e37;
  --weather-muted: #52665f;
  --weather-border: #d6e1dc;
}
```

## Paket erstellen

Im Projektverzeichnis `python examples/wordpress/package.py` ausführen.
Das Skript übernimmt die gemeinsamen Widget-Assets unverändert und erzeugt
`data/artifacts/weewx-weather.zip`. Keine Node- oder Composer-Installation nötig.
