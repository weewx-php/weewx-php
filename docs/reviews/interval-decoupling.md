# Independent logger, upload and archive intervals

Reviewed 2026-09-05 by Codex (`/root`) in both local projects. This extends
[hardware ingest v2](hardware-ingest.md); the former exact-interval restriction
is superseded by the [shared contract](../native-ingest-v2.md).

## Behavior

- Collector polling uses the driver's current interval. Every queued hardware
  record retains its own original duration, including variable-duration backlogs.
  Upload cadence and PHP target cadence are independent. Status distinguishes
  polling interval from the last queued record's interval; the cursor commits
  atomically with the record.
- PHP preserves mapped hardware history inside each archive before releasing
  live-journal holds. Whole intervals can tile a larger archive field; LOOP fills
  other fields. Original coarse records survive live retention.
- Frontend historical queries choose coverage per observation without double
  rain. Fine/misaligned series carry a separate original-interval fallback layer.
  Daily queries, resumable computation, result/unit conversion, cache invalidation
  and public-feed limits account for that history. Mergeable cache states are
  disabled for hardware history because selection depends on query boundaries.
- Time-chart recipes draw original spans separately. Cumulative series preserve
  unknown periods. External readers of the standard WeeWX tables continue to see
  the fixed grid; PHP resolves the combined history when querying.

No interpolation or guessed within-interval rain/extrema. Conflicting overlapping
hardware spans are retained but excluded from historical aggregation. Crossing
midnight is not prorated into daily-specific statistics. Wind vectors reconstructed
from aggregate speeds/directions are estimates, not original sample statistics.
Physical Davis/USB devices were not tested.

## Verification

| Check | Result |
|---|---|
| PHP 8.1 Docker suite | 354 tests, 3,753 assertions passed |
| Collector Python 3.13 / WeeWX 5.5.0 Docker suite | 125 tests passed |
| Real TLS/PHP endpoint | Two separately adopted stations, original 5-minute hardware records into 1-minute PHP archives, correct period rain, five null minute points and one original logger fallback; lost ACK/deduplication passed |
| PHP archive tests | Complete 5/10-minute tiling, weighted temperature, rain sum, gust/direction, per-field LOOP fallback, original history after journal pruning |
| PHP frontend tests | Coarse-only history, overlap precedence, 5-to-7-minute boundary gaps, variable durations, conflicting spans, daily history without fixed rows, page/budget exhaustion and persisted resume, midnight boundaries, cache and unit conversion |
| JavaScript | Five chart/feed tests passed, including separate logger spans and cumulative gaps |
| HTTP compatibility | Existing `native_ingest` conformance passed |
| Mirrors | v2 schemas byte-identical; contracts equal after relative-link normalization |
| Static checks | PHP CS Fixer / PHPStan; Ruff on all collector source and tests passed |
| Separate-container Simulator run | Two stations, 541 events, 70-second receiver outage, collector/worker restart, lost ACK and replay; 72 archive values matched original WeeWX; 196.3 seconds |

The full Simulator result is [interval-decoupling-docker.json](interval-decoupling-docker.json).
The isolated `weewx-interval-e2e` containers, network and fixture volume were removed
after the successful run. The original hardware mismatch scenario is separately
covered by the real-PHP integration case above.

The collector test image also installs requests/dateutil for the concurrently
added PurpleAir integration fixtures. The older two-container runner now uses
the collector's current `weewx.conf` format instead of removed TOML configuration.
These changes affect only the test environment. Tests use temporary credentials,
databases and local TLS; no production station data was used.

Reproduce from the PHP project:

```sh
docker compose -f tests/docker/compose.yml run --rm unit
docker compose -f tests/docker/compose.yml run --rm lint
docker compose -f tests/docker/compose.yml run --rm conformance native_ingest
docker compose -f tests/docker/collector.compose.yml build unit
docker compose -f tests/docker/collector.compose.yml run --rm --no-deps unit
node --test --test-isolation=none tests/chart-recipes.test.mjs tests/feed-client.test.mjs
```

## Security review

**Security-Sensitive: YES. Reviewed by `/root`. OWASP categories checked: 10/10.**

Reviewed the changed database, archive selection, history query, accumulator,
serialization/cache/feed and collector runtime/spool paths, plus their callers,
tests, charts and shared contract. The v2 parser/receiver/receipt review remains
applicable; interval decoupling does not change authentication or the wire shape.

| Category | Result |
|---|---|
| A01 access control | Existing collector binding, station adoption and archive field mappings still gate input. No HTTP-selected database, SQL or driver execution. |
| A02 cryptography | Existing HTTPS and credential storage unchanged. Tests use generated local credentials; none included in reports. |
| A03 injection | Prepared values for new SQL; column/table identifiers come from validated catalog/schema. No interpolated observation values or shell input. Chart tooltips use rich text, tables use textContent. |
| A04 design | Whole-interval selection, per-field precedence, honest coverage, bounded fallback output, persisted cursor/checkpoints and original-history retention prevent duplication and fabricated resolution. |
| A05 configuration | No new public route, switch or error payload. Reads use existing row/statement/time budgets and indexed, paged queries. |
| A06 components | Composer and collector pip-audit reported no known vulnerabilities. No new production dependency for interval decoupling. |
| A07 authentication | Token binding, rotation, HTTPS and admission/rate limits preserved and existing receiver conformance passed. |
| A08 integrity | Original validated v2 metadata and deterministic event IDs retained. JSON only; no object deserialization. Additional SQLite history writes are transactional, ahead of hold release. |
| A09 logging | Existing fixed-message errors and spool status; added last-record interval contains only numeric metadata. No token or payload logging. |
| A10 SSRF | No new outbound PHP requests; collector retains configured HTTPS destination restrictions. |

Additional skill checks: no XML/DTD parsing; no unescaped HTML/template execution
added. New historical reads enforce the existing series/feed point limits,
including fallback intervals, and can persist/resume after budget exhaustion.

`composer audit --locked --no-interaction`: no advisories. Collector
`python -m pip_audit --progress-spinner off`: no known vulnerabilities; the local
editable collector package is reviewed as source because it is not on PyPI.

No unresolved security findings. **Security Review Status: PASS.**
