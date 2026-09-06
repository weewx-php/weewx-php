# Wettervorhersage als Erweiterung

Die Vorhersage liegt im eigenen Repository
[extension-forecast](https://github.com/weewx-php/extension-forecast).
Im Admin unter **Erweiterungen** installieren, unter **Forecast** konfigurieren
und aktivieren. Einstellungen können pro Archiv abweichen. Das Paket benötigt
Erweiterungs-API 2 und hängt sich in den bestehenden Tick ein.

```php
if ($wx->hasTag('forecast.status')) {
    $status = $wx->tag('forecast.status');
    $today = $wx->tag('forecast.day', ['index' => 0]);
    $hours = $wx->tag('forecast.hourly', ['observation' => 'outTemp', 'hours' => 48]);
}
```

Das Demo-Theme liest diese Tags und behält seine Vorhersagedarstellung.
Provider, Cache, Abruf und Auswertung gehören vollständig zum Paket.
Im Core gibt es keinen Vorhersageabschnitt, keinen `forecast`-CLI-Befehl und
keine `$wx->forecast()`-Methode mehr. Diagnose: `extensions tags`, `extensions run`.

Für vorhandene Installationen enthält das Paket `tools/migrate.php`. Es übernimmt
die alten Archiveinstellungen und gültige Caches nach ausdrücklichem Aufruf.
Vor dem Aufruf keine neuen Forecast-Optionen speichern.
[Einrichtung, Migration und Tag-Vertrag](https://github.com/weewx-php/extension-forecast#readme).
