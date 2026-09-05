<?php

declare(strict_types=1);

use WeewxPhp\DemoTheme\View;

/** @var View|null $view */
$title = $view === null ? 'Wetterstation' : $view->name;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= View::escape($title) ?> · Wetter</title>
    <link rel="stylesheet" href="assets/demo.css">
    <script src="assets/demo.js" defer></script>
</head>
<body>
<a class="skip" href="#weather">Zum Wetter</a>
<div class="page">
    <header class="site-header">
        <a class="brand" href="?range=24h" aria-label="Wetterübersicht">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="6"/><path d="M16 2v4m0 20v4M2 16h4m20 0h4M6 6l3 3m14 14 3 3M6 26l3-3M23 9l3-3"/></svg></span>
            <span>Wetter<span class="brand-sub">weewx-php</span></span>
        </a>
        <span class="theme-name">Demo-Theme</span>
    </header>
    <main id="weather">
    <?php if ($view === null): ?>
        <section class="empty-page"><p class="eyebrow">Wetterstation</p><h1>Keine Wetterdaten</h1><p>Konfiguration nicht verfügbar.</p></section>
    <?php else: ?>
        <?php
        $chart = $view->chart();
        $rain = $view->points('rain7d', 'mm');
        $rainMax = 1.0;
        foreach ($rain as $point) {
            $rainMax = max($rainMax, $point['value'] ?? 0);
        }
        $day = $view->date($view->updated, 'd.m.Y');
        ?>
        <div class="page-title">
            <div><p class="eyebrow">Wetterstation</p><h1><?= View::escape($title) ?></h1></div>
            <div class="archive-time"><span class="quiet">Letzte Messung</span><time><?= View::escape($view->date($view->updated)) ?></time></div>
        </div>
        <p class="notice" data-refresh-status role="status"<?= $view->status === '' ? ' hidden' : '' ?>><?= View::escape($view->status) ?></p>
        <p class="quiet" data-live<?= $view->liveTemperature->raw === null ? ' hidden' : '' ?>>Live: <?= $view->liveTemperature->html() ?><?= $view->liveTemperature->status === 'stale' ? ' · veraltet' : '' ?></p>

        <section class="overview" aria-label="Letzte Messwerte">
            <div class="temperature-card">
                <span class="eyebrow">Außentemperatur</span>
                <div class="temperature"><?= $view->number('temperature') ?><span>°C</span></div>
                <div class="extremes"><span>Min. <strong><?= $view->number('low') ?>°</strong></span><span>Max. <strong><?= $view->number('high') ?>°</strong></span><span class="measurement-day"><?= View::escape($day) ?></span></div>
            </div>
            <dl class="metrics">
                <div><dt>Luftfeuchte</dt><dd><?= $view->number('humidity') ?><span>%</span></dd></div>
                <div><dt>Wind</dt><dd><?= $view->number('wind') ?><span>km/h</span></dd></div>
                <div><dt>Luftdruck</dt><dd><?= $view->number('pressure') ?><span>hPa</span></dd></div>
            </dl>
        </section>

        <div class="content-grid">
            <section class="panel temperature-panel" aria-labelledby="temperature-title">
                <div class="panel-heading"><div><p class="eyebrow">Verlauf</p><h2 id="temperature-title">Temperatur</h2></div>
                    <nav class="range" aria-label="Zeitraum">
                        <a href="?range=24h"<?= $view->range === '24h' ? ' aria-current="true"' : '' ?>>24 Stunden</a>
                        <a href="?range=7d"<?= $view->range === '7d' ? ' aria-current="true"' : '' ?>>7 Tage</a>
                    </nav>
                </div>
                <?php if ($chart['paths'] === []): ?>
                    <div class="chart-empty">Keine Verlaufsdaten</div>
                <?php else: ?>
                    <svg class="temperature-chart" viewBox="0 0 780 256" role="img" aria-labelledby="chart-title chart-description">
                        <title id="chart-title">Temperatur in Grad Celsius</title>
                        <desc id="chart-description"><?= View::escape($view->date($chart['start']) . ' bis ' . $view->date($chart['end'])) ?>. Die Messwerte stehen unter dem Diagramm.</desc>
                        <?php for ($i = 0; $i < 4; ++$i): $y = 30 + $i * 60; ?>
                            <line class="grid-line" x1="48" x2="752" y1="<?= $y ?>" y2="<?= $y ?>"/>
                            <text class="axis-label" x="34" y="<?= $y + 4 ?>" text-anchor="end"><?= number_format($chart['high'] - $i * ($chart['high'] - $chart['low']) / 3, 0, ',', '.') ?>°</text>
                        <?php endfor; ?>
                        <?php foreach ($chart['paths'] as $path): ?><path class="temperature-line" d="<?= View::escape($path) ?>"/><?php endforeach; ?>
                        <?php for ($i = 0; $i < 5; ++$i): $time = (int) (($chart['start'] ?? 0) + (($chart['end'] ?? 0) - ($chart['start'] ?? 0)) * $i / 4); ?>
                            <text class="axis-label" x="<?= 48 + $i * 176 ?>" y="243" text-anchor="<?= $i === 0 ? 'start' : ($i === 4 ? 'end' : 'middle') ?>"><?= View::escape($view->date($time, $view->range === '7d' ? 'd.m.' : 'H:i')) ?></text>
                        <?php endfor; ?>
                    </svg>
                    <details class="chart-data"><summary>Messwerte</summary><div class="table-scroll"><table><caption>Temperatur · <?= $view->range === '24h' ? '15-Minuten-Mittel' : 'Stundenmittel' ?></caption><thead><tr><th scope="col">Zeit</th><th scope="col">°C</th></tr></thead><tbody>
                        <?php foreach ($chart['points'] as $point): ?><tr><td><?= View::escape($view->date($point['end'])) ?></td><td><?= $point['value'] === null ? '—' : number_format($point['value'], 1, ',', '.') ?></td></tr><?php endforeach; ?>
                    </tbody></table></div></details>
                <?php endif; ?>
            </section>

            <section class="panel rain-panel" aria-labelledby="rain-title">
                <div class="panel-heading"><div><p class="eyebrow"><?= View::escape($day) ?></p><h2 id="rain-title">Niederschlag</h2></div><span class="rain-symbol" aria-hidden="true">↧</span></div>
                <div class="rain-total"><?= $view->number('rainDay') ?><span>mm</span></div>
                <dl class="rain-totals"><div><dt>Monat</dt><dd><?= $view->number('rainMonth') ?> <span>mm</span></dd></div><div><dt>Jahr</dt><dd><?= $view->number('rainYear') ?> <span>mm</span></dd></div></dl>
                <h3>7 Kalendertage</h3>
                <?php if ($rain === []): ?><p class="quiet">Keine Verlaufsdaten</p><?php else: ?>
                <svg class="rain-chart" viewBox="0 0 320 130" role="img" aria-labelledby="rain-chart-title">
                    <title id="rain-chart-title">Tagesniederschlag in Millimetern</title>
                    <?php foreach ($rain as $i => $point): $width = 300 / count($rain);
                        $height = $point['value'] === null ? 0 : max(2, $point['value'] / $rainMax * 65); ?>
                        <rect class="rain-bar<?= $point['value'] === null ? ' rain-gap' : '' ?>" x="<?= sprintf('%.2F', 10 + $i * $width + 6) ?>" y="<?= sprintf('%.2F', 88 - $height) ?>" width="<?= sprintf('%.2F', $width - 12) ?>" height="<?= sprintf('%.2F', $height) ?>" rx="3"/>
                        <text class="rain-label" x="<?= sprintf('%.2F', 10 + ($i + 0.5) * $width) ?>" y="<?= sprintf('%.2F', 78 - $height) ?>" text-anchor="middle"><?= $point['value'] === null ? '—' : number_format($point['value'], 1, ',', '.') ?></text>
                        <text class="axis-label" x="<?= sprintf('%.2F', 10 + ($i + 0.5) * $width) ?>" y="115" text-anchor="middle"><?= View::escape($view->date($point['start'], 'd.m.')) ?></text>
                    <?php endforeach; ?>
                </svg>
                <?php endif; ?>
            </section>
        </div>

        <?php
        $comparison = $view->report('rainComparison');
        $wettest = $view->report('wettestMonth');
        $dry = $view->report('drySpell');
        $record = $wettest->periods->points[0] ?? null;
        ?>
        <div class="records-grid">
            <section class="panel record-panel">
                <h2>Monatsvergleich</h2>
                <dl><div><dt>Gleicher Monatsstand · frühere Jahre</dt><dd><?= $comparison->value('percentOfMean')->html() ?></dd></div>
                    <div><dt>Referenzjahre</dt><dd><?= $comparison->referenceYears() === [] ? '—' : View::escape(implode(', ', $comparison->referenceYears())) ?></dd></div></dl>
            </section>
            <section class="panel record-panel">
                <h2>Nassester Monat</h2>
                <dl><div><dt><?= View::escape($view->date($record['start'] ?? null, 'm.Y')) ?></dt><dd><?= $wettest->periods->value(0)->html() ?></dd></div>
                    <div><dt>Mindestabdeckung</dt><dd>95 %</dd></div></dl>
            </section>
            <section class="panel record-panel">
                <h2>Längste Trockenperiode</h2>
                <dl><div><dt>Vollständig gemessene Tage ohne Regen</dt><dd><?= $dry->value('intervals')->html() ?></dd></div>
                    <div><dt>Beginn</dt><dd><?= $dry->value('start')->html('d.m.Y') ?></dd></div></dl>
            </section>
        </div>

        <section class="sun-strip" aria-label="Sonnenzeiten heute">
            <div><span class="sun-icon" aria-hidden="true">☀</span><h2>Sonne <span><?= View::escape($view->date(time(), 'd.m.Y')) ?></span></h2></div>
            <dl><div><dt>Aufgang</dt><dd><?= View::escape($view->solarTime('sunrise')) ?></dd></div><div><dt>Untergang</dt><dd><?= View::escape($view->solarTime('sunset')) ?></dd></div></dl>
        </section>
    <?php endif; ?>
    </main>
    <footer><span>weewx-php <span class="footer-divider">/</span> Demo</span><span><?= View::escape($view?->zone->getName() ?? 'Europe/Berlin') ?></span></footer>
</div>
</body>
</html>
