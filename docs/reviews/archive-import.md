# Archive import — implementation and security review

Reviewed 2026-09-06 by `/root`. Security-sensitive: **YES**.

Scope: `Admin/ArchiveImport`, `ImportFiles`, `ImportController`,
`DailyStatistics`, `ReadModel`, `Service`, `Page`, archive repair methods in
`Archive/ArchiveDb`, the HTTP import adapter, browser script, CSS and locales.
Existing unrelated theme, backup and forecast changes are outside this review.

## Verification

- Targeted PHPUnit: 43 tests, 387 assertions passed (admin, imports, ArchiveDb).
- PHPStan maximum level passes for changed import/admin/archive code and tests.
- JavaScript syntax check passes.
- Browser integration with the actual 7.2 MiB WeeWX reference database: eight
  1 MiB-or-smaller requests, pause/reload/resume without resending acknowledged
  blocks; unit detection, 50 timezone candidates including Berlin, unchanged
  successful import, webspace discovery, UTC repair and exclusion of linked
  source paths. No browser errors. Desktop and 390px screenshots inspected;
  no horizontal mobile overflow.
- Tests include wrong offsets, path traversal, anonymous and invalid-CSRF body
  rejection, oversized requests, mixed/unknown units, winter/summer and fractional
  offsets, both DST transition days, interrupted publication, discarded payloads,
  raw-row and known-extrema preservation, and absence of an original-file backup.
- Reference database hash unchanged after read-only analysis. Two-day check
  114.2 ms; all-zone detection 115.7 ms on the local development host.
- `composer audit --no-dev --locked`: no production packages to audit.
- Deployed 13 runtime/admin/locale files to All-inkl after server-side PHP lint
  and SHA-256 verification. Existing configuration remained unchanged during
  deployment; no database was uploaded by deployment. Fresh authenticated live
  browser tab confirms the new controls and the user's subsequently imported
  Kirchdorf archive. The temporary local QA server was stopped.

## Security review

| OWASP category | Result |
|---|---|
| A01 Access control | Every import action requires admin session and CSRF before reading the request body; only POST; search paths are server-owned and realpath-confined. |
| A02 Cryptographic failures | Existing HTTPS requirement, Strict/HttpOnly/Secure cookie, no-store responses, random 128-bit staging IDs; payloads outside public root. |
| A03 Injection | Bound SQL values; validated/quoted identifiers; no shell execution; filename is display-only; DOM uses textContent and options. |
| A04 Insecure design | Bounded upload chunks and repair steps, exact acknowledged offsets, exclusive per-import lock, resumable publication, no original backup; explicit payload discard. |
| A05 Misconfiguration | Private staging directories and deny rule; filesystem-root searches rejected; no arbitrary client-supplied source path; generic errors. |
| A06 Vulnerable components | No new runtime dependency; production Composer audit has no packages. |
| A07 Authentication failures | Existing single-admin authentication and CSRF reused; no password or session policy changes. |
| A08 Integrity failures | SQLite signature and real archive table checked; units validated across records; original archive rows preserved; configuration only published after successful inspection/repair. |
| A09 Logging/monitoring failures | Begin/select/commit/discard admin audit events, generic exception class in server logs; no database content or absolute search paths in errors. |
| A10 SSRF | No outbound requests; filesystem search remains beneath configured webspace. |

XML, LDAP and object deserialization are not used. Uploaded SQLite files are
admin-controlled input, not executable uploads. Request limits do not replace
hosting disk quotas. Abandoned imports can be discarded; no automatic original
backup or backup retention policy is introduced. Sample agreement is not a
proof that every historical day is correct.

**Security review status: PASS.**
