# Async pipeline and rain audit — 2026-09-06

## Result

HTTP tick and visitor endpoints only persist coalesced filesystem wakeups and
launch detached CLI workers. Intake commits the accepted packet before enqueueing.
There is no inline or FastCGI-after-response calculation fallback. Archive,
analysis, services and maintenance have independent process ownership locks.
Long analytics do not hold the archive writer or any live/ingest SQLite transaction.
Recent archive intervals get a short priority turn before historical replay.

Intake opens SQLite with zero busy timeout: failed persistence returns 503 rather
than acknowledging a lost packet. Finite filesystem and process-launch latency
still exists; no application can guarantee zero latency under host/OS failure.
Unsupported detached execution leaves durable work and reports external-required.
Cron must provide a CLI worker on those hosts. Visit polling while visible is
throttled to once per minute for the installation. Worker claims survive crashes;
subsequent triggers resume unfinished work. Runtime profiles retain per-SAPI limits.

WAL backup snapshots are pinned together under short write reservations, then
reservations are released before copying. A dedicated backup lock prevents
concurrent snapshot owners and staging cleanup races. Non-WAL databases retain
the prior blocking backup behavior; production uses WAL. Large individual SQL
or filesystem operations remain cooperative-budget boundaries.

## Rain semantics

Production mapping was inspected read-only: day/event/hour/month/week and
rainRate are assigned; yearRain and totalRain are received but not archive
columns. Auxiliary inputs now pass through the selected gauge, archive-unit
conversion, calibration and quality controls without becoming archive columns.
Explicit rain sources and exclusions take precedence. Independently declared
custom counters do not borrow another gauge's counters.

All valid counters are seeded. The longest continuous one is preferred, with
cross-checks against others. Counter return/fallback cannot double-count an
already booked interval. Falling counters, conflicting deltas and ambiguous
period boundaries are not interpreted as rainfall; their new baselines allow
subsequent increments. Another continuous counter can cover a reset. Daily,
monthly and yearly boundaries are conservatively checked in the archive zone;
console-specific nonstandard reset schedules or an undetectable reset-and-rise
within an unobserved gap cannot be reconstructed from counters alone.

True LOOP interval rain retains hardware precedence. Hourly/24h rolling amounts
and rates are not differences of cumulative counters. Hardware archive amounts
retain the existing span rules. Long-gap recovered amounts are booked once in
rain but never turned into a current inferred rain rate. Gap/reset evidence in
weewx_rain_evidence records the known interval and uncertainty outside WeeWX's
observation schema; replacing a built interval replaces its evidence atomically.
Affected earlier cache spans are invalidated too.

Dry spells keep strict completeness. Equal cumulative readings can establish
missing dry time; a visible reset or positive/interday recovery vetoes a false
dry classification. Historical imported counters are range/units/reset checked.
No synthetic redistribution to individual days. The theme's global pending
banner no longer aggregates unrelated expiring analysis queries. Annual rain
refreshes with the archive; month-to-date comparison refreshes hourly.

Nested hardware-day computation now closes the candidate cursor before reading
its resumable inner day. This fixes the previously failing interrupted-read test.

## Validation

- 431 tests, 4347 assertions passed in the applicable local suite. Two existing
  environment-dependent cases were excluded: POSIX mode bits on Windows, and a
  CLI upload test whose fixture assumes network access is disabled.
- PHPUnit warns that local PHP has no pcntl timeout enforcement.
- PHPStan maximum level: no errors.
- New cases cover 12h cross-midnight gaps, resets, returning counters, conflicting
  values, sender isolation, WU/rolling semantics, unit conversion, archive schema
  independence, duplicate storage, gap evidence, intake with worker locks held,
  enqueue coalescing and concurrent writes during a frozen WAL backup snapshot.
- Final affected-area run: 140 tests, 1097 assertions passed; added regression
  assertions also require mapped cumulative readings to stay calculation inputs.
- Final PHPStan run: no errors. Runtime dependency audit: no packages to audit.

## Security Review

Security-Sensitive: YES. Reviewed by /root with security-review skill.
OWASP categories checked: 10/10. Status: PASS for scoped changes.

| Category | Result |
|---|---|
| A01 Access control | Tick secret comparison retained; visitor POST/header/same-origin checks retained; no worker parameters from HTTP. Admin mapping auth/CSRF unchanged. |
| A02 Cryptographic failures | No credentials changed or exposed. No secrets in response, queue filenames or new evidence. Existing HTTP Ecowitt transport retained as required by hardware. |
| A03 Injection | Worker lanes allowlisted. Executable, script and server configuration arguments shell-escaped; user payloads never reach shell. Evidence SQL parameters bound; counter SQL names from fixed allowlist. HTML status labels escaped. |
| A04 Insecure design | Nonblocking ownership locks and durable claims. Commit-before-ack. Explicit measurement semantics and per-source baselines; no implicit merging of gauges. |
| A05 Misconfiguration | Queue in protected data directory; worker CLI-only. Unsupported launch has no synchronous fallback. No public debug endpoint added. |
| A06 Components | No runtime dependencies introduced; composer audit --no-dev reports no packages to audit. |
| A07 Authentication | Existing station authentication/adoption and tick-token checks retained. |
| A08 Integrity | Atomic archive/evidence writes, idempotent replay, private coalesced queue, stable cache signatures. WAL snapshot consistency tested with concurrent writer. |
| A09 Logging | Existing ingestion rejection and worker errors retained; HTTP failures log exception classes. Detailed work remains in private logs/cache status. |
| A10 SSRF | No user-configurable HTTP dispatch URLs added; workers launch local fixed scripts. Existing network service policies unchanged. |

