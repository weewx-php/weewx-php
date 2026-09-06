# Frontend layer security review, 2026-09-05

Scope: frontend recipes, archive reader, analytics cache/worker, change markers,
CLI, output helpers and astronomy port. Based on the `security-review` skill and
OWASP checklist. No deployment or PR.

| Area | Check |
|---|---|
| Injection | Identifiers strictly validated/quoted; values bound; aggregate names limited to the catalog. No shell, LDAP, XPath or SQL expressions from input. |
| Authentication | No new public endpoints or login mechanisms. Existing tick access remains authoritative. |
| Data/secrets | Cache contains weather data, recipes and configuration hashes; no tokens/passwords. Data directory outside the webroot. |
| XML/XXE | No XML processing added. |
| Access control | SQLITE3_OPEN_READONLY; archive selection from configuration. `data.php` is trusted local theme software. |
| Misconfiguration | No new external services or runtime installations. Error presentation belongs to the theme. |
| XSS | Value output HTML-escaped; JSON escapes HTML-sensitive characters. `format()` intentionally returns plain text. |
| Deserialization | JSON with depth limit and validated recipe types; no `unserialize` or `eval`. Fixed local astronomy files. |
| Dependencies | `composer audit --locked --no-interaction --format=json`: no advisories, no abandoned packages, exit 0. Git reported an ownership warning on the container mount. No Composer runtime packages added. |
| Logging | Existing logger, `analytics status`, retry delay on errors. |
| Resources | Bounded source queries, page budget, series/recipe limits. Persisted blocks do not yet have a storage quota. |
| Consistency | Tick lock also covers catchup/rebuild; persistent change marker; revision-checked publication in a short cache transaction; interrupted jobs resume. |

Tests cover identifier injection, oversized formats, script JSON, budget limits,
external corrections, nightly intervals, resumption without double-counting and
shared historical blocks. No open critical or high findings in the reviewed
changes. Arbitrary custom PHP themes and third-party files are not automatically
covered by this review.

Final checks: 256 PHP tests with 1,459 assertions passed; formatting checks and
PHPStan at maximum level reported no errors. All conformance checks passed,
including 128 frontend aggregate comparisons, 28 calendar boundaries, 2,072
astronomy cases and the archive with more than one million five-minute records.
