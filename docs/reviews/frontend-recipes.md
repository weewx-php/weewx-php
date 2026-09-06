# Frontend extensions review

Date: September 5, 2026. Implementation of the
[community requirements](../frontend-community-research.md) and six agreed
improvements. Usage: [Frontend recipes](../frontend-recipes.md).

## Implemented scope

| Area | Result |
|---|---|
| Central output | Theme-specific output profile, unit groups and custom observations, safe placeholders, scalar and series conversion including temperature differences |
| Time reference | Archive, current clock or fixed instant; calendar dates versus fixed durations; same selection for astronomy |
| Theme management | Atomic synchronization, shared ownership, release of exclusive registrations, CLI preflight and preparation |
| Prepared analyses | Month-to-date comparisons, qualified rankings, dry spells, events and exact quantiles of interval aggregates; with exclusion reasons and comparison years |
| Shared calculation | Exact mergeable states for safe scalar aggregates; separate raw/daily weighting; correctable closed blocks |
| Operation | Cache-only instances, priority and aging, resumable worker, query and worker diagnostics |
| Community additions | Observation catalog and availability, semantic series defaults, shared grid and year overlays, explicit live journal and fixed JSON dataset |

There is no new service dependency. The existing tick processes the analytics
database. Public pages do not trigger analytics post-processing. The guide states
the domain limits of live derivations, event resolution and quantile populations.

## Function and performance

- Complete unit test run on PHP 8.1.34: 315 tests passed.
- Frontend regression cases: output profiles, US/metric units, pending values,
  delta conversion, daylight saving time/midnight, variable archive intervals,
  ET totals, null gaps, theme ownership and removal, closed states, subsequent
  archive corrections, dry/wet intervals, comparison years, leap year exclusions,
  quantiles, two archives, live failure and last valid value.
- PHPStan at the highest project level: no errors.
- WeeWX conformance: all executed checks passed, including 128 frontend
  aggregates and 28 calendar boundaries. Optional weewx-evo comparisons for
  Windy/Weathercloud/InfluxDB/MQTT were skipped because sources were not mounted.
- Ten-year archive: 1,051,776 archive rows and 3,652 daily summaries.
  Measured cold sum access: 57.1 ms; bounded raw-data miss: 25.3 ms; warm access:
  0.3 ms with zero archive read budget. Daily ranking across all 3,652 days:
  30 resumed worker runs, 1,018 ms total. These are local test-environment
  measurements, not runtime guarantees for every web host.
- Local demo: desktop and 390-pixel mobile viewport checked; no horizontal
  overflow, seven rain bars and no browser errors.
- JSON endpoint: GET 200, seven daily bars and 168 hourly points with `range=7d`;
  POST 405. The previous viewport was restored.

## Security Review

**Security-Sensitive:** YES. Reviewed by the implementing agent using
`C:/Users/manuel/.agents/skills/security-review/SKILL.md`. All ten categories
checked; no open high or critical findings.

| Category | Result |
|---|---|
| Injection | SQL values bound; dynamic observation/table names restricted by the existing identifier validator. Conditions, priorities, intervals and recipe types allowlisted. No arbitrary SQL recipes. |
| Authentication | No new login or credential lifecycle. Diagnostics/manifest management through local PHP/CLI interfaces only. |
| Sensitive data | JSON contains the fixed weather dataset, no raw packets, sender identities, configuration, paths, credentials or diagnostic logs. Generic HTTP errors. |
| XML/XXE | No XML parser or DTD access added. |
| Access control | HTTP selects only the fixed demo definition; no client-selectable file paths or archives. Live mapping respects configured senders. New endpoint accepts GET only. |
| Configuration | CSP limited to same-origin styles/scripts/connections; nosniff, no-store. Archive and live journal read-only; analytics separate. |
| XSS | Value/theme HTML escaped, JSON uses HEX flags; polling sets textContent, not innerHTML. |
| Deserialization | JSON with bounded depth and validated recipe/result structures; no unserialize. Theme PHP comes exclusively from trusted local files. |
| Components | No additional runtime dependency. `composer audit --locked --working-dir=/opt/build`, including development packages: no known security advisories. |
| Logging/monitoring | Worker errors and next attempts stored; steps expose sources, cache reuse and budget use. HTTP error details appear only in the server log. |

Additional operational checks: no overlapping browser polls, eight-second request
timeout, pause in hidden tabs; fixed query/point limits; worker does not publish
during a marked archive mutation or after a source token change. Shared
registrations survive theme synchronization. Cache state merging accepts only an
exact disjoint partition and does not use averaged medians.

**Security Review Status:** PASS.
