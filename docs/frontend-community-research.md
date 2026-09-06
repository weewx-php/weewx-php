# Requirements from the WeeWX community

Research date: September 5, 2026. Based on selected public user group posts,
GitHub issues and replies, and documentation for several skins. This is a
qualitative sample, not a representative user survey or a ranking by installation
count. Older and closed bugs serve as use cases, not as claims about current
versions.

## Observations and implications

| Evidence | Observation | Implication for our layer |
|---|---|---|
| [Belchertown #924](https://github.com/poblabs/weewx-belchertown/issues/924), January 2024, closed | Several users with old database schemas were affected: derived observations were calculated during report generation; the author reports a run aborted after 600 seconds. | Check schema and derivability in advance; prepare expensive derivations in the worker; expose costs and missing prerequisites. |
| [User group: historical charts with gaps](https://groups.google.com/g/weewx-user/c/XYTvS6lA1Z0), October 2021 | November/December values appeared in January/February categories when earlier months were missing. | Use a shared calendar grid with explicit time boundaries and nulls; never infer categories from the position of available values. |
| [NeoWX #88, solution report](https://github.com/neoground/neowx-material/issues/88#issuecomment-2804120751), April 2025 | Very small individual ET values appeared as 0.0; a user describes switching to period totals. | Match display aggregation to observation semantics: sum interval amounts, time-average states and handle counters separately. Round only for output. |
| [NeoWX #93](https://github.com/neoground/neowx-material/issues/93), October 2024 | Custom air quality sensors appear with six decimal places despite format settings. | Use one output profile for HTML, tables and charts, including custom observations. |
| [NeoWX #104 and replies](https://github.com/neoground/neowx-material/issues/104), March 2026 | Inconsistent sensor/chart identifiers complicated soil moisture display. Replies report a fix in the seehase fork, confirmed by the requester. | Use one observation catalog and the same series recipes on all pages; preflight checks instead of silently disappearing charts. |
| [User group: second data source](https://groups.google.com/g/weewx-user/c/0hTWSxyolXI), May 2026 | Indoor readings from a second instance were visible as individual values, but charts were missing. The maintainer points to a Seasons bug; the user confirms the workaround. | Use the same archive selection for values and series; allow multiple archives to align to shared timestamps. |
| [NeoWX #97](https://github.com/neoground/neowx-material/issues/97), February 2025 | Explicit request for automatic refresh on kiosk screens. | Provide refreshable datasets and data age; connect existing live data. Keep transport and page layout separate. |
| [User group: last rain](https://groups.google.com/g/weewx-user/c/s7im4XEckDk), March 2021, and [days since last rain](https://groups.google.com/g/weewx-user/c/1vtUflRv5ys), from August 2014 | Users want the last occurrence, elapsed duration and longest dry spell. One reply describes using daily summaries to narrow subsequent archive access. | Provide event searches and continuous periods as cacheable recipes with threshold and gap rules. |
| [User group: JAS](https://groups.google.com/g/weewx-user/c/C-sWs_zMQBM/m/qFCh8XywAwAJ), 2022 | Explicit interest in overlaid years/months on basic shared hosting; transferred data volume is also discussed. | Define calendar alignment, resolution and point limits in the data contract; prepare historical comparisons. |
| [WeeWX #877](https://github.com/weewx/weewx/issues/877), July 2023, closed | An availability check failed to recognize computable XTypes without an archive column. | Distinguish an existing column, a theoretically computable value and an actually available observation. |
| [WeeWX #867](https://github.com/weewx/weewx/issues/867), May 2023, closed | Request for different locales per report. | Set language and number/time formatting per theme or output profile; a theme must not change global settings. |

The original issue texts for #924, #88, #93, #97 and #104, plus replies to #88
and #104, were also read through the public GitHub API. Search results alone
do not reliably show whether a fork has already fixed a bug. Recent proposals
for this project from the `hilman2` account were not counted as independent
evidence of community demand.

## Input from skins and extensions

| Project | Relevance to the abstraction layer |
|---|---|
| [Belchertown](https://github.com/poblabs/weewx-belchertown) and [New Belchertown](https://github.com/uajqq/weewx-belchertown-new) | Live updates, configurable charts, records and kiosk views. |
| [NeoWX, seehase fork](https://github.com/seehase/neowx-material) | Multiple axes, telemetry/battery, sensor-specific resolution and unit-dependent trend displays. |
| [JAS, current repository](https://github.com/weewx-extensions/jas) | Data for charts and tables; historical data need not be regenerated at every archive interval. |
| [AganetWX](https://github.com/aganet/weewx-aganetwx) | Discovers additional sensors. Year comparisons are cached daily by default; monthly records use the same aggregation. Changed settings may require manual cache refresh. This supports versioned theme registrations in our implementation. |
| [time_since](https://github.com/tkeffer/weewx-time_since) | Returns the last occurrence and elapsed duration for conditions. Validated conditions should replace arbitrary SQL expressions in our implementation. |
| [GTS](https://github.com/roe-dl/weewx-GTS) | Additional domain recipes for solar energy, growing degree sums, evaporation and alternative day boundaries. Evidence of specialized uses, not of a majority requirement. |

## Implementation proposal

The following points are our interpretation of the sources, not a new API yet.

1. Expose the existing observation catalog through the frontend: unit, meaning,
   format, allowed aggregates, dependencies, availability and data age.
   Custom sensors need the same treatment as standard observations.
2. Define one dataset for PHP output, charts and updates. Connect the existing
   live database; keep live values, archive values and the last valid observation
   explicitly distinguishable. Bounded JSON polling is sufficient initially for
   shared hosting. Live/archive overlaps must neither double-count rainfall nor
   lose daily extremes.
3. Align series to a common grid: calendar boundaries, gaps, coverage, multiple
   observations/archives and appropriate resolution. Rendering and chart library
   selection remain the theme's responsibility.
4. Add events and comparisons: last rain, dry spells, time above thresholds,
   month-to-date totals through the same calendar day, and year overlays.
   Comparison baselines exclude incomplete periods under a visible rule.
   Define the February 29 case explicitly.
5. Synchronize and prepare theme registrations; version schema/recipe changes.
   Extend the existing page budget and historical block cache, including
   diagnostics and understandable refresh schedules.

Use the demo theme as a consumer at each stage. Useful test cases include a
sensor without observations, null rainfall versus zero rainfall, ET totals,
mixed archive intervals, daylight saving time, November as the first available
month, two archives with different data freshness and live sender failure.

Several skins include forecasts and external weather services. Plan a separate
provider interface with attribution and its own refresh schedule for later.
The existing astronomy calculations remain the shared foundation.
