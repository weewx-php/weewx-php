# Review: theme cookbook, public feeds and widgets

Date: 2026-09-05. Reviewer: `/root`.
Security-Sensitive: **YES**. OWASP categories checked: **10/10**.

## Scope

- `src/Frontend/Api/{Feed,Endpoint,Response}.php`, `public/api/v1.php`.
- `Query::prepared()` enforces cache-only reading for an existing recipe.
- `Weather::live()` now applies the output profile even without a live file.
- `themes/cookbook/{data,feeds}.php`, `public/cookbook.php` and its assets.
- Reusable feed client, custom element and WordPress plugin.
- Documentation, package build and related tests.

## Security boundaries

The API explicitly publishes public weather data. Without a local feed file,
there are no feeds. Only the operator defines archives, observations, units,
aggregations and periods. HTTP clients select only a feed and a subset of its
allowed fields. The include path comes from the local environment/configuration,
never from HTTP.

CORS is not authentication. The cookbook example export deliberately allows
any origin and contains only the listed outdoor observations. Confidential data
would require a separate authenticated interface.

At most 24 prepared recipes and eight live fields per feed; at most 4,096 points
and a 1 MiB response. Archive recipes are not calculated in the HTTP path.
LOOP access reads at most 512 packets per field within the shared page budget.
For public operation, server-wide HTTP rate limiting belongs at the reverse proxy;
`pollSeconds` is explicitly only a client recommendation.

## OWASP review

| Category | Result | Check |
|---|---|---|
| A01 Access control | PASS | Publication requires local approval; field selection bounded; no automatic private metadata; GET/HEAD/OPTIONS methods |
| A02 Cryptography/privacy | PASS | No credentials in browser or feed; HTTPS documented for production; local tests on loopback |
| A03 Injection/XSS | PASS | No SQL/shell/include paths from request data; JSON with HEX escaping, nosniff; DOM output uses only textContent; WP attributes escaped; ECharts RichText tooltips |
| A04 Insecure design | PASS | Fixed recipes, cache-only access, size limits, explicit observation time and source; live does not replace archive amounts |
| A05 Misconfiguration/XXE | PASS | Closed without a feed file; generic errors, Vary: Origin also on errors; no XML processing; DocumentRoot public/ |
| A06 Components | PASS | ECharts 6.1.0 from official tag, license/NOTICE/hash included; npm and Composer without known advisories |
| A07 Authentication | N/A | Public read interface without sessions, cookies or browser keys; existing admin/ingest authentication unchanged |
| A08 Data/software integrity | PASS | No untrusted PHP deserialization; JSON version checked; module URLs limited to HTTP(S), no embedded credentials; plugin ZIP copies maintained assets exactly |
| A09 Logging/monitoring | PASS | Error details only in server log; HTTP statuses for access/validation errors; worker diagnostics remain internal; web server access logging documented for operation |
| A10 SSRF | N/A | No server-side requests to widget URLs; data fetched directly in browser, credentials: omit |

No open critical or high security findings in the reviewed scope.
Security Review Status: **PASS**.

## Checks

- Frontend PHP suite: **47 tests, 285 assertions**, PHP 8.1.34, passed.
  Includes seven API tests for allowed fields, malicious parameters,
  CORS/preflight, cache miss without archive access, zero versus null, ETag/HEAD,
  live observation time, status changes and units when the live journal is missing.
- JavaScript: **four tests**, passed. Milliseconds, calendar date at the DST
  transition, interrupted cumulative amounts, shared polling, ETags, timeout,
  retries, visibility and idempotent cleanup.
- WordPress: PHP syntax and standalone hook/escaping smoke test passed.
  Registration, shortcode, classic widget, options, URL schemes, module tag
  and attribute injection protection checked. No complete WordPress installation
  test performed in this environment.
- Browser: four real ECharts instances, desktop and 390 px mobile width;
  no horizontal page overflow. Expandable table with 295 data rows usable.
  No warnings/errors in the browser console.
- Second origin: HTML on port 8088 reads API on 8087; widget with filtered fields
  visible. HTTP GET 200, conditional GET 304, OPTIONS 204 and disallowed field
  400 verified.
- WeeWX conformance: all executed checks passed, including frontend aggregates
  and a test with 1,051,776 archive rows. Optional weewx-evo upload comparisons
  skipped because sources were not mounted.
- PHP-CS-Fixer for the ten PHP files in scope: passed.
- PHPStan at maximum level for the entire project: passed.
- npm audit of pinned ECharts dependencies: **0 vulnerabilities**.
  Composer audit of existing development dependencies: no advisories.
- Cookbook file links and identical shared assets in the plugin ZIP verified.

## Concurrent working directory changes

The ingest/replay area was being developed independently during this work.
The last full run had 336 tests and 3,584 assertions; one failure occurred in
`NativeReplayTest::testLiveDeliveryIsProtectedWhenTheScheduledTickStopsForDays`:
MappingError for the automatically generated test archive at `Archiver::open()`.
That test and its associated ingest files were not changed here.

The full formatting run also reported differences in `CollectorCommand.php`,
`Archiver.php`, `CollectorStore.php`, `ReplayStore.php` and `NativeReplayTest.php`.
The cookbook/API checks above passed independently. The overall state was
therefore not reported as fully passing in that run.

## Local demo

The gallery uses the demo configuration's existing archive unchanged. Only the
separate analytics cache and an explicit local feed file were set up for the
preview. The source contains some days from August 2026, no complete historical
comparison years and no active LOOP input. Missing live values/comparisons are
shown as missing rather than replaced with sample data. The temporary second
test server was stopped after the cross-origin test.
