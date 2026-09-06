# Archive connection and first-station mapping suggestions

## Behavior

The archive editor now begins with station selection and a save-and-continue action that opens field mapping. Archive and station lists expose the next setup action. Setup status distinguishes a missing station, open field assignments, a disabled archive, and readiness. General archive settings preserve station selections independently.

For a populated archive with exactly one selected station and no existing assignments, exact canonical observation names are proposed against existing columns. Duplicate observations, unknown values, and absent columns are excluded. Current sensor values and the last non-NULL historical values appear with their units and timestamps. Historical values use one aggregate scan plus indexed timestamp lookups, not a separate full scan per field.

An expandable review confirmation precedes the explicit final acceptance button. The POST requires authentication, CSRF, complete-form marker, revision, affirmative confirmation and a fingerprint of the exact proposed assignments. Server-side recomputation rejects changed proposals. Existing mapping validation and history confirmation apply atomically through Changes; archived readings are not modified.

## Validation

- Admin suite: 43 tests, 380 assertions passed. PHP emits the platform warning that pcntl is unavailable for test time limits.
- Maximum-level PHPStan passed for changed runtime and tests.
- PHP CS Fixer and PHP syntax checks passed.
- Local browser: station selection redirects to field mapping; manual historical mapping still requires confirmation. Bulk proposal acceptance completed through both visible confirmation steps on the imported 17,963-row QA archive.
- Mobile 390 px: station setup and proposal values visually checked; document scroll width 375 px, no page overflow. Temporary viewport reset.
- Tests verify last non-NULL values in sparse columns, duplicate-source exclusion, multiple-station exclusion, rejection of missing confirmation and wrong preview fingerprints, and byte-identical original database after acceptance.

## Security review

Security-sensitive: YES. Reviewed by /root using the security-review skill. OWASP categories checked: 10/10.

| Category | Result |
|---|---|
| A01 Access control | Existing authenticated HTTPS admin, CSRF and method checks cover both new actions. Only configured stations may be selected. |
| A02 Cryptographic failures | Existing credential handling unchanged; no secrets introduced or displayed. |
| A03 Injection | Dynamic column names validated by Catalog and schema membership, timestamp parameters bound; all rendered station, field and archive values escaped. |
| A04 Insecure design | Explicit review plus final confirmation; proposals restricted to an unassigned first station. Fresh sensor values do not invalidate the structural fingerprint, changed assignments do. |
| A05 Misconfiguration | No new public endpoints, external inputs or security settings. |
| A06 Components | No dependency added. Composer audit --no-dev --locked: no production packages. |
| A07 Authentication | Existing auth, sessions and CSRF retained; unconfirmed/unauthorized actions rejected. |
| A08 Integrity | Existing revision check, writer lock, atomic configuration change and mapping validation reused. Preview is read-only and original measurements remain unchanged. |
| A09 Logging | Changes audit and revision records cover new commands; rejection auditing unchanged. |
| A10 SSRF | No network destinations or server-side requests introduced. |

No open high or critical findings. Security review status: PASS.

## Deployment

Seven runtime files deployed to All-inkl after checksum verification and remote PHP lint; configuration unchanged. Live archive has one selected station and no field assignments. Server-side rendering finds 20 exact proposals, includes both confirmation stages and historical values, and completed in 123 ms. No live assignments were accepted during verification. Browser-side live check reached the existing login screen; authenticated UI interaction and mobile behavior were verified on the local QA copy.
