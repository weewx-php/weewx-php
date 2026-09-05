<?php

declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Theme-Cookbook · Beispiele</title>
    <link rel="stylesheet" href="assets/cookbook.css">
    <script defer src="assets/vendor/echarts/echarts.min.js"></script>
    <script type="module" src="assets/cookbook.js"></script>
    <script type="module" src="assets/weather-widget.js"></script>
</head>
<body data-api="api/v1.php?feed=charts">
<header><a href="./">Wetterstation</a><span>Theme-Cookbook</span><a href="api/v1.php?feed=charts">JSON-API</a></header>
<main>
    <h1>Wetterdaten mit Apache ECharts</h1>
    <p id="chart-status" role="status">Laden …</p>
    <div class="charts">
        <section><h2>Temperatur &amp; Luftfeuchte</h2><p data-status="temperature24h"></p><div data-chart="temperature" class="chart" role="img" aria-label="Temperatur und Luftfeuchte der letzten 24 Stunden"></div></section>
        <section><h2>Niederschlag · 7 Kalendertage</h2><p data-status="rain7d"></p><div data-chart="rain" class="chart" role="img" aria-label="Niederschlag pro Kalendertag"></div></section>
        <section><h2>Niederschlag · kumuliert über 24 h</h2><p data-status="rain24h"></p><div data-chart="cumulative" class="chart" role="img" aria-label="Kumulierte Niederschlagsmenge; endet an Datenlücken"></div></section>
        <section><h2>Monat im Vergleich zu Vorjahren</h2><p data-status="rainComparison"></p><div data-chart="comparison" class="chart" role="img" aria-label="Monatsniederschlag der Vorjahre bis zur gleichen Kalenderzeit"></div></section>
    </div>
    <section class="embed"><h2>Sidebar-Widgets</h2><div class="widgets">
        <weewx-weather api="api/v1.php?feed=sidebar" title="Letzte Archivmessung"></weewx-weather>
        <weewx-weather api="api/v1.php?feed=live" title="Live"></weewx-weather>
    </div></section>
    <details><summary>Diagrammdaten als Tabelle</summary><div class="table-scroll"><table id="chart-table"><thead><tr><th>Serie</th><th>Beginn</th><th>Ende</th><th>Wert</th><th>Einheit</th><th>Abdeckung</th></tr></thead><tbody></tbody></table></div></details>
    <noscript>JavaScript ist für Diagramme und Widgets erforderlich.</noscript>
</main>
</body></html>
