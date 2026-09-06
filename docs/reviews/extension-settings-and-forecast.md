# Extension settings and forecast extraction — 2026-09-06

## Delivered

- Extension API 2: checksummed declarative JSON settings, automatic Admin menu,
  configuration while disabled, global/archive options and blank secret fields.
  API 1 package identities remain compatible.
- Climate 0.2.0 published at
  `58cd0730b0839e1c5ed8022c886d351f54678fd8` in
  [extension-climate](https://github.com/weewx-php/extension-climate).
- Forecast 0.1.0 published at
  `fdc8de437a9a2d04108f5e5946090040c630d607` in
  [extension-forecast](https://github.com/weewx-php/extension-forecast).
- Both approved by [extension-catalog](https://github.com/weewx-php/extension-catalog)
  commit `1bc22b5f444436baae7c5b2dff5e2acd76ed53c2`.
  Standalone validator fetched and checked all 25 package files.
- Core provider/cache/forecast configuration, CLI command and dedicated frontend
  method removed. Forecast uses generic extension workers and namespaced tags.
  The demo contains only a small adapter for presenting these tags.

## Verification

- Settings/installer suite: 17 tests, 107 assertions passed.
- Core Admin, Extension, Tick, Frontend and Backup tests passed during the broad
  201-test run. The example configuration was corrected afterward; 46 Config
  tests / 222 assertions then passed. One POSIX mode assertion cannot pass on
  Windows (0666 returned instead of 0640); it was excluded from that final
  Config run, without changing unrelated permission code.
- Separate climate suite: 7 tests / 133 assertions passed.
- Separate forecast suite: 16 tests / 132 assertions passed.
- Maximum-level PHPStan, PHP 8.1 target: package and core passed.
- PHP CS Fixer passed for modified implementation paths. No runtime Composer
  dependencies added; `composer audit --no-dev --format=json`: no packages.
- Real local forecast package installation from the published catalog, real
  Open-Meteo response (13,288 bytes), legacy cache/settings migration and
  idempotent second invocation passed. The migration uses normal Admin database
  path validation; the legacy installation's archive parent directory must exist.

## ALL-INKL

Authorized deployment to `https://weewxphp.fs-sys.de/`:

- Phase one deployed 31 code files with remote PHP lint, SHA-256 verification,
  conflict checks and local backups of prior code. Live configuration unchanged.
- Isolated private installation on the actual host: Climate 0.2.0 installation
  and activation 3.037 s, 4 MiB PHP peak, all 11 files verified, Admin settings
  available before activation, first ERA5 year downloaded. Production climate
  was left uninstalled for the user's own installation/activation test.
- Phase two deployed 14 code files, installed Forecast disabled, migrated one
  archive and copied its valid cache. Preserved enabled=true, days=7, every=3600,
  timeout=8. Old forecast configuration sections removed. A confidential config
  backup remains on the host (0600); it was never downloaded.
- Seven unused forecast core files were backed up as code and removed remotely.
- Post-deployment `check-config` passed. Production tags: ready, seven days,
  48 hourly points. Tick extension worker: fresh. Admin menu and per-archive
  settings rendered successfully. [Machine-readable result](forecast-extension-live.json).
- Public site and Admin login returned HTTP 200; public HTML includes forecast
  cards. No browser visual sign-off is claimed. The prior local-browser denial
  was respected; checks used CLI and the authorized production HTTPS endpoint.

Unrelated existing checkout changes were retained. No broad core Git commit or
push was made. Independent extension/catalog repositories were published using
the GitHub API. No remote Actions execution is claimed.

## Security review

Security-Sensitive: YES. Reviewed by primary agent using security-review skill.
OWASP categories checked: 10/10.

| Category | Result |
|---|---|
| A01 Access control | PASS. Existing HTTPS/Admin session/CSRF gates; revision and writer/store locks. Archive IDs must exist. Package PHP is not evaluated by settings forms. |
| A02 Cryptography | PASS. Verified HTTPS, immutable SHA-256 package/settings identities. Secret fields are blank, preserve on empty submission and allow explicit clearing. |
| A03 Injection | PASS. Strict field types/names and fixed validation formats; escaped localized metadata; no arbitrary form HTML, regex or callback from JSON. |
| A04 Design | PASS. Bounded metadata/field count, completeness and schema hash checks, validated cross-field ranges. Migration is explicit, idempotent and conflict-aware. |
| A05 Configuration | PASS. Global options plus archive overrides; install disabled; missing coordinates do not fetch. Private paths and existing atomic config recovery. |
| A06 Components | PASS. No added runtime dependencies; production audit clean. |
| A07 Authentication | PASS. No new identity mechanism or public settings endpoint. CLI migration relies on explicit host access. |
| A08 Integrity | PASS. Fresh approved releases, all files checked; schema change rejects stale forms. Migration verifies installed package, preserves unknown unrelated config and copies only validated caches. |
| A09 Logging | PASS. Existing operation audit records IDs without form values. Forecast suppresses transport exception text that can contain keys. |
| A10 SSRF | PASS. Fixed GitHub catalog/package hosts and fixed Open-Meteo public/customer endpoints. Redirects refused; time and response sizes bounded. |

Security Review Status: PASS. No deferred critical/high findings.

Extensions remain trusted PHP, not a sandbox. Configuration and its backups can
contain API keys and retain their existing private-data security requirements.
