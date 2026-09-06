# PHP tags for themes

New: [Output profiles, time references, theme management and prepared
analyses](frontend-recipes.md). `series()` without an aggregate now uses
observation semantics; explicitly specified aggregates keep their behavior.

A runnable [demo theme](../themes/demo/README.md) uses these tags to display
observations, temperature and rain history, and sun times.

```php
$wx = require '/path/weewx-php/frontend.php';
echo $wx->current('outTemp')->to('degree_C');
echo $wx->day()->sum('rain');
echo $wx->year()->max('outTemp');
echo $wx->on('2025-09')->sum('rain');
echo $wx->last('6h')->avg('windSpeed');
echo $wx->trend('barometer', over: '3h');
$json = $wx->week()->series('outTemp', 'hour')->json(milliseconds: true);
```

`frontend.php` uses `weewx-php.conf` or the path from `WEEWX_PHP_CONF`.
Alternatively, use `Weather::open($configPath, $archiveId)`. `archive('garden')`
selects another configured archive. All instances created this way share the
page budget. The archive file must already exist.

## Registering and preparing queries

A query starts as a recipe. `get()`, `value()`, `series()`, `raw()`, formatting
or output requests the result and registers the query automatically.
Use `data.php` to prepare queries without a page visit:

```php
// themes/my-theme/data.php; $wx comes from the theme or CLI.
return [
    'temperature' => $wx->day()->series('outTemp', 'hour'),
    'rainMonths' => $wx->alltime()->series('rain', 'month', 'sum')
        ->completed(0.95)->nightly('03:00'),
    'rainRecord' => $wx->alltime()->maxsum('rain')->nightly(),
    'sunrise' => $wx->almanac()->sun()->rise()->nightly('00:10'),
];
```

```sh
php bin/weewx-php analytics register themes/my-theme/data.php
php bin/weewx-php analytics run
php bin/weewx-php analytics status
php bin/weewx-php analytics catalog
```

Registrations remain pinned until `analytics forget <id>`. Automatically discovered
recipes stop refreshing after 30 days without use. At most 1,000 recipes can be
registered. When changing `data.php`, remove old IDs and register again. The file
is trusted theme software, not an upload format.

The existing tick handles background work after archiving and uploads, using at
most two seconds of its remaining budget. Long calculations resume on the next
tick. `analytics run` uses the configured time budget. No additional daemon is
required.

## Refresh schedules, results and historical blocks

| Call | Refresh |
|---|---|
| Default / `refresh('archive')` | Archive interval |
| `refresh('15m')`, `refresh('6h')` | Time windows of the specified duration |
| `nightly('03:00')` | Daily at the local time |
| `refresh('daily')` | Next local midnight |
| `refresh('weekly')` | Next start of the week, Monday |
| `refresh('monthly')` / `refresh('yearly')` | Start of month/year |
| `refresh('once')` | Once; changes may trigger repair |

Completed explicit periods do not expire with time. A completed September 2025
is retained. Dynamic queries such as `year()` keep their schedule because they
will later refer to another year. Series store completed blocks separately and
reuse them across recipes and schedules. Formatting and unit conversion do not
create additional calculations.

`.completed()` removes partial and ongoing blocks. Its argument is the minimum
coverage by valid observation intervals; the default of 1 means complete.
Gaps are `null`; a dry period has an observed value of 0.

`ready` means ready according to the agreed schedule, `pending` means no result
yet, and `stale` means an existing result is due for recalculation. `asOf` is the
reference time; `computedAt` is the calculation time. The theme decides how to
show placeholders and reload. `ready` does not confirm a current connection to
the station; archive observations themselves may be outdated.

Changes made by our archiver invalidate affected days and dependent results.
Corrections can also refresh completed blocks. Configuration changes and detected
external writes invalidate more broadly as a precaution. External writers do
not report changed ranges and can therefore cause more recalculation.
Invalidate explicitly after external imports:

```sh
php bin/weewx-php analytics invalidate kirchdorf 2025-09-01 2025-10-01
```

The separate `analytics.sdb` is replaceable and belongs outside the webroot.
The frontend opens WeeWX archives read-only. Historical cache blocks have no TTL
and grow with registered demand. Automatic cleanup based on a storage quota
is not yet implemented.

