# Theme cookbook: PHP tags, Apache ECharts, API and WordPress

This cookbook takes you from your first PHP theme to an embedded live widget.
The examples use the existing frontend layer, which provides WeeWX/xaggs
aggregates, astronomy queries, cacheable comparisons and explicit live values.
Apache ECharts draws the prepared series.

**Runnable examples:** the separately installable [Cookbook theme](themes.md#cookbook),
including its `data.php` and `assets/chart-recipes.js`, and the
[WordPress plugin](../examples/wordpress/weewx-weather/README.md).
The gallery uses real data from the configured station.
The active Cookbook publishes its feeds. Custom feed definitions take precedence; setup instructions follow below.

## Contents

- [Visitor unit selection](#visitor-unit-selection)

1. [Workflow and file structure](#1-workflow-and-file-structure)
2. [Your first PHP theme](#2-your-first-php-theme)
3. [Output and units](#3-output-and-units)
4. [Periods and time zones](#4-periods-and-time-zones)
5. [Observations, series and data quality](#5-observations-series-and-data-quality)
6. [Records and comparisons](#6-records-and-comparisons)
7. [Astronomy and theme settings](#7-astronomy-and-theme-settings)
8. [Cache, activation and operation](#8-cache-activation-and-operation)
9. [Apache ECharts](#9-apache-echarts)
10. [Public API](#10-public-api)
11. [Embedding on any website](#11-embedding-on-any-website)
12. [WordPress sidebar widgets](#12-wordpress-sidebar-widgets)
13. [Checks and troubleshooting](#13-checks-and-troubleshooting)

## Visitor unit selection

Use the shared output profile when a visitor should be able to switch units.
The running Demo and Cookbook packages include selection controls. The
[display-units reference](display-units.md) lists profiles, defaults, request
parameters and response metadata.

The core supplies `$wx` and `$theme` to `theme.php` and `snapshot.php`. Use the
same output profile before defining queries or reading live values:

```php
use WeewxPhp\Frontend\Output;

$wx = $wx->output($theme->output(new Output(
    language: $theme->language,
    decimals: ['group_percent' => 0, 'mbar' => 0],
)));
$temperature = $wx->current('outTemp')->value();
$history = $wx->last('24h')->series('outTemp', '15m')->series();

echo $temperature->html();
$chart = $history->jsonSerialize();
```

The same temperature might display as `20.0 °C` or `68.0 °F`. Do not append a
fixed unit to `html()` or convert the chart values again in JavaScript.
For a shared `data.php` also used by the CLI, initialize
`$theme ??= new WeewxPhp\Frontend\Theme();` before obtaining its output profile.

Place this control in the template. Keep any theme-specific navigation state,
such as the selected chart range, in hidden fields:

```php
<form method="get">
    <label for="units"><?= $theme->html('Units') ?></label>
    <select id="units" name="units">
        <?php foreach ($theme->unitOptions() as $id => $label): ?>
        <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"<?= $id === $theme->units->selection ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit"><?= $theme->html('Apply') ?></button>
</form>
```

Build JSON feed URLs with the effective profile, for example
`api/v1.php?feed=charts&units=us`. In an HTML attribute, escape `&` as `&amp;`.
The Cookbook template passes this URL to both the chart client and its widgets.
For a theme snapshot, use `data.php?units=us` and include the selected chart range.
Refresh the complete display when switching profiles so values and labels change
together.

An ECharts option can bind directly to the response metadata:

```javascript
const series = feed.data.temperature24h;
const number = new Intl.NumberFormat(document.documentElement.lang || 'en', {
    minimumFractionDigits: series.decimals,
    maximumFractionDigits: series.decimals,
});
const option = {
    xAxis: {type: 'time'},
    yAxis: {type: 'value', name: series.unitLabel, scale: true},
    tooltip: {
        trigger: 'axis',
        renderMode: 'richText',
        valueFormatter: value => value === null ? '—' : `${number.format(value)} ${series.unitLabel}`,
    },
    series: [{
        type: 'line', connectNulls: false,
        data: series.points.map(point => [point.end * 1000, point.value]),
    }],
};
```

See `assets/chart-recipes.js` in the Cookbook package for dual axes, rain totals,
hardware intervals and monthly comparisons using the same metadata. Extension
values returned as `Value`, `Series` or `Report` participate in the shared output
profile; remove explicit conversions such as `to('degree_C')` from their display
code. Keep fixed units only where the calculation itself requires them.

## 1. Workflow and file structure

A theme describes **which data it needs**. A `Query` starts as a recipe.
Only `get()`, `value()`, `series()`, `report()` or direct text output reads
the result. The worker calculates expensive recipes in the background.
The database and its column structure stay behind `Weather`.

```text
frontend.php                    Entry point: returns Weather
themes/my-theme/
  data.php                      Named recipes, no HTML output
  template.php                  HTML and presentation
  settings.json                 Optional: admin settings
  locales/en.json               Optional: admin strings
public/
  my-theme.php                  HTTP entry point
  assets/my-theme.css           Styling
  assets/my-theme.js            Interaction and ECharts
  api/v1.php                    Shared public data endpoint
/etc/weewx-php/
  station.conf                  Station configuration outside the webroot
  public-feeds.php               Explicitly published datasets
```

The web server uses only `public/` as its DocumentRoot. Configuration,
SQLite files, logs and theme PHP reside outside it. Theme files and
`public-feeds.php` are executable, trusted local code.
A URL parameter must never directly determine an include path.

## 2. Your first PHP theme

In `themes/my-theme/data.php`:

```php
<?php
declare(strict_types=1);

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Weather;

if (!isset($wx) || !$wx instanceof Weather) {
    throw new LogicException('Weather is missing');
}
$wx = $wx->output(new Output('en', units: [
    'group_temperature' => 'degree_C',
    'group_rain' => 'mm',
    'group_speed' => 'km_per_hour',
]))->reference('archive');

return [
    'temperature' => $wx->current('outTemp'),
    'rainToday' => $wx->day()->sum('rain'),
    'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
];
```

In `public/my-theme.php`:

```php
<?php
declare(strict_types=1);

/** @var \WeewxPhp\Frontend\Weather $wx */
$wx = (require dirname(__DIR__) . '/frontend.php')->cacheOnly();
try {
    $queries = require dirname(__DIR__) . '/themes/my-theme/data.php';
    $wx->syncTheme('my-theme', $queries);
    $data = $wx->dataset($queries)->get();
    require dirname(__DIR__) . '/themes/my-theme/template.php';
} finally {
    $wx->close();
}
```

In `themes/my-theme/template.php`:

```php
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weather</title>
<h1>Weather</h1>
<dl>
  <dt>Temperature</dt><dd><?= $data['temperature'] ?></dd>
  <dt>Rain today</dt><dd><?= $data['rainToday'] ?></dd>
</dl>
</html>
```

`Value` and individual `Query` results are HTML-safe when output directly.
Escape custom text such as station names with `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')`. Render series and reports explicitly;
they cannot meaningfully be output as a single text value.

Set up from the project directory:

```sh
php bin/weewx-php --config /etc/weewx-php/station.conf analytics preflight themes/my-theme/data.php
php bin/weewx-php --config /etc/weewx-php/station.conf analytics sync my-theme themes/my-theme/data.php
php bin/weewx-php --config /etc/weewx-php/station.conf analytics run
```

Set `WEEWX_PHP_CONF=/etc/weewx-php/station.conf` for the web process.
Then open `/my-theme.php`. An empty cache result displays a placeholder;
extensive preparation may require multiple worker runs. For a complete PHP
theme with error handling, see the [demo](themes.md#demo).

For migration from Cheetah:

| Cheetah expression | PHP recipe |
|---|---|
| `$current.outTemp` | `$wx->current('outTemp')` |
| `$day.rain.sum` | `$wx->day()->sum('rain')` |
| `$month.outTemp.max` | `$wx->month()->max('outTemp')` |
| `$year.outTemp.maxtime` | `$wx->year()->aggregate('outTemp', 'maxtime')` |
| `$alltime.rain.sum` | `$wx->alltime()->sum('rain')` |

The chains describe the same domain selection. In PHP, the recipe remains
unchanged until output and can be named, prepared and reused.

## 3. Output and units

An output profile applies to individual values, series and report values:

```php
$wx = $wx->output(new \WeewxPhp\Frontend\Output(
    language: 'en',
    units: [
        'group_temperature' => 'degree_C',
        'group_speed' => 'km_per_hour',
        'group_rain' => 'mm',
        'group_pressure' => 'mbar',
    ],
    decimals: ['group_percent' => 0, 'group_pressure' => 0, 'rain' => 2],
    missing: '—',
    dateFormat: 'Y-m-d H:i',
));

$temperature = $wx->current('outTemp')->value();
echo $temperature;                         // For example, 21.7 °C, HTML-safe.
$text = $temperature->format();            // Plain text for JSON, email, etc.
$number = $temperature->raw;               // Unrounded number or null.
$fahrenheit = $temperature->to('degree_F');
$labelFree = $temperature->format(label: false);

$series = $wx->last('24h')->series('outTemp', '15m')->series();
$fahrenheitSeries = $series->to('degree_F');
$labels = $series->formatted();
```

`format()` does **not** return HTML-escaped text; `html()` does.
Do not pass formatted numbers such as `"1,013.2"` to ECharts. Charts calculate
with numbers; format labels only at the axis or tooltip.
`null` means missing; `0` is an observed value. In PHP, check
`$value->raw !== null`; in JavaScript, use `value !== null`, not `if (value)`.

Conversion is presentation and creates no additional cache calculation.
A pending value remains a placeholder after `->to(...)`. A known incompatible
unit is rejected. Temperature differences in reports are converted without
the Celsius/Fahrenheit zero-point offset.

Currently supported output languages: `de`, `en`. Observation names remain
stable internally (`outTemp`); visible labels belong in theme/feed strings.

## 4. Periods and time zones

| Intended meaning | Recipe |
|---|---|
| Today by the clock | `$wx->today()` |
| Day of the latest archive interval | `$wx->reference('archive')->day()` |
| Rolling 24 hours through now | `$wx->reference('clock')->last('24h')` |
| Last 24 hours with archive data | `$wx->reference('archive')->last('24h')` |
| Seven calendar dates including today | `$wx->reference('clock')->days(7)` |
| Exactly 168 hours | `$wx->reference('clock')->last('7d')` |
| All of September 2024 | `$wx->on('2024-09')` |
| All of 2024 | `$wx->on('2024')` |
| Custom boundaries | `$wx->between('2024-09-01', '2024-10-01')` |
| Reproducible historical reference | `$wx->reference('fixed', '2024-09-15 12:00:00')` |

Calendar boundaries use the archive's time zone. A DST transition day can be
23 or 25 hours long. `last('24h')` always means 86,400 seconds.
An archive record at 00:00 ends the preceding observation interval and belongs
to the previous day under an archive reference. With a clock reference,
the new day starts at 00:00.

Archives use `(start, end]` intervals. Label a daily rain series with
**`start` as the calendar date**. Otherwise, Monday's rain appears under Tuesday.
For time-series curves, `end` is usually appropriate.

`current()` means the latest archive observation in an archive context.
With an explicit time, instant lookup and `maxDelta` apply; this is not automatic
LOOP live access. `latest()` finds the last valid observation. `live()`
reads the live journal. All three retain their own observation timestamp.

A `fixed` reference limits dynamic periods; `on()` and `between()` explicitly
request their complete boundaries. Without a selection, the compatible
`legacy` reference applies: weather follows the archive, astronomy the clock.

## 5. Observations, series and data quality

### Available observations

```php
$columns = $wx->observations();
$sensor = $wx->measurement('extraTemp1');
// stored, derivable, dependencies, missingDependencies, availability,
// unit, group, kind, asOf, age, defaultAggregate, output, aggregates
```

This is a bounded schema/availability lookup for development. An existing
column does not guarantee observations. A known formula does not guarantee
complete inputs. The public API deliberately does not publish this catalog
automatically: indoor readings remain private until the operator explicitly
includes them in a feed.

### Choosing the right aggregate

```php
$wx->day()->sum('rain');
$wx->day()->min('outTemp');
$wx->day()->max('outTemp');
$wx->day()->aggregate('outTemp', 'maxtime');
$wx->day()->aggregate('wind', 'vecdir');
$wx->last('24h')->series('rain', 'hour');        // Interval totals.
$wx->last('24h')->series('outTemp', 'hour');     // Time-weighted means.
$wx->last('24h')->series('outTemp', 'hour', 'max');
```

Without an explicit aggregate, `series()` chooses observation semantics: rain,
ET and interval energy are summed; states use time-weighted means; cumulative
rain counters return their last reading. Counter differences require a reset
rule. Wind direction requires vector evaluation, not an arithmetic mean of
degrees. `avg()` retains the documented WeeWX semantics.

All other WeeWX/xaggs names are listed in the [tag reference](frontend.md).
The general form is `aggregate('observation', 'aggregate', threshold, unit)`.
It also supports dynamically selected **locally approved** aggregates.

### Series structure and status

```php
$series = $wx->days(7)->series('rain', 'day')->series();
foreach ($series->points as $point) {
    // start, end: Unix seconds.
    // value: number or null.
    // coverage: observed fraction of the interval, 0..1 or null.
}
$pairs = $series->pairs(time: 'end', milliseconds: true);
$json = $series->json(time: 'end', milliseconds: true); // Coordinate pairs only.
```

By contrast, `json_encode($series)` contains the full object with unit,
status and coverage. Use the versioned feed endpoint for external websites.

| Status | Meaning for the theme |
|---|---|
| `ready` | Calculation available according to the refresh rule |
| `pending` | No completed result yet; show a placeholder |
| `stale` | Previous result available, refresh due; for live data: observation too old |
| `unavailable` | Source or observation currently unavailable |

`ready` does not tell you whether the station has been offline for days.
`asOf` identifies the underlying observation time; `computedAt` identifies
the calculation time. Keep them distinct. API live fields set `computedAt`
to `null` because they are read directly from the journal.

### Completeness and multiple series

```php
$completeDays = $wx->year()->series('rain', 'day')->completed(0.95);
$aligned = $wx->dataset([
    'temperature' => $wx->reference('clock')->days(7)->series('outTemp', 'hour'),
    'humidity' => $wx->reference('clock')->days(7)->series('outHumidity', 'hour'),
])->aligned();
```

`completed(0.95)` requires completed intervals with at least 95% coverage,
excluding an ongoing day. `coverage(0.95)` sets the coverage threshold without
also requiring a complete calendar interval. Mark today's daily bar as partial;
a value with gaps is not a reliable full-day amount.

`aligned()` joins exact interval boundaries and preserves missing values as
`null`. Different overlapping grids are rejected. For multiple archives, use
the same time reference, resolution and time zone. A shared list position is
not time alignment.

## 6. Records and comparisons

These recipes belong in `data.php`. They are cached as complete `Report`
objects, including ranking, comparison and quality checks.

```php
return [
    'wettestMonth' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->rank(1)->nightly(),
    'driestMonth' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->rank(1, ascending: true)->nightly(),
    'wettestDay' => $wx->alltime()->series('rain', 'day')
        ->completed(0.95)->rank(1)->nightly(),
    'driestSeptember' => $wx->alltime()->series('rain', 'month')
        ->completed(0.95)->calendarMonth(9)->rank(1, ascending: true)->nightly(),
    'monthComparison' => $wx->reference('clock')->month()->sum('rain')
        ->compareYears(0.95)->nightly('02:00'),
    'drySpell' => $wx->alltime()->series('rain', 'day')
        ->longestSpell(0, 'le', 'mm')->nightly(),
    'lastRainDay' => $wx->alltime()->series('rain', 'day')
        ->lastEvent(0, 'gt', 'mm')->nightly(),
    'rainP95' => $wx->alltime()->series('rain', 'day')
        ->completed(0.95)->quantile(0.95)->nightly(),
];
```

For example, the monthly comparison compares September 15 through noon with
the same local instant in earlier years. The current year does not contribute
to the reference mean. A complete historical month would answer a different question.

```php
$report = $queries['monthComparison']->report();
echo $report->value('current');
echo $report->value('mean');
echo $report->value('difference');
echo $report->value('percentOfMean');
$years = $report->referenceYears();
$exclusions = $report->meta['excluded'] ?? [];
$historicalPeriods = $report->periods;
```

Offer reference years and exclusions alongside the comparison. The report
records reasons for data gaps, incomplete intervals and nonexistent leap year
dates. Rankings contain the selected periods and the size of the valid
population. Ties are ordered by earliest start; the “driest day” is often one
of many days with 0 mm.

Dry spells require 100% observed time coverage. Data gaps break the sequence.
Resolution determines the meaning: `day` returns days, not a second-precise last
rain time. Choose a finer resolution with a bounded period for finer results.

Quantiles use R7 on the selected interval values. The 95th percentile of daily
amounts is not a quantile of all five-minute raw observations. Completed monthly
medians cannot be merged correctly by averaging them.

## 7. Astronomy and theme settings

Astronomy recipes can appear in the same manifest:

```php
$sky = $wx->reference('clock')->almanac();
$sunrise = $sky->sun()->rise()->nightly('00:05');
$sunset = $sky->sun()->set()->nightly('00:05');
$moonrise = $sky->moon()->tag('next_rising')->nightly();
$fullMoon = $sky->tag('nextFullMoon')->nightly();
$jupiterAltitude = $sky->body('jupiter')->altitude()->refresh('15m');
$siriusAzimuth = $sky->body('Sirius')->azimuth()->refresh('15m');
```

Configure station coordinates, altitude and time zone. Rises/sets may be absent
depending on location and date; allow placeholders. Events change less frequently
than instantaneous sky positions. More bodies, stars, horizon rules and time
series: [astronomy reference](frontend.md#astronomy-from-our-existing-calculations).

Theme settings are separate from weather queries:

```php
$theme = \WeewxPhp\Frontend\Theme::configured(
    '/etc/weewx-php/station.conf', id: 'demo', language: 'en'
);
$range = $theme->extras['default_range'] ?? '24h';
```

Registered, typed settings are available in `extras`.
See [Demo settings](themes.md#demo) and
[en.json](../themes/demo/locales/en.json) for examples. Map custom inputs,
such as a period selector, to fixed recipes:

```php
$range = ($_GET['range'] ?? null) === '7d' ? '7d' : '24h';
$selected = $queries[$range === '7d' ? 'temperature7d' : 'temperature24h'];
```

Register both variants in the manifest. This prevents URL variations from
creating unlimited new cache entries.

## 8. Cache, activation and operation

`analytics.sdb` is the shared, rebuildable result cache. Different themes can
share the same calculation. Closed series blocks and mergeable intermediate
states are reused. A theme needs neither its own cache database nor its own worker.

| Result | Example rule |
|---|---|
| Latest archive values, current daily values | Default `archive` |
| Frequently needed position/trend | `refresh('15m')` |
| Monthly comparison, long-term records | `nightly('02:00')` |
| Infrequently changed overview | `refresh('weekly')` or `refresh('monthly')` |
| Fixed period explicitly prepared once | `refresh('once')` |
| LOOP live values | Direct bounded journal access, no aggregate job |

Completed results remain valid while their source data and calculation rules
remain unchanged. Corrections, later imports or a changed source revision may
require recalculation. “Never touch again” therefore does not apply to data
that have subsequently changed.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
php bin/weewx-php --config station.conf analytics status
php bin/weewx-php --config station.conf analytics deactivate cookbook
```

`sync` atomically reconciles the complete named query set. Removed exclusive
registrations are released; other themes and manually pinned queries retain
their results. Synchronize again after changes. Only define queries in
`data.php`; do not load data there with `get()`.

The existing tick runs the worker after data processing. It must run regularly,
even when nobody visits the website. `analytics run` operates within a run
budget and does not guarantee completing the entire initial build in one
invocation. The gallery does not start a worker itself.

`cacheOnly()` prevents archive queries and calculations while rendering;
`Query::prepared()` enforces the same for a single existing recipe.
The public API always uses this. Cache access and registrations still occur;
the mode does not mean “no SQLite access at all.”

Priorities range from -10 to 10. Current values take precedence; large historical
recipes can use `priority(-5)`, for example. Long-waiting work gains priority
through aging. The shared page budget limits optional inline queries; a worker
builds large results incrementally.

## 9. Apache ECharts

The gallery includes **Apache ECharts 6.1.0** locally, with LICENSE, NOTICE and
checksum in [vendor/echarts](../public/assets/vendor/echarts/README.md).
No CDN or Node build is needed to get started. ECharts handles rendering and
interaction; PHP supplies the aggregated weather series.

### Starting the gallery

Place a `public-feeds.php` beside the station configuration selected by
`WEEWX_PHP_CONF`, with the following contents; adjust the project path:

```php
<?php
return require '/opt/weewx-php/themes/cookbook/feeds.php';
```

This example explicitly publishes three feeds for any origin: `sidebar`,
`live` and `charts`. It includes only the outdoor observations and charts
named in the file. Use section 10 for your own publications.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
```

Then open `/cookbook.php`. The current local demo is at
`http://127.0.0.1:8087/cookbook.php`. Its source contains only a few archive
days, no long-term comparison baseline and no active LOOP input. Empty
comparison years and missing live values represent the actual data state.

### First chart: PHP series → ECharts

HTML and CSS:

```html
<link rel="stylesheet" href="assets/charts.css">
<script defer src="assets/vendor/echarts/echarts.min.js"></script>
<script type="module" src="assets/temperature-chart.js"></script>
<div id="temperature" class="chart"></div>
```

```css
.chart { width: 100%; height: 320px; min-width: 0; }
```

`assets/temperature-chart.js`:

```js
import {subscribe} from './feed-client.js';
import {timeFormat} from './chart-recipes.js';

const node = document.querySelector('#temperature');
const chart = echarts.init(node);
const size = new ResizeObserver(() => chart.resize());
size.observe(node);
const stop = subscribe('api/v1.php?feed=charts&fields=temperature24h', feed => {
    if (!feed) return;
    const values = feed.data.temperature24h;
    chart.setOption({
        animation: false,
        tooltip: {trigger: 'axis', renderMode: 'richText'},
        xAxis: {type: 'time', axisLabel: {formatter: timeFormat(feed.timezone)}},
        yAxis: {type: 'value', name: '°C'},
        series: [{id: 'outside', type: 'line', name: 'Outside',
            showSymbol: false, connectNulls: false,
            data: values.points.map(p => [p.end * 1000, p.value])}],
    });
});
// When removing the chart, for example during an SPA route change:
// stop(); size.disconnect(); chart.dispose();
```

**URL resolution:** `subscribe()` resolves relative API URLs against the HTML
page, not the JavaScript file. The example suits a page directly in `public/`.
Adjust the path for pages in subdirectories. Absolute HTTPS URLs are unambiguous
for external embedding.

ECharts requires a container with measurable dimensions. In tabs/accordions,
initialize after the container becomes visible or call `resize()` afterward.
A `ResizeObserver` also responds to sidebar width changes, not just window sizes.
[Official chart size documentation](https://echarts.apache.org/handbook/en/concepts/chart-size/).

### Dataset and two axes

The runnable `temperatureHumidity(data, zone)` function in
[`chart-recipes.js`](../public/assets/chart-recipes.js) demonstrates two datasets:

```js
const option = {
    dataset: [{
        id: 'outside',
        dimensions: ['time', 'temperature'],
        source: feed.data.temperature24h.points.map(p => [p.end * 1000, p.value]),
    }],
    xAxis: {type: 'time'},
    yAxis: [{type: 'value', name: '°C'}, {type: 'value', name: '%', min: 0, max: 100}],
    series: [{
        id: 'temperature', type: 'line', datasetId: 'outside',
        encode: {x: 'time', y: 'temperature'}, yAxisIndex: 0,
        connectNulls: false,
    }],
};
```

`dataset` keeps numbers separate from chart options; `encode` maps dimensions
explicitly. Add a separate dataset and `yAxisIndex: 1` for humidity, as in the
gallery. Sharing a y-axis between °C and percent would be incorrect.
[ECharts Dataset](https://echarts.apache.org/handbook/en/concepts/dataset/).

### Daily rain as bars

```js
import {dailyRain} from './chart-recipes.js';
chart.setOption(dailyRain(feed.data.rain7d, feed.timezone));
```

This function labels `start` in the station's time zone. It creates calendar
categories instead of equal-length millisecond bars: a day remains one bar
across DST changes. Missing values remain `null`. The gallery also exposes
observed coverage in the data table.

### Drawing cumulative rain correctly

```js
import {rainAccumulation} from './chart-recipes.js';
chart.setOption(rainAccumulation(feed.data.rain24h, feed.timezone));
```

The function sums the prepared interval amounts and draws a stepped line.
This is lightweight presentation work over at most a few hundred points.
At the first missing or incompletely observed interval, the verified cumulative
total ends; later points remain `null`. Treating a data gap as 0 mm would invent
a total that is too low.

### Monthly comparisons and rankings

```js
import {monthlyComparison} from './chart-recipes.js';
chart.setOption(monthlyComparison(feed.data.rainComparison, feed.timezone));
```

The report already contains qualified historical comparison periods. ECharts
does not need to download ten years of raw data or calculate rankings.
`report.values.current`, `mean` and `difference` can appear alongside as
summary values. `report.meta.excluded` explains excluded references.
If reference years are missing, indicate an empty analysis instead of plotting zero.

For the “ten wettest months,” define a monthly series with
`completed(0.95)->rank(10)->nightly()` in the manifest. Bars use
`report.periods.points`; categories are the start's month **and year**.

### Overlaying yearly curves

Prepare a monthly or daily series in the worker. In PHP:

```php
$series = $wx->between('2022-01-01', '2026-01-01')
    ->series('outTemp', 'day')->series();
$overlay = $series->overlay('Europe/Berlin');
// Keys: month-day time; values: year => observation/null.
```

For ECharts, use the keys as a `category` axis and each year as a line.
Align by calendar date, not the 365th list index. February 29 retains its own
category. For hourly overlays, the repeated local hour at the clock change is
ambiguous; the existing function rejects it. Daily or monthly resolution avoids
this ambiguity.

### Updates, tooltips and accessibility

`feed-client.js` shares one polling cycle per identical URL, uses ETags,
limits requests to eight seconds, pauses in hidden tabs and delays retries
after errors. Do not create new `setInterval()` loops per metric. Update
ECharts with `setOption()`; stable series IDs preserve mappings.
[Dynamic data](https://echarts.apache.org/handbook/en/how-to/data/dynamic-data/).

Numeric coordinates use Unix milliseconds; API timestamps use Unix seconds.
Multiply by 1000 only once. Set the station's time zone explicitly with
`Intl.DateTimeFormat`, even when the visitor is abroad. Detailed timestamps
including the time zone avoid ambiguity during the repeated autumn hour.

Use tooltip `renderMode: 'richText'` and do not concatenate external text
into HTML formatters. `connectNulls: false` keeps observation gaps visible.
Do not enable `smooth` for actual trend curves without a domain reason:
smoothing can suggest observation patterns that never occurred.

A chart should have a clear label and a data table. The gallery shows interval
start, end, unit and coverage in an expandable table. ECharts also supports
ARIA descriptions and patterns; these do not replace an accessible table.
[ECharts accessibility](https://echarts.apache.org/handbook/en/best-practices/aria/).

## 10. Public API

### Publishing a feed

`public/api/v1.php` loads only a locally configured feed file:
`WEEWX_PHP_FEEDS` if set; otherwise `public-feeds.php` beside the station
configuration selected by `WEEWX_PHP_CONF`. Without this file, no feeds are
published. The local cookbook demo has such a file.

A standalone example for `public-feeds.php`:

```php
<?php
use WeewxPhp\Frontend\Api\Feed;
use WeewxPhp\Frontend\Output;

// $wx is provided by the endpoint.
$wx = $wx->output(new Output('en', units: [
    'group_temperature' => 'degree_C', 'group_speed' => 'km_per_hour',
]));

return [
    'garden-live' => new Feed(
        $wx,
        live: ['temperature' => 'outTemp', 'humidity' => 'outHumidity', 'wind' => 'windSpeed'],
        labels: ['temperature' => 'Temperature', 'humidity' => 'Humidity', 'wind' => 'Wind'],
        origins: ['https://blog.example.org'],
        pollSeconds: 15,
        liveMaxAge: 120,
        title: 'Garden',
    ),
    'archive' => new Feed(
        $wx,
        queries: ['temperature' => $wx->reference('archive')->current('outTemp')],
        labels: ['temperature' => 'Temperature'],
        origins: ['https://blog.example.org'],
        pollSeconds: 60,
    ),
];
```

For archive/chart feeds, preferably reuse recipes from the shared theme manifest
and manage its queries with `analytics sync`, as shown in
[the Cookbook feed definitions](themes.md#cookbook). The API request
also registers missing fixed recipes, but never calculates them inline.
Independent API recipes can have their own `data.php` and owner, such as
`api-public`. On deactivation, release that owner with
`analytics deactivate api-public`.

### Version 1 contract

```text
GET /api/v1.php?feed=garden-live
GET /api/v1.php?feed=garden-live&fields=temperature,humidity
HEAD /api/v1.php?feed=garden-live
OPTIONS /api/v1.php?feed=garden-live
```

`fields` may contain only fields from the selected feed. There are no HTTP
parameters for SQL, observation names, archive paths, arbitrary periods,
aggregation, units or callback functions. Additional variants require additional
approved recipes/feeds. Unknown parameters are rejected.

Example response; values only illustrate the schema:

```json
{
  "version": 1,
  "feed": "garden-live",
  "title": "Garden",
  "timezone": "Europe/Berlin",
  "pollSeconds": 15,
  "data": {
    "temperature": {
      "type": "value",
      "source": "live",
      "label": "Temperature",
      "value": 21.7,
      "unit": "degree_C",
      "group": "group_temperature",
      "formatted": "21.7 °C",
      "status": "ready",
      "asOf": 1787734200,
      "computedAt": null,
      "coverage": null,
      "delta": false
    }
  }
}
```

`type=series` returns `points` with `start`, `end`, `value`, `coverage`
and unit/status metadata. `type=report` returns `periods`, `values`,
`meta` and `status`; observation/calculation times are in its contained
series/values. `source` is `live`, `archive` or `astronomy`.
The weather fields shown contain numbers or `null`. Other tags may return
booleans, text or wind vectors as `{real, imag}`. For scalar wind charts,
explicitly select speed or `vecdir`. Keep field names and their domain meaning
stable within a feed. Publish a new feed name for an incompatible change.

| HTTP status | Meaning |
|---|---|
| 200 | Contract delivered; individual fields may be missing/pending |
| 304 | Content unchanged relative to `If-None-Match`; reuse the previous response |
| 204 | CORS preflight accepted, no body |
| 400 | Invalid parameters, fields or preflight headers |
| 403 | Browser origin not allowed |
| 404 | Unknown feed or API not configured |
| 405 | Method unsupported |
| 503 | Source/definition temporarily unusable; details only in the server log |

Errors have the form `{"version":1,"error":"…"}`. HEAD and 304 have no body.
Successful responses carry an ETag,
`Cache-Control: public, max-age=0, must-revalidate` and `Vary: Origin`.
The ETag saves bandwidth; it does not replace the worker or query limits.

### Access, limits and operation

**CORS is not authentication.** This API serves public weather data.
Even a feed with a narrow origin list can be retrieved by a server client.
Do not protect private indoor readings or secrets through CORS alone.
`origins: ['*']` deliberately permits embedding on any website.
Browser credentials and authorization headers are not used.

Each feed allows at most 24 prepared recipes and eight live fields; a response
is limited to 4,096 series points and 1 MiB JSON. Field names, feed names,
methods and parameters are constrained. Live access reads at most 512 journal
packets per field within the shared read budget. Budget exhaustion produces
`pending`. A live packet does not replace archived rain amounts or records.

These limits prevent unbounded archive work, but not unlimited HTTP requests.
For public operation, configure an appropriate request limit and HTTPS at the
reverse proxy. `pollSeconds` is a client recommendation, not server-side rate
limiting. Web server access logs show HTTP error rates and request volume.
The API process needs read permissions on live/archive files and the existing
permissions for the analytics cache.

Feed files must never be selected through URL parameters or executed from
uploads. Do not expose station configuration or diagnostics publicly.
API errors contain no internal paths or SQL text.

## 11. Embedding on any website

### Two HTML lines, assets from the weather server

```html
<script type="module" src="https://wetter.example.org/assets/weather-widget.js"></script>
<weewx-weather api="https://wetter.example.org/api/v1.php?feed=garden-live" fields="temperature,humidity,wind" title="Weather"></weewx-weather>
```

Load the JavaScript file once per page. You can then add any number of
`weewx-weather` elements. An empty field selection displays all individual
values in the feed. This small widget does not render chart series.

**Also enable CORS for static assets used as external ES modules.**
API headers alone are not enough. Nginx example in the weather virtual host:

```nginx
location ~ ^/assets/(weather-widget\.js|feed-client\.js|weather-widget\.css)$ {
    add_header Access-Control-Allow-Origin "*" always;
    add_header X-Content-Type-Options nosniff always;
    try_files $uri =404;
}
```

Serve JavaScript with the correct MIME type. For Apache, set the corresponding
headers for exactly these files. The embedding page's CSP must allow the weather
origin in `script-src`, `style-src` and `connect-src`. On an HTTPS page,
use HTTPS URLs for all weather resources too.

### Assets on the embedding website

Alternatively, copy three files to your own directory:
`weather-widget.js`, `feed-client.js`, `weather-widget.css`. Preserve their
relative paths to one another. Then change only the script path:

```html
<script type="module" src="/weather-assets/weather-widget.js"></script>
<weewx-weather api="https://wetter.example.org/api/v1.php?feed=garden-live"></weewx-weather>
```

Only the JSON API now needs CORS. The WordPress plugin uses this approach too.
A complete HTML file is available at [examples/embed.html](../examples/embed.html).

### Behavior and styling

The widget displays value, source, observation time and status when applicable.
Missing values appear as placeholders; on connection errors, previous data
remain visible with an error indicator. It does not automatically switch from
live data to old archive observations.

Shadow DOM isolates styles from the page theme. Customize through CSS variables:

```css
weewx-weather {
  --weather-background: #fff;
  --weather-text: #183e37;
  --weather-muted: #52665f;
  --weather-border: #d6e1dc;
}
```

For custom snippets, `fetch()` is also sufficient:

```js
const response = await fetch('https://wetter.example.org/api/v1.php?feed=garden-live&fields=temperature', {
    credentials: 'omit',
});
if (!response.ok) throw new Error(`HTTP ${response.status}`);
const feed = await response.json();
document.querySelector('#outside').textContent = feed.data.temperature.formatted;
```

This is a one-time request. For regular updates, use the supplied `subscribe()`
client; it handles timeouts, pauses and retries. Insert API text through
`textContent`, not `innerHTML`.

## 12. WordPress sidebar widgets

An installable plugin is available as source under
[`examples/wordpress/weewx-weather`](../examples/wordpress/weewx-weather).
Generate the ZIP in the project directory:

```sh
python examples/wordpress/package.py
```

Result: `data/artifacts/weewx-weather.zip`. In WordPress, select it under
**Plugins → Add New Plugin → Upload Plugin** and activate it.
The ZIP includes the shared widget assets locally; no CDN, WordPress proxy
or additional WordPress cron job is required.

Add a **Shortcode block** to the sidebar in the block widget editor:

```text
[weewx_weather api="https://wetter.example.org/api/v1.php?feed=garden-live" fields="temperature,humidity,wind" title="Weather at home"]
```

The same shortcode works in posts and pages. Classic themes also get the
**WeeWX Wetter** widget with API URL, title and comma-separated field selection.
The plugin uses the theme's existing sidebar; it does not register another one.

JavaScript is enqueued through WordPress and loaded as a module. Widget forms
sanitize options; output escapes attributes. Browser API access needs no
credentials. The WordPress origin must be allowed in the feed, for example
`https://blog.example.org` without a path or trailing slash.

Page caches may store the widget HTML: observations are updated afterward in
the browser. Optimization plugins must not convert ES modules and their relative
imports into classic scripts. If needed, exclude the `weewx-weather` handle
from bundling. With a CSP, the matching origin in `connect-src` is sufficient
for external weather data because assets are local.

Background: [WordPress WP_Widget](https://developer.wordpress.org/reference/classes/wp_widget/),
[Enqueuing scripts](https://developer.wordpress.org/reference/functions/wp_enqueue_script/).

## 13. Checks and troubleshooting

### Checking the API and cache

```sh
curl -i 'https://wetter.example.org/api/v1.php?feed=garden-live'
curl -i -H 'Origin: https://blog.example.org' 'https://wetter.example.org/api/v1.php?feed=garden-live'
curl -i -X OPTIONS -H 'Origin: https://blog.example.org' \
  -H 'Access-Control-Request-Method: GET' -H 'Access-Control-Request-Headers: If-None-Match' \
  'https://wetter.example.org/api/v1.php?feed=garden-live'
```

Inspect `$wx->diagnostics()` in the local theme when needed: data source,
rows, cache hits, next refresh and errors. Keep this output internal.
For prepared-only query clones, diagnostics belong to each clone;
use `analytics status` for overall operation.

| Symptom | Check |
|---|---|
| Everything `pending` | Manifest synchronized? Worker running? Multiple initial build runs needed? |
| `stale`, values unchanged | Check worker status and observation time; the station may have no new data |
| API 404 | Check `WEEWX_PHP_CONF`, feed file path and feed name |
| API 403 | Exact origin allowed, including scheme and port? |
| API 503 | Check server log, definition, SQLite access and response limits |
| Live empty, archive populated | Check LOOP input, sender mapping and live journal |
| ECharts empty | Check container height, API status, points and browser console |
| Chart dates show 1970 | Seconds were not converted to milliseconds |
| Rain shifted by one day | Label daily bars with `start` |
| Gaps become zero | Avoid `value || 0` expressions or unconsidered fill values |
| Widget fails only on an external site | Check API CORS, module/CSS CORS, MIME, CSP and HTTPS |
| No comparison years | Check report coverage and exclusion reasons; do not interpret as zero |

### Testing custom themes

Include at least one complete day, a data gap, an actual zero value, a missing
sensor and a DST transition. Request a cold cache: the page must render with
placeholders. Then run the worker and check the same page again. Synchronize a
changed manifest and verify that other themes retain their results.

Bundled checks:

```sh
docker compose -f tests/docker/compose.yml run --rm unit
docker compose -f tests/docker/compose.yml run --rm lint
docker compose -f tests/docker/compose.yml run --rm frontend-js
php tests/wordpress-smoke.php
```

PHP tests cover cache-only access, CORS, ETags, field permissions, live observation
time and missing data. JavaScript tests cover time conversion, data gaps, calendar
labels and polling. The WordPress smoke test checks hooks, shortcode, widget
options and escaping with a local API double; a complete WordPress installation
test remains a separate integration check.

Additional references: [complete tags](frontend.md),
[recipes and cache behavior](frontend-recipes.md),
[station configuration](configuration.md), [CLI](commands.md).
