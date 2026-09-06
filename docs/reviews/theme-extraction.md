# Theme extraction review

Date: 2026-09-06

## Result

- Core ships only `themes/basic`, with live measurements, units, per-reading
  timestamps, missing values and stale status. No archive file or analytics
  database is required to display live data.
- English is the default; German is selectable in the admin. Locale dictionaries
  cover labels, status text and dates. Numbers follow the selected language.
- Demo and Cookbook are independent local Git repositories under `4_Themes`.
  Their existing presentation and assets were preserved. They are not published
  to GitHub by this change. No theme catalog or remote installer was added.
- Public HTML, snapshots and assets use generic routing. The configured active
  theme controls rendering; a missing package falls back to Basic.
- The existing worker progress regressions remain in the core test suite as
  `WorkerProgressTest`, independently of any external theme recipe.
- Project instructions require Docker tests, English GitHub content and comments,
  and multilingual development with English defaults. JavaScript and Demo tests
  now have Docker Compose services.

## Validation

All final automated checks ran in Docker:

- Core PHPUnit: 449 tests passed on PHP 8.1.
- Core PHP CS Fixer and PHPStan: passed.
- External package PHPStan: passed for Demo and Cookbook.
- Demo PHPUnit: 3 tests passed.
- JavaScript: 5 tests passed on Node 22.
- Browser: English and German Basic pages served by Docker displayed the same
  readings with localized labels, numbers and measurement times. The extracted
  Demo and Cookbook pages and their assets were also checked during extraction.
- Full conformance: frontend and frontend-scale checks passed. `ingest` and
  `native_ingest` failed their concurrent-discovery assertions, including an
  isolated repeat. These checks exercise ingest routes rather than theme routes.
  Their failures remain unresolved; this change does not alter ingest behavior.

The linter also normalized pre-existing line-ending differences in nine files.
No logic changes were made to those files for this normalization.

## Security review

Security-sensitive: yes. Reviewed by the main implementation agent using the
security-review checklist.

| Category | Result |
|---|---|
| A01 Access control | Public requests cannot choose executable theme paths. Admin settings preserve existing authorization, revision and CSRF checks. |
| A02 Cryptographic failures | No credentials or cryptographic changes; error responses contain no configuration paths. |
| A03 Injection | Theme IDs and asset components are validated. HTML and attributes are escaped; polling updates use `textContent`. |
| A04 Insecure design | Packages are trusted local application code. Settings and locale discovery execute no package PHP. |
| A05 Misconfiguration | Explicit content types, no-sniff, CSP, no-store snapshots and GET/HEAD method limits. |
| A06 Components | No new production Composer dependency. The production-only audit had no packages to audit. Existing shared chart assets were retained. |
| A07 Authentication | Existing admin authentication remains in place. |
| A08 Integrity | Bounded JSON settings/locale reads; real-path containment rejects files escaping package roots. |
| A09 Logging | Renderer failures are logged server-side; public responses remain generic. |
| A10 SSRF | Remote wrappers and network package paths are rejected. No new server-side downloads. |

No critical or high findings were identified. Static asset requests reject PHP,
JSON, path traversal and files outside the local package. Third-party PHP themes
remain trusted code, as documented by the package contract.
