# Forecast review — 2026-09-06

Historical review of the earlier core implementation. It has now moved to
[extension-forecast](https://github.com/weewx-php/extension-forecast).
[Extraction and deployment review](extension-settings-and-forecast.md).

Open-Meteo forecasts are opt-in per archive. Tick fetches them into a separate
local cache; `$wx->forecast()` exposes daily reports and hourly series with
the existing value formatting, output profiles and unit conversion. Admin,
CLI and the demo theme use the same configuration and cache.

## Validation

- PHP 8.1 container: **389 tests, 4,054 assertions passed**.
- New coverage: fetch scheduling and tick locking; bounded timeout and response
  sizes in both HTTP transports; stale fallback and retry delays; malformed
  data; cache invalidation; absent/disabled forecasts; preservation of null and
  zero; output profiles; local dates, both DST changes, western timezones and
  midnight refresh; admin persistence; a one-day forecast after its last hour.
- PHPStan at the repository's maximum level: no errors.
- PHP CS Fixer: 28 forecast-related source/test paths clean. The final
  repository-wide run reported formatting in a concurrently rewritten demo
  template; those ongoing template edits were preserved.
- Live HTTPS smoke test in PHP 8.1: HTTP 200, 168 hourly values and seven days.
  CLI `forecast status berlin` returned `ready`; `forecast fetch berlin`
  returned `fresh` without another request.
- Browser review with temporary Berlin forecast data: desktop and 390 px wide
  layouts, seven forecast cards, German numbers, fetch time and attribution.
  This checked the forecast presentation before a concurrent demo redesign.
- `git diff --check`: passed.
- WeeWX conformance: 15 checks passed; `ingest` failed with
  `pending requests wrote live.sdb`. The same failure was reproduced by running
  only `ingest` from a clean `git archive HEAD` in a separate temporary directory.
  Existing `IngestCommand` opens the live collector even for `ingest endpoints`;
  this is outside the forecast change.

## Security review

**Security-Sensitive: YES. Reviewed by: main agent. OWASP categories: 10/10.**

| Category | Result |
|---|---|
| A01 Access control | Existing authenticated/CSRF-protected admin save; public theme access only reads local forecast data. |
| A02 Cryptographic failures | Fixed HTTPS API, certificate verification retained, no credentials introduced. |
| A03 Injection | Numeric JSON fields and timestamps validated; no provider HTML or SQL; theme output escaped. |
| A04 Insecure design | Opt-in fetches, shared tick lock, budget-limited requests, ten-minute retries, stale fallback. |
| A05 Misconfiguration | Disabled by default; forecast cache is under the private data directory. |
| A06 Components | No dependencies added; `composer audit --locked` found no vulnerability advisories, including dev dependencies. |
| A07 Authentication | Uses the existing admin session and revision checks; no new authentication flow. |
| A08 Data integrity | Bounded JSON depth, row counts, equal series lengths, ordered timestamps and finite numeric values; atomic cache replacement after validation. |
| A09 Logging | Fetch failures logged; fetch timestamps/status available through CLI and tick output; stale status shown in theme. |
| A10 SSRF | Provider host/path fixed in code; only validated location/time parameters; redirects disabled in both transports. |

HTTP response size limits are enforced during transfer and survive timeout
clamping. Cache paths hash archive IDs; fingerprints include location, timezone
and requested horizon. Readers do not create directories, perform network
requests or deserialize PHP objects. Invalid responses cannot replace the last
valid forecast. Provider error bodies and internal paths are not exposed by the
public forecast result.

**Security review status: PASS. No unresolved findings in the forecast change.**

The free provider endpoint is documented for non-commercial use; paid customer
endpoints are outside this implementation. Source and CC BY 4.0 attribution are
included in the demo and serialized forecast metadata.
