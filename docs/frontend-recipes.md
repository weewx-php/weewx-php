# Frontend: output, time references and prepared analyses

These examples complement [the tag reference](frontend.md). A query is an
immutable recipe. `get()` returns `Value`, `Series` or `Report`.
Conversion and formatting change neither the recipe nor its cache entry.

## One output profile per theme

```php
use WeewxPhp\Frontend\Output;

$wx = (require '/path/weewx-php/frontend.php')->output(new Output(
    language: 'en',
    units: ['group_temperature' => 'degree_C', 'group_rain' => 'mm',
        'group_speed' => 'km_per_hour', 'group_pressure' => 'mbar'],
    decimals: ['group_pressure' => 0, 'group_percent' => 0, 'ET' => 3],
    missing: '—',
    dateFormat: 'Y-m-d H:i',
));

echo $wx->current('outTemp');                  // HTML-safe, including the unit.
$series = $wx->last('24h')->series('outTemp', '15m')->series();
$fahrenheit = $series->to('degree_F');
$numbers = $series->pairs();                  // Unrounded numbers; null stays null.
$table = $series->formatted();                // Text using the same profile.
echo $wx->on('1990')->min('outTemp')->value()->to('degree_C');
```

Even a pending value without a known source unit can be converted: it remains
a placeholder. Known incompatible units cause an error. `format()` produces
text; `html()` and direct output produce HTML-safe text. Explicit printf formats
override the number profile. Initial languages are `de` and `en`; the global
process locale is not changed. Units can be overridden by observation or unit
group; decimal places can additionally be overridden by unit. Temperature
differences are converted without a zero-point offset.

## Choosing a time reference explicitly

```php
$now = $wx->reference('clock');
$archive = $wx->reference('archive');
$snapshot = $wx->reference('fixed', '2024-09-15 12:00:00');

$now->day();                // Today's calendar date, including exactly 00:00.
$wx->today();               // Same, regardless of the other time reference.
$archive->day();            // Day containing the latest archive record.
$now->last('24h');          // Exactly 86400 elapsed seconds.
$now->last('7d');           // Exactly 168 hours.
$now->days(7);              // Exactly seven calendar dates, including today.
$wx->on('2024-09');         // Explicit full month.
```

With an archive reference, a midnight record belongs to the day that just ended,
following WeeWX. Calendar arithmetic uses the archive's time zone: seven calendar
days may cover 167 or 169 hours across a DST change. `clock` and `fixed` also
apply to astronomy. A fixed reference limits dynamic weather periods to that
instant; `between()` and `on()` explicitly query their complete boundaries.

Without a selection, `legacy` preserves compatibility: weather follows the latest
archive record, astronomy the clock. `current()` reads the latest archive record
in the default/archive context; an explicit instant uses the existing `maxDelta`
rules. `latest()` searches backward for the last valid value and returns its
observation time in `asOf`. `live()` explicitly reads the live journal.
These three access methods do not automatically replace one another.

## Observations and shared datasets

```php
$sensor = $wx->measurement('extraTemp1');
// stored, derivable, dependencies, missingDependencies, availability,
// kind, unit, group, asOf, age, defaultAggregate, output, aggregates

$wx->day()->series('ET', 'hour');       // Sum by default.
$wx->day()->series('rain', 'hour');     // Sum.
$wx->day()->series('outTemp', 'hour');  // Time-weighted mean.
$wx->day()->avg('outTemp');            // Existing WeeWX semantics.

$data = $wx->dataset([
    'outside' => $wx->reference('clock')->days(7)->series('outTemp', 'hour'),
    'other' => $wx->archive('second_location')->reference('clock')->days(7)->series('outTemp', 'hour'),
]);
$results = $data->get();
$grid = $data->aligned();
echo $data->json();
```

Without an aggregate, `series()` uses observation semantics: interval amounts such
as rain, ET and energy are summed; states are time-weighted; cumulative rain
counters return the latest reading. A counter difference requires an explicit
reset rule and is therefore not automatically interpreted as a rainfall amount.
For wind directions, use `aggregate('wind', 'vecdir')`. A specified aggregate
overrides this selection. Custom observations use the same existing observation
catalog and their configured unit groups.

`measurement()` performs bounded checks of the schema and current observation.
`stored` means the column exists, not that an observation exists. `derivable`
means a known formula, not guaranteed complete inputs. `dependencies` lists known
observation prerequisites; coordinate/history-dependent formulas must additionally
be computable from the station data.

`aligned()` joins exact interval boundaries, fills missing values with `null`
and rejects overlapping grids with different resolutions. For multiple archives,
choose the same time reference, time zone and resolution. There is no implicit
interpolation. `Series::overlay($timezone)` aligns existing values by month, day
and time instead of list position; missing years receive `null`, and February 29
keeps its own category.

## Comparisons, records and events as recipes

```php
$month = $wx->reference('clock')->month()->sum('rain')
    ->compareYears(minimumCoverage: 0.95)->nightly('02:00');

$wettest = $wx->alltime()->series('rain', 'month')
    ->completed(0.95)->rank(1)->nightly();
$driestSeptember = $wx->alltime()->series('rain', 'month')
    ->completed(0.95)->calendarMonth(9)->rank(1, ascending: true)->nightly();
$dry = $wx->alltime()->series('rain', 'day')
    ->longestSpell(threshold: 0, operator: 'le', unit: 'mm')->nightly();
$lastWetDay = $wx->alltime()->series('rain', 'day')
    ->lastEvent(threshold: 0, operator: 'gt', unit: 'mm')->nightly();
$p95 = $wx->alltime()->series('rain', 'day')->completed(0.95)
    ->quantile(0.95)->nightly();

$report = $month->report();
echo $report->value('current');
echo $report->value('mean');
echo $report->value('difference');
echo $report->value('percentOfMean');
echo $report->value('percentile');
$years = $report->referenceYears();
$exclusions = $report->meta['excluded'] ?? [];
```

