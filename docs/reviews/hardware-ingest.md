# Hardware ingest v2 review

Historical review of the first v2 implementation. Its exact-interval restriction
is superseded by [interval decoupling](interval-decoupling.md); use that review
and the current contract for supported interval behavior and latest verification.

Reviewed 2026-09-05 by Codex (`/root`). Applies to the local changes in
`weewx-php` and `weewx-php-ingest` for `[StdArchive] record_generation = hardware`.

## Result and boundaries

PHP accepts v1 LOOP and v2 mixed LOOP/hardware batches. Hardware events retain
station identity, original units and interval, with normal admission and durable
receipts. Exact matching hardware intervals replace software archive values;
late arrival uses existing replay to repair archives and daily summaries.

The collector uses real WeeWX startup/periodic logger APIs in each isolated engine,
serializes after callbacks, and commits the hardware cursor with the local event.
Unsupported logger APIs fall back to LOOP collection. The setup probe still tests
a current LOOP reading without draining history.

Hardware aggregates with a different duration/end remain in the live journal and
are logged as mismatches. No splitting or coarser aggregation is implemented.
Physical Davis/USB hardware has not been tested; driver API tests use a logger
fixture on a real StdEngine and the original Simulator for fallback.

## Verification

| Check | Result |
|---|---|
| PHP Docker suite, PHP 8.1.34 | 345 tests, 3,700 assertions passed |
| Collector Docker suite, Python 3.13 / WeeWX 5.5.0 | 109 tests passed, no skips |
| Actual PHP endpoint behind verified test TLS | Both LOOP and hardware admission, two station identities, lost ACK and duplicate retry passed |
| Hardware lifecycle | Startup, periodic fetch, callbacks, restart cursor, full-spool atomicity, read failure retry, unsupported-driver fallback and explicit software/no-catchup passed |
| PHP archive semantics | Hardware rain precedence, LOOP extremes, logger-only gap recovery, late daily repair, span mismatch, station selection and US-to-METRICWX conversion passed |
| v1 HTTP conformance | `native_ingest` passed |
| Contract mirrors | v2 schemas byte-identical; documents identical after relative-link normalization |
| Static checks | PHP CS Fixer / PHPStan and Collector Ruff passed |

Reproduce from the PHP project:

```sh
docker compose -f tests/docker/compose.yml run --rm unit
docker compose -f tests/docker/compose.yml run --rm lint
docker compose -f tests/docker/compose.yml run --rm conformance native_ingest
docker compose -f tests/docker/collector.compose.yml run --rm --no-deps unit
```

The collector test image and sibling collector checkout are required, as described
in [the Docker integration setup](collector-docker.md). Tests use temporary data,
fixtures and generated credentials; no production station or receiver was touched.

## Security review

**Security-Sensitive: YES. Reviewed by: `/root`. OWASP categories checked: 10/10.**

Changed sensitive files reviewed: PHP `NativeEvent.php`, `NativeParser.php`,
`NativeReceiver.php`, `CollectorStore.php`, `Archiver.php`; collector `protocol.py`,
`runtime.py`, `spool.py`, `hardware.py`; associated schemas, tests and contract.

| Category | Result |
|---|---|
| A01 access control | Existing token-to-collector binding and pending/adopted/blocked admission apply to both packet kinds. No caller-selected sender or new privilege. |
| A02 cryptography | Existing verified HTTPS and hashed credentials preserved. UUIDv5 identifies logger events only; it is not an authentication token. |
| A03 injection | Numeric observations and bounded module/UUID metadata only; prepared SQL. No driver import, shell execution or HTML output added on PHP. |
| A04 insecure design | Hardware span must exactly match archive span before use; prevents overlap/double rain. Local cursor and event commit together; server receipts and replay commit together. |
| A05 configuration | Explicit version handling; unsupported kinds/intervals fail before discovery. No report/database services enabled on collector. No debug responses or new public routes. |
| A06 components | Dependency audit found no known vulnerabilities; no dependencies changed. |
| A07 authentication | Existing HTTPS, token validation, rotation/disable, rate and admission checks unchanged. Tests retain v1 coverage. |
| A08 integrity | Archive digest includes interval/kind; LOOP digest remains compatible. Deterministic hardware IDs, matching ACK version/identity, finite values, integer-second duration and duplicate-key validation. |
| A09 logging | Hardware fallback/retry/cursor visible in local status; PHP logs span mismatch. Error messages contain fixed reasons, station IDs or exception classes, not credentials or payloads. |
| A10 SSRF | PHP performs no outbound requests for hardware events. Collector retains configured HTTPS destination/redirect restrictions. |

The skill's additional XML, deserialization and XSS checks are also covered: no XML
parser, object deserialization or rendering path was introduced; JSON is strictly
validated. Receiver bounds, spool quotas and history age limits continue to apply.

Audits run: `composer audit --locked --no-interaction` reported no advisories;
collector environment `python -m pip_audit --progress-spinner off` reported no known
vulnerabilities. The local editable collector package is not on PyPI and is reviewed
as source rather than audited against PyPI. Composer emitted a read-only mount
ownership warning for Git; the advisory audit itself completed successfully.

No unresolved security findings. **Security Review Status: PASS.**
