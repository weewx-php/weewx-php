# Admin security review

Reviewed: 2026-09-05. Reviewer: Codex, primary agent.
Security-sensitive: **YES**. Status: **ISSUES_FIXED**.

The review covered the complete new Admin and Measurement services, the HTTP
entry point, browser enhancement, CLI provisioning, configuration/schema
operations, processing revisions, ingest field inventory and their integration
with the existing SQLite and archive code. The mandatory security-review skill's
10 categories were checked.

| Category | Result |
|---|---|
| Injection | Values use SQL parameters. Dynamic column identifiers are constrained and checked against the real schema. No commands execute from submitted data. |
| Authentication | Password hashing, random session tokens stored as hashes, CSRF validation, login rotation, expiry and per-client/global login limits. No default account password. |
| Sensitive data | HTTPS required outside the explicit loopback override. HttpOnly, Secure-on-HTTPS, SameSite Strict cookies. Credentials are never prefilled; configuration serializer errors no longer echo their contents. |
| XML / external entities | No XML processing in these routes. |
| Access control | Authentication gates every admin view and command, including the column-history JSON endpoint. Mutations require POST and CSRF. Unknown pages are rejected. |
| Configuration | Private data root, realpath checks, reserved database names, no-replace archive installation, no raw file editor. CSP, no-store, nosniff and frame restrictions. |
| XSS | Dynamic HTML and attributes are escaped. History updates use DOM textContent. Theme definitions cannot inject markup or replace core controls. |
| Deserialization / integrity | Typed JSON validation, declarative theme schemas, optimistic revisions, one writer lock and resumable schema operations. Truncated archive-wide forms are rejected. |
| Dependencies | Composer audit of the locked dependencies returned no advisories and no abandoned packages. No new runtime package dependencies. |
| Logging | Login, reset, rejection and mutation audit records omit credentials. Logs retain bounded histories. Pending recovery conflicts fail closed. |

Fixed during review: theme field names colliding with security form controls;
potential credential values in configuration validation errors; incorrect rain
counter aggregation after conversion into rainfall. Authentication on the
progressive history endpoint is covered by a regression assertion. Continuing
populated column history requires confirmation scoped to the station, source
and destination; the server validates this even without browser scripting.

Verification: PHP 8.1.34 passed 316 tests with 3,205 assertions, including 22
admin tests with 198 assertions. Formatting and PHPStan passed. WeeWX conformance
checks passed. Admin coverage includes stale writes, CSRF, sessions, escaping,
recovery, existing archive preservation, typed sources, complete archive-wide
drafts, exact inventory and unknown historical provenance. Browser checks
confirmed inline column creation, populated-column warnings, required
confirmation, empty-column selection and restoring saved assignments with Cancel.

Operational boundaries are documented in [Administration](admin.md): private
filesystem permissions, trusted HTTPS termination, hard-link support/recovery,
archive scans for exact inventory, and configuration history versus per-record
provenance. There are no open critical or high-severity findings from this review.
