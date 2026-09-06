# Full installation backups — implementation and security review

Reviewed 2026-09-06 by `/root`. Security-sensitive: **YES**.

## Changes

- Full daily SQLite/configuration TAR backups, configurable retention (three
  days by default), manual CLI and admin scheduling, authenticated streaming
  download, manifest and SHA-256 verification.
- SQLite write reservations span all source snapshots. Separate read-only
  source connections include WAL without copying live sidecar files.
- Recovery validates into a new private directory, relocates configuration and
  history, clears active sessions and maintenance scheduling, then publishes by
  rename. Existing installations are never overwritten or activated implicitly.
- Backup and maintenance failure affect tick status; the overview and CLI show
  overdue backup/tick and storage warnings.
- Configuration serialization now writes parent values before child sections;
  newly added settings previously changed scope when read back.

## Verification

Local PHP 8.5.1, SQLite3 and Phar, PHPUnit 10.5.64:

- 15 dedicated backup tests, 109 assertions: passed.
- A 32 MiB SQLite payload was backed up and restored while a separate PHP
  process updated ingest/live databases transactionally. Matching source
  revisions survived; the large-file test run peaked at 12 MiB PHP memory.
- Coverage includes WAL-only committed measurements, credentials, password
  preservation, session invalidation, restored tick execution, daily scheduling,
  manual requests, exact retention boundary, failed/interrupted work, staging
  cleanup, retry backoff, disabled automation, CLI recovery without an existing
  config, authenticated UI settings, traversal, corrupt hashes and TAR links.
- Broader local suite: 370 tests / 3913 assertions passed with three explicit
  exclusions, before adding the last two dedicated tests above. Excluded tests:
  `testWritesAtomicallyAndKeepsTheMode` requires Unix mode semantics;
  `testTheUploadsFromTheCommandLine` assumes Docker's disabled network;
  `testDailyQueryResumesAfterBudgetExhaustionInsideOneDay` fails in concurrently
  modified hardware-history code outside this change.
- PHP CS Fixer reports no changes for modified/new backup-related files.
- PHPStan at the project maximum level passes for the changed core/admin/config/
  backup code and its tests. A whole-repository run also reports unrelated CLI
  `$argv` and concurrent sensor-source test diagnostics in this local environment.
- `composer audit`: no security vulnerability advisories. Adding `ext-phar`
  changed no locked package versions.
- `git diff --check`: passed.

Docker's named pipe denied access from this session despite the service running;
the PHP 8.1 Linux/container conformance matrix was not executed. Browser access
to the temporary loopback test server was denied, so visual browser QA remains
unverified. Controller/rendered-HTML tests passed. The temporary server was stopped.

## Security review

Reviewed `src/Backup/*`, changed admin controller/response/download adapter,
configuration parsing/writing/settings, maintenance/tick outcome integration,
CLI commands, and tests. Existing authentication and CSRF checks remain the
authorization boundary; no new public endpoint was introduced.

| OWASP category | Result |
|---|---|
| A01 Access control | Downloads require an authenticated admin session; anchored server filename pattern; no arbitrary user paths; invalid names return 404. |
| A02 Cryptographic failures | Existing HTTPS requirement and no-store headers apply. Private staging directories and 0600 package files. TAR contains secrets and is not encrypted; trusted private storage is documented. |
| A03 Injection | SQL values bound; dynamic table names are fixed allowlists. TAR paths are allowlisted and regular-file checked. UI output escaped and download names exclude header metacharacters. |
| A04 Insecure design | Coordinated DB snapshots, validated staging, successful-publication-only retention, interrupted-work retry, new-directory-only recovery and session invalidation. |
| A05 Misconfiguration | Phar dependency explicit; no executable PHAR generation or generic extract-to-disk API. Data kept outside web root. |
| A06 Vulnerable components | Composer audit clean; no new third-party runtime package. |
| A07 Authentication failures | Existing admin login/CSRF/HTTPS controls retained; download and request authorization tests pass; restored active sessions removed. |
| A08 Integrity failures | Versioned manifest, size/hash checks, SQLite quick checks, unexpected entries/links rejected. Hashes detect corruption, not malicious replacement; trusted restore source required. |
| A09 Logging/monitoring failures | Admin request/download audit records, backup status and timestamps, generic error logs without credentials, failed maintenance/backup reflected in tick result. |
| A10 SSRF | No new outbound network operation; restore is a local CLI operation. |

No unresolved critical/high findings were identified in this change.
Security review status: **PASS within the documented deployment assumptions**.

## Operational limits

The PHP SQLite API is synchronous per database. Full backups can exceed the
archive `time_budget`; large installations need a suitably configured CLI cron.
SQLite writer reservations can cause ingest busy-timeout/retry responses during
copying. Existing PHP execution limits are not bypassed. The free-space check is
an estimate; I/O failure still fails safely without pruning older packages.
Application code/themes are not included, recovery does not activate itself, and
host-loss recovery requires an independently stored copy.
