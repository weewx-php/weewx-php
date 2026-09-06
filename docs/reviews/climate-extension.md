# Climate extension review — 2026-09-06

Scope: generic local extension loading, namespaced read-only theme tags,
independent bounded workers, optional worldwide ERA5 climate package and an
opt-in comparison block in the demo theme. Existing unrelated working-tree
changes were retained.

Follow-up: the climate package and its tests now live in
[weewx-php/extension-climate](https://github.com/weewx-php/extension-climate).
Core installer and catalog work is recorded in [extension-store.md](extension-store.md).
Counts below describe the earlier combined checkout.

## Validation

- 116 tests / 839 assertions passed: Extension, Forecast, Frontend, Tick, Backup.
  Run locally with PHP 8.5.1, PHPUnit 10.5 and a workspace temporary directory.
- Additional detached-services-lane coverage passed afterwards: all 11 extension
  tests / 139 assertions passed, including operation with uploads and forecasts disabled.
- Tick integration follow-up: 24 Extension/Tick tests / 222 assertions passed.
  The visitor-tick test simulates the existing process launcher, advances the
  climate import on successive permitted visits, and checks the visit gate.
  No extension-specific scheduler or cron is involved. PHPStan for extension
  tests and the changed test's formatting check passed. Production dispatch
  behavior is unchanged; the HTTP launcher still requires process-start support.
- An earlier 133-test run including Config had one failure in the existing
  `ConfFileTest::testWritesAtomicallyAndKeepsTheMode`: Windows returned mode 0666
  where the test expects POSIX 0640. No permission behavior was changed here.
- Full PHPStan analysis passed with the project's PHP 8.1 target and maximum level;
  the new `extensions/` directory is included in the normal lint configuration.
- Changed PHP files were formatted with the project rules.
- Real local PHP import: Sydney, 1991–2020, 30 annual requests plus final aggregation;
  451,201 bytes cache with ±5-day smoothing, 4 MiB PHP peak memory, both cURL and stream HTTPS succeeded.
  [Recorded measurements](climate-live.json). A subsequent normal tick reported
  extension status `ready` and zero measured archive records.
- Visual browser verification was denied by the browser permission policy.
  No visual sign-off is claimed. Existing demo-theme rendering tests passed.
- Docker tests could not run because access to the local Docker pipe was denied.
  No actual shared hosting server was accessed.

Covered cases: successful resumable import, exact annual date alignment,
rejected incomplete year and delayed retry, persistent earlier years, cache
invalidation on coordinates, no theme-side writes/downloads, normal statistics,
leap-day population, null versus zero, coverage threshold, equal-year-weighted
11-day smoothing, annual wraparound, smoothing changes without downloads, unit conversion,
Southern Hemisphere DST, independent locks and time limits, customer credential
redaction, disabled/missing packages and atomic registration failure.

## Security review

**Security-Sensitive: YES. Reviewed by: primary agent, security-review skill.**

| OWASP category | Result |
|---|---|
| A01 Access control | PASS. Activation is operator-owned file configuration; no new public mutation endpoint. Packages have separate identifiers and storage. |
| A02 Cryptography | PASS. Fixed HTTPS endpoints and enabled TLS verification; process-local CA path in the Windows probe. API keys are absent from returned tags and captured transport exceptions. |
| A03 Injection | PASS. No SQL or shell execution in the extension; query parameters are encoded, tag options are validated and rendered values escaped. |
| A04 Design | PASS. One annual request per worker invocation, persistent progress, 128 KiB response cap, independent locks and ten-minute retry delay. |
| A05 Configuration | PASS. Disabled by default. URL wrappers and UNC paths cannot become package entries. Missing packages do not prevent core configuration loading or recovery. |
| A06 Components | PASS. No additional runtime libraries. `composer audit --no-dev --format=json`: no packages to audit. Host PHP/cURL/OpenSSL maintenance remains the host's responsibility. |
| A07 Authentication | N/A. Existing admin authentication is unchanged; no new sessions or credentials provisioned. |
| A08 Integrity | PASS. Complete dates and units are validated before annual files are published; JSON, not PHP object deserialization. Atomic cache replacement. Only explicitly activated trusted local PHP executes. |
| A09 Logging | PASS. Worker failures are logged; public status omits exception details. A test verifies customer keys cannot leak through transport errors. |
| A10 SSRF | PASS. Climate fetch targets only the fixed public/customer Open-Meteo hosts. No user-controlled URL or redirect following. |

The trusted-package boundary is explicit: PHP extensions can execute arbitrary
PHP and are not sandboxed. Reader purity is a documented contract, supported by
separate read and worker contexts. Packages must not be installed from untrusted
upload directories. No new findings requiring deferral.

**Security Review Status: PASS.**

## Limits

ERA5 values are reanalysis, not station measurements. The default window covers
five days before and after the date; each reference year contributes equally.
Calendar windows wrap within the reference year's annual cycle. Leap-day
centres use leap years only. No hourly normal or probability band is implied. Missing years do
not become zero, and the 80% availability rule is not described as WMO
certification. DWD remains an evaluated future provider. Extension caches and
code are not included in existing installation backups; both are documented,
and this package's cache can be regenerated. Extensions currently activate through
configuration files rather than an admin installation UI.