All these recipes, including post-processing, are calculated in the worker and
stored as complete `Report` objects. A page returns `pending` when no result
exists, or the previous report with `stale` when refresh is due. `periods`
contains included intervals with coverage; `meta` records rules and exclusions.
For rankings, `periods` is the selected ranking and `populationCount` is the
number of all valid candidates. Ties are resolved deterministically by earliest start.

The monthly comparison uses the current reference month through the same local
calendar time in each previous archive year. The reference year itself is
excluded from the comparison mean. Example: September 15 at noon is compared
with September 15 at noon in previous years. Nonexistent leap year dates are
excluded with a reason. When the comparison mean is zero, the percentage
comparison remains null.

Dry spells require complete intervals with 100% observed time coverage. Gaps and
noncontiguous boundaries break the sequence. `start`, `end`, `duration`,
`intervals`, `last`, `since` and `matchingDuration` give the start, end,
length, count, last match, elapsed time and total matching duration.
`lastEvent()` uses the selected interval resolution: a daily recipe returns
the end of the last wet day, not a claimed second-precise rain time. For a finer
search, choose a finer resolution and a suitably bounded period.
Only `gt`, `ge`, `lt`, `le` are allowed.

Quantiles use exact R7 on the selected interval aggregates. The 95th percentile
of daily precipitation is not a statistic of all raw observations.
Monthly medians are never averaged or presented as a raw-data median.

## Theme queries and the worker

```php
// data.php contains only trusted local theme code.
$queries = require __DIR__ . '/data.php';
$wx->syncTheme('my-theme', $queries); // Activate or synchronize a changed list.
$wx->deactivateTheme('my-theme');    // Release exclusive registrations.

$prepared = $wx->cacheOnly();          // No calculations or archive queries while rendering.
$urgent = $wx->current('outTemp')->priority(10);
$history = $wx->alltime()->max('outTemp')->priority(-5)->nightly();
```

```sh
php bin/weewx-php --config station.conf analytics preflight themes/demo/data.php
php bin/weewx-php --config station.conf analytics sync demo themes/demo/data.php
php bin/weewx-php --config station.conf analytics run
php bin/weewx-php --config station.conf analytics status
php bin/weewx-php --config station.conf analytics deactivate demo
```

`sync` is atomic and idempotent when queries are unchanged. Other themes and
queries manually pinned with `register` retain their results. Activation makes
calculations available to the existing tick/worker; `analytics run` starts
preparation immediately within the run budget. A large initial build requires
multiple worker runs. The normal tick already runs the worker after
ingest/archiving. No extra cache database per theme or new server service is required.

Priorities range from -10 to 10. Current observations start at 10; long-waiting
tasks gain priority through aging. Each job receives a bounded row, statement
and time slice per run and can resume. `nightly()`, `weekly`, `monthly`,
`yearly`, fixed durations and `once` set publication frequency. A closed explicit
interval is recalculated only after an affecting data/configuration change.

## Reuse and diagnostics

The shared cache also stores count, sum, weighted sum, time weight and minima/maxima
with timestamps. Different aggregates can reuse the same state. Disjoint states
that fit without gaps can be merged into a larger period. Example: daily states
→ month → year. Raw-data and daily-summary states remain separate, as do
observations and archives. A bounded merge attempt falls back to resumable
source access when no complete partition exists.

Closed series blocks also remain reusable. Interval-specific archive corrections
delete affected states and blocks. External writes without a change log invalidate
more broadly as a precaution. “Closed” therefore means permanently valid in the
absence of new source data/corrections.

`$wx->diagnostics()` returns cache hits, data sources, rows/statements read,
runtime, next refresh and errors per query. `analytics status` additionally
includes owner count, priority, build state and diagnostics for the last
calculation step. Values are step/query measurements, not estimates of all future
costs. Diagnostic output belongs in local development or protected administration,
not in a public JSON endpoint.

Normal series are limited to 2048 points, analyses to 50000 intervals, datasets
to 128 queries and the cache to 1000 registered recipes. For larger datasets,
use coarser intervals. Point limits produce a visible error, not silently
truncated statistics.

## Live data and the demo

`live('outTemp', maxAge: 120)` reads at most 512 recent journal packets, applies
existing sender mapping, units, calibration and quality rules, and returns
observation time and `ready`/`stale`/`unavailable`. A missing live journal is
not created. History-dependent quantities not already in the packet are not
derived here. Live values are displayed explicitly; they are not added to archive
amounts. Daily extremes remain those stored by the existing archive process,
including LOOP extremes.

The demo synchronizes its queries on load and uses the output profile, seven
calendar dates and the three new analysis examples. `public/data.php` exports
only this fixed dataset from prepared results, plus the explicitly requested
live value. It accepts no SQL, file paths or arbitrary queries. Polling every
15 seconds pauses in hidden tabs and does not overlap. Charts update when the
prepared data change. The worker must run regularly and independently.