No unresolved critical or high security findings in this change. Host CPU,
filesystem availability, actual sensor reset metadata and packet arrival cannot
be guaranteed by asynchronous dispatch.

## All-Inkl deployment and live verification

Changed code was deployed to `/www/htdocs/w01a0e03/_weewxphp` using allowlisted,
checksummed atomic replacements. Configuration and credentials were unchanged.
Previous code copies are local only, not retained as webspace originals.

The first FPM launch exposed a host-specific old PHP executable in PHP_BINDIR;
versioned CLI paths now take precedence (`/usr/bin/php85` on this host).
The four startup lanes also exposed writer-lock contention: detached owners
retry briefly under their lane lock so fast maintenance cannot starve archiving.
HTTP still never waits on those locks. The retry uses a real wall-clock deadline.
The worker error log stopped growing after the interpreter selection fix.

- Authenticated HTTP test used the deployed `public/tick.php` on the same host
  with isolated private configuration/storage and a loopback test server. With
  archive and analytics locks held, all three responses were 202 in 0.0136,
  0.0261 and 0.0209 seconds. No production tick token was enabled or altered.
- Production visitor endpoint: 204 in 0.064–0.087 seconds; genuine FPM requests
  launch CLI workers. The public token-protected endpoint correctly stays 403
  without a configured token; station/visitor wakeups do not require it.
- Holding both production analysis ownership locks for 55 seconds did not stop
  intake or archiving: two real packets arrived and the archive advanced 60s.
  No synthetic production station packets were submitted.
- Production snapshot after deployment: HTTP 200, empty global pending banner,
  169 ready temperature points over seven days, seven ready rainfall buckets,
  longest verified dry spell 3 days (2–4 September), rain rate in mm_per_hour.
- Actual production mapping uses total/year/month/week/day/event counters as
  auxiliary inputs. Neither yearRain nor totalRain is an archive column.
- Rain sums spanning only part of a wet multi-day gap are unknown; a range
  covering the whole gap retains the recovered amount. A regression test checks
  both the individual-day result and the full-span sum.

## Follow-up: four dry chart days versus a three-day spell

The production archive has two gaps on 1 September: 04:30–04:32 and
18:29–18:30 Europe/Berlin (three missing one-minute records). Both dayRain
and monthRain are zero on either side. A whole-day counter bracket crossed
the August reset and incorrectly rejected all of 1 September.

RainCoverage now merges observed zero-rain intervals and stored evidence,
then proves each remaining gap separately. Its indexed day-range query emits
at most 32 gaps per page; a saved proof cursor resumes after budget exhaustion.
Known positive rain in an overlapping imported logger interval vetoes a dry
counter proof. Actual reset boundaries remain unproven without another source.
Daily rain charts use the same proof as dry spells. An unproven zero sum is
unknown; today's observed portion can show zero without counting a full day.
The analytics cache version changes to rebuild previously rejected days.

Validation: 65 frontend tests / 413 assertions passed, including month-reset
regression, unknown chart gaps, imported rain precedence and serialized resume.
The sole PHPUnit runner warning is the unavailable Windows pcntl extension.
PHPStan maximum level passes. The original WeeWX database yields four days
starting 1 September; the complete historical spell calculation used 65 SQL
statements, 331 returned rows and approximately 17 ms locally.

Security follow-up: existing OWASP review remains applicable (10/10, PASS).
New timestamps use bound parameters; SQL structure and table/column names are
fixed. Reads remain read-only and paged with persisted progress; no intake,
authentication, deployment credentials or archive records are changed.

Deployed four code files to All-Inkl with matching SHA-256 checksums and
unchanged configuration. Public HTML and JSON now both show four dry days
starting 01.09.2026; all four daily chart points have complete rain evidence.
The running day retains partial coverage, and the global status is empty.
Visitor wakeup returned HTTP 204 in 0.147 seconds; recalculation ran in the
detached worker. Live archive timestamp advanced to 06.09.2026 15:16 Berlin.

## Source semantics checked

- [WeeWX observation and archive semantics](https://weewx.com/docs/5.2/custom/introduction/).
- [Ecowitt configurable daily/weekly resets](https://www.ecowitt.com/shop/forum/forumDetails/338).
- [Ecowitt rain reset settings](https://oss.ecowitt.net/uploads/20250408/WS%20View%20Plus%20%26%20Web%20UI%20Manual%20%28Generic%29.pdf).
