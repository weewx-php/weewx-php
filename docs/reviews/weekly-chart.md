# Weekly chart starvation

The rolling seven-day chart used relative 3600-second buckets. Every arriving minute shifted all historical bucket boundaries. A first run exhausts the 256-statement worker slice after roughly 125 of 168 buckets; archive invalidation on the next station record restarts the work and the shifted spans miss all previous chunks. The plot remained pending indefinitely during continuous reception.

The theme now requests calendar-hour buckets. Completed historical hours retain their identity across advancing windows and can be reused after current-day invalidation. Limits and archival data are unchanged. An empty pending plot now says that computation is in progress rather than claiming no data exists.

Regression test seeds seven days, deliberately yields the first worker run, writes another station record with the real day-level invalidation path, then requires completion in the second worker run. It fails with the old relative buckets and passes with calendar hours. Demo theme suite: 4 tests, 25 assertions pass; PHPStan max and diff checks pass. Existing Windows pcntl warning concerns test timeout enforcement only.

Security review: changes use the existing validated query API and static escaped UI text; no new endpoints, credentials, SQL interpolation or authorization paths.


## Host precision and parallel workers

ALL-INKL web PHP serialized floats with precision 100, CLI with -1. The old configuration hash changed between those environments although the configuration was identical. Source revision changed and discarded analytics chunks. `CacheJson` temporarily selects round-trip precision and restores the host value even on exceptions. Cache and recipe identities now remain stable. A regression switches between -1, 14, 17 and 100, preserves chunks, and still invalidates a real coordinate change.

The former worker also stopped after one 256-statement slice per job even when almost the entire tick budget remained. It now drains successive durable slices within the runtime window. `analytics.lock` permits one analysis worker; `tick.lock` is released before calculation and acquired briefly for configuration/recovery only. Separate station ticks may archive while analysis reads. Request work and source revision are fetched together; chunk/state writes validate that revision transactionally. Old work cannot reinsert chunks after a concurrent invalidation. Unaffected historical chunks remain available.

Automatic `time_budget = 0` uses PHP's readable execution limit with headroom. Without a limit, profiles start at 20 seconds and increase on full useful windows, capped at 300 seconds. Archive and analysis phases, and CLI/web SAPIs, have separate persistent profiles. Checkpoints left by an interrupted process reduce the next safe window without claiming an exact hidden host timeout. No sleeps or deliberate timeout probes. Manual ceilings remain supported. Analysis outcomes are included in tick history; logs include runtime windows and calculation outcomes. Overlapping runs are ordered by their start times in history.

Visit requests finish their FastCGI response before background work; authorization/request-origin checks precede that response. Other SAPIs retain synchronous fallback. Ecowitt already used FastCGI completion.

Validation: targeted frontend, tick and archive-settings suites passed 68 tests / 436 assertions. New tests cover single-worker draining, availability of the archive writer lock, rejection of a duplicate worker, interrupted-host learning, real configuration invalidation and stale writes from concurrent generations. PHPStan max passes. Broad Windows runs also exposed pre-existing HardwareHistory resume and POSIX permission tests; the former fails identically using the HEAD Computation class, while Windows cannot preserve POSIX file modes. The pcntl warning only disables test timeout enforcement.

## Security review

Security-sensitive: yes. Reviewed by /root using security-review. OWASP categories checked: 10/10.

- Injection: all new state/cache SQL is parameterized. No new commands from user input.
- Authentication and access control: tick credentials, station credentials, visit origin/header checks and admin CSRF checks remain enforced.
- Sensitive data: runtime profiles contain only numeric timing/status; logs contain no credentials. Private state/cache paths remain outside the public root.
- XML/external entities: no XML parsing changes.
- Misconfiguration: configured budgets are range-validated; automatic mode has finite ceilings and margins, without disabling PHP timeouts.
- XSS: UI change is a static translated label; no dynamic HTML added.
- Deserialization: JSON shape/type checks, no PHP object deserialization.
- Dependencies: no runtime dependency changes introduced by this fix.
- Monitoring: interrupted profiles warn, normal slice and runtime outcomes log, analytics failures appear in tick outcomes.
- Concurrency/integrity: separate nonblocking worker locks, short generation-checked cache transactions, guarded publication, durable checkpoints and guaranteed lock cleanup on normal exceptions.

No new security findings.


Live deployment verification (2026-09-06): enabled automatic runtime (`time_budget=0`) under the archive writer lock after validating the new config. ALL-INKL FPM reports max_execution_time=60; logs show 54.0 seconds for the archive window and ~53.9 for analysis. Consecutive real station ticks completed 16 analytics jobs, pending=0, failed=0. Archive grew to 17,992 rows and the latest record advanced. Public 7d response: ready, 169 points, rendered SVG curve, no empty-chart element. Visit POST returned 204 in 0.166 seconds. No synthetic station data or archival history edits. An additional regression confirms a station tick writes its archive record while analytics.lock is held (5 assertions pass).
