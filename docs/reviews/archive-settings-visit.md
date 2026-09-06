# Archive settings, geocoding and visitor ticks

Reviewed 2026-09-06 by `/root`. Security-sensitive: **YES**.

Scope: modified Admin Page/Service/Controller, new Admin Geocoding, Tick Visit,
Settings/ConfigReader additions, `public/visit.php`, location/visit scripts and
the theme script include. Existing authentication, configuration transaction,
tick, HTTP transport and receiver implementations were inspected as dependencies.

## Verification

- Targeted admin, archive-import, archive-settings, tick and visitor tests pass.
- Maximum-level PHPStan passes for modified runtime code and new tests.
- JavaScript syntax checks and PHP CS Fixer pass for the scoped code.
- Browser: real Open-Meteo search finds Kirchdorf an der Amper; selecting it
  supplies coordinates and 441 m place elevation, preserves an existing manual
  height, saves feet without relabelling, and retains existing archive timezone.
  Desktop and 390px mobile screenshots checked, with no horizontal overflow.
- Loading the public page triggers a 204 background visit request. Unit tests
  verify operation without stations, shared throttling, recent-cron suppression,
  disabled behavior and the existing writer lock.
- Live reception diagnosis: HTTP port 80 has no HTTPS redirect. Ingest was
  disabled and public_url empty. Enabled through the locked configuration change
  service, using `http://weewxphp.fs-sys.de`. Real WS2900_V2.01.12 packets arrived
  as a pending station; no synthetic weather packet or automatic mapping was used.
- Composer has no production packages to audit; no dependency added.
- Broader configuration test has the known Windows Unix-mode assertion failure
  in `testWritesAtomicallyAndKeepsTheMode`; unrelated to these changes.

## Security review

| OWASP category | Result |
|---|---|
| A01 Access control | Geocoding and setting mutations require existing admin session and CSRF. Visit endpoint intentionally exposes only fixed configured work; no arbitrary action/path/token inputs. |
| A02 Cryptographic failures | Geocoding uses HTTPS with verification. No credentials or tick token exposed to visitors. Local test PHP received the existing Windows trust roots without disabling validation. |
| A03 Injection | Bound SQL, fixed HTTPS provider, encoded query; DOM textContent, escaped labels/connection details; typed and range-validated numeric inputs. |
| A04 Insecure design | Visit attempts globally throttled under nonblocking lock and normal tick writer lock. Normal cooperative tick budget retained; heavy maintenance may exceed it as documented. |
| A05 Misconfiguration | Reception state and missing URL visible. No global TLS weakening, no CORS on visit endpoint, no response body exposing run details. |
| A06 Vulnerable components | No new dependencies; production Composer audit has no packages. |
| A07 Authentication failures | Existing login, cookie and CSRF protections unchanged. Unauthorized/invalid-CSRF geocoding does not call the provider. |
| A08 Integrity failures | Coordinates and elevation are reviewable unsaved form values. Existing height and fixed archive timezone are preserved. Invalid units/numbers leave config unchanged. |
| A09 Logging failures | Visitor ticks recorded with trigger `visit`; generic transport failure logging; config changes retain existing audit/revision behavior. |
| A10 SSRF | Geocoding destination is fixed, redirects disabled, timeout 5 seconds, response bounded to 128 KiB, eight results, 100 cache entries and a shared two-second request throttle. |

The public visitor endpoint is not equivalent to secret administrative access.
Any client can request its rate-limited maintenance work by supplying the fixed
header; cross-site browser form requests cannot do so. XML and object
deserialization are not used. **Security review status: PASS.**

## Live deployment verification

- Fifteen runtime files deployed to All-inkl with SHA-256 checks and PHP lint; deployment preserved configuration.
- Live archive editor displays separated height/unit fields; explicit place search returned Kirchdorf an der Amper, 48.45942 / 11.65438, 441 m. No archive settings were saved during verification.
- Connection details visibly show HTTP, port 80; GET of the Ecowitt receive path returns 405 directly over HTTP with no HTTPS redirect (POST is required).
- At 2026-09-06 09:34:49 UTC, real WS2900_V2.01.12 sender had 55 received packets, state adopted, and 3 stored packets. User adopted the station during verification; agent did not adopt or map it. Stored packets are not a claim of archive output.
- Runtime log contains a completed visit-triggered tick at 09:30:46 UTC and an ingest-triggered tick at 09:34:00 UTC.