## Performance limits

Small initial calculations may run on the page. The shared default budget is
12,000 rows read, 128 source queries and approximately 200 ms. Archive queries
are bounded by primary keys; calculations process batches of 512 rows.
PHP 8.1 has no SQLite progress handler: the time limit is checked between
statements, rows and astronomy steps, not as a hard interruption of a running
operation. A database lock waits at most 25 ms.

A series contains at most 2,048 points. Use monthly/yearly blocks for decades;
daily records can be queried directly through daily aggregates. Invalid queries
throw `QueryError`; budget overruns create a background job. Arbitrary SQL
expressions are not allowed. The budget limits this library, not arbitrary PHP
theme code or host load.

Daily summaries are used only when version 4.0 and `lastUpdate` cover the
required period. Otherwise, simple aggregates use raw data within the budget;
daily-only aggregates require intact summaries. Mixed unit systems in a raw
archive must be normalized before aggregation.

## WeeWX and xaggs

Based on WeeWX 5.5 and xaggs 1.0, commit
`d36145689b6d4bd363a792432df94215d69f5026`. `analytics catalog` lists the names.
The purpose is a PHP API with corresponding functions, not a Cheetah interpreter.

| Family | PHP access |
|---|---|
| current, latest, trend | `current('outTemp')`, `latest('outTemp')`, `trend('outTemp')` |
| hour/day/yesterday/week/month/season/year/rainyear/seasonsyear/alltime | Methods with the same names; `ago: 1` for the previous period |
| Custom spans | `between(start, end)`, `last('6h')`, `on('2025-09')` |
| Observations and custom columns | Observation name as argument; `observations()` |
| Aggregates | `aggregate('rain', 'maxsum')` or `maxsum('rain')` |
| Thresholds | `year()->avg_ge('outTemp', 25, 'degree_C')` |
| Calendar iteration | `periods('day')` |
| Series | `series('rain', 'day', 'sum')`; aggregate `cumulative` for a running total |
| Raw series | `records('outTemp')` |
| Value helpers | `to()`, `format()`, `html()`, `raw()`, `json()` |
| Station/Units/Labels | `station()`, `unit('outTemp')`, `Theme::label()` |
| gettext/pgettext | `Theme::text(message, context)` or `html()` |
| Skin/Report/Extras/SummaryBy* | `Theme` and `Theme::tags()`; rendering context supplied by the theme |
| Python helpers such as jsonize, to_int, to_bool, to_list | PHP types, casts and JSON functions |

The core catalog contains 38 names: min/max with timestamps, first/last values,
differences, derivatives, RMS, wind vectors, means of daily extremes, extremes
of daily totals, threshold counts and availability checks. `windvec` and
`windgustvec` return a `Vector` with east/north components, magnitude and
direction. Heating/cooling/growing degree days use daily means. Missing derivable
columns use our existing weather formulas within the same read budget.

All seven xaggs `historical_*` aggregates examine one calendar day across all
archive years: `on('2025-09-05')->historical_avg('outTemp')`.
`avg_ge`, `avg_gt`, `avg_le`, `avg_lt` count days by their daily mean.

Archive spans use WeeWX's `(start, end]`; midnight belongs to the preceding
archive day. Calendar blocks use the archive's time zone, including daylight
saving time. During the repeated autumn hour, the supplied UTC instant is
preserved. `season()` is meteorological. `calendar(weekStart: 0, rainYearStart: 10)`
sets the start of the week (0 = Monday, 6 = Sunday) and rain year.

## Astronomy from our existing calculations

```php
echo $wx->almanac()->sun()->rise();
echo $wx->almanac()->moon()->tag('next_rising');
echo $wx->almanac()->tag('nextFullMoon');
echo $wx->almanac()->body('jupiter')->altitude();
echo $wx->almanac()->body('Sirius')->azimuth();
echo $wx->almanac()->separation('moon', 'venus');
echo $wx->almanac()->observer(horizon: -6, pressure: 0)->sun()->center()->rise();
$path = $wx->almanac()->body('mars')->series(
    'altitude', '2026-09-05', '2026-09-06', '15m'
)->series();
```

Planet calculations/orbital data come from `wetter`; lunar positions, moon phases
and seasons come from `weewx-evo`. `Weewx\Sun` remains the solar foundation.
Python and PyEphem serve only as test references.
Provenance: `src/Astronomy/SOURCES.md`.

Available objects include the Sun, Moon, eight additional bodies including Pluto,
and the PyEphem star catalog: rises/sets, transits/antitransits, previous/next
events, visibility duration and change, coordinates, angular separations,
illumination, moon age/names, sidereal time, distances and seasonal events.
Legacy angle aliases are accepted. Angles consistently use degrees; times use
Unix seconds, convertible with `to('dublin_jd')`.

Inapplicable values remain `null`. `mag` is currently available only for the Sun
and catalog stars. Satellite/comet orbits from additional catalogs and their
special fields are not yet connected. `almanac($timestamp)` fixes the instant
and is persisted; without an explicit instant, the current time applies with
a default five-minute refresh schedule. Observer parameters belong to the recipe.

The event search covers 48 hours and can find a rise on the following day where
PyEphem initially reports `NeverUp`. `visible` measures actual visibility during
the local day, including 23/25-hour days. The approximations are not bit-for-bit
reproductions of PyEphem. The comparison matrix covers 2,072 cases from 2000,
2024, 2026 and 2035 at three locations: largest observed deviations 0.02725°
and 62 s. Pluto is designed for 1885–2099.

## Precipitation comparisons

```php
$months = $wx->alltime()->series('rain', 'month', 'sum')
    ->completed(0.95)->nightly()->series();
$wettest = $months->rank(5);
$driest = $months->rank(5, ascending: true);
$septembers = $months->calendarMonth(9, 'Europe/Berlin');
$median = $septembers->quantile(0.5);
$comparison = $septembers->compare($wx->on('2025-09')->sum('rain')->value());

echo $comparison['mean'];
echo $comparison['difference'];
echo $comparison['percentOfMean'];
echo $comparison['percentile'];

// Original WeeWX daily records:
echo $wx->alltime()->maxsum('rain')->nightly();
echo $wx->alltime()->maxsumtime('rain')->nightly();
echo $wx->alltime()->minsum('rain')->nightly();
```

Rankings retain time spans and data coverage. Ties are ordered by the earlier
period. Quantiles use linear interpolation (R7); percentiles use the midrank
for ties. Missing observations do not participate. `compare()` uses all values
in the supplied reference series, potentially including the comparison month
itself. For independent references, set boundaries with `between()`.
Compare ongoing months only with historical spans of equal length;
this alignment is not yet automatic.

Additional defined recipe types, not yet implemented:

| Analysis | Domain rule | Schedule |
|---|---|---|
| Month-to-date comparison across earlier years | Same local calendar day; February 29 rule | Daily; historical daily blocks retained |
| Longest dry/wet spell | Rain threshold, coverage, gaps break spells | After day completion; merge prefixes/suffixes |
| Rain events | Dry pause, minimum amount, intensity | Archive interval; closed events retained |
| Climate normals/anomalies | Reference years, coverage, weighting | Month/year completion |
| Frost, heat, tropical nights | Local daily window and thresholds | Daily |
| Rolling quantiles/wind rose | Window, time weighting, calm conditions | Per recipe; no quantiles from averaged quantiles |

New aggregates require units, null/gap rules, merge rules, data dependencies
and a refresh schedule. Themes therefore retain the same small API.

## Checks and sources

`tests/Unit/Frontend` and the `frontend`, `astronomy` and `frontend_scale`
conformance checks test this layer. The scale test generates 1,051,776 five-minute
rows and 3,652 daily summaries. One measured run took 45.3 ms for the initial
grand total, 0.3 ms for cache access and 16.3 ms before a raw-data query was
interrupted by its budget. These are not guaranteed host latencies.

Sources: [WeeWX tags](https://weewx.com/docs/latest/custom/cheetah-generator/),
[WeeWX developer notes](https://weewx.com/docs/latest/devnotes/),
[weewx-xaggs](https://github.com/tkeffer/weewx-xaggs).
