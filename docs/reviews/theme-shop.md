# Theme shop review

Date: 2026-09-06

The admin theme page now provides the same catalog workflow as extensions:
install, update, activate, deactivate, configure and remove. Package downloads
reuse the extension store's bounded HTTP requests, fixed GitHub origin, immutable
commit references, SHA-256 manifests and staging directories. Theme storage and
the approval list are separate from extensions.

Installation leaves the active selection unchanged. Updates preserve saved
settings and activation; failed downloads leave the configured version intact.
Removing or deactivating the active theme selects Basic. The core theme is
protected, and manually installed packages cannot be replaced by the shop.

## Security review

**Security-Sensitive:** YES
**Reviewed By:** Codex primary agent using `security-review`
**OWASP Categories Checked:** 10/10

Reviewed: `Admin/ThemeService.php`, `Admin/ThemePage.php`, modified controller,
configuration mutation and theme settings paths, shared `Extension/Catalog.php`,
`Extension/Release.php`, `Extension/Installer.php` and their filesystem/HTTP
dependencies. No runtime dependencies were added.

| Category | Result | Evidence |
| --- | --- | --- |
| A01 Access control | PASS | Authenticated admin commands, CSRF checks, revision checks and independent store lock. Basic and manual packages protected. |
| A02 Cryptography | PASS | HTTPS through the existing verified HTTP client; every file must match an approved SHA-256 at a fixed commit. |
| A03 Injection | PASS | Catalog and locale strings escaped in HTML. IDs and file paths use the existing strict validators. No shell, SQL interpolation or template evaluation added. |
| A04 Insecure design | PASS | Inactive installation, atomic configuration switch after staging, retained old versions for in-flight requests. Missing catalog never permits unapproved downloads or activation. |
| A05 Misconfiguration | PASS | Private theme-store directory with deny rule. Declarative metadata validated without executing PHP. `managed_release` reserved against settings-schema writes. No XML handling. |
| A06 Dependencies | PASS | Existing production dependency set unchanged; Docker Composer audit reports no security advisories. |
| A07 Authentication | PASS | Anonymous requests cannot load the shop catalog or install packages. Invalid CSRF now exits before rendering a view that could fetch a catalog. |
| A08 Integrity | PASS | Fresh approval and matching release fingerprint required for install/activation; reactivation re-verifies installed files. Managed settings forms cannot activate packages. |
| A09 Logging | PASS | Store refreshes and configuration changes use existing audit/revision records; rejected requests are audited. Download exceptions expose only their class in server logs. |
| A10 SSRF | PASS | Fixed catalog URLs and repository-scoped raw GitHub URLs; manifests accept no arbitrary download URL. Existing HTTP redirect policy and response limits remain in use. |

### Findings resolved

- Saving settings previously offered an independent activation path. Managed
  theme activation now belongs exclusively to the shop's fresh approval check.
- Rejecting CSRF must not proceed to a shop render that fetches remote data.
- `managed_release` is a reserved settings key; `basic` and `active` cannot be
  approved catalog IDs.

Theme PHP remains trusted application code, as with extensions. Installing a
reviewed package does not sandbox its renderer. Catalog revocation blocks future
activation and installation; it does not remotely stop already active themes.

**Security Review Status:** ISSUES_FIXED

## Validation

- Docker PHPUnit: 463 tests passed, including 14 new theme-shop tests.
- Docker frontend JavaScript: 5 tests passed.
- Docker PHP CS Fixer and PHPStan at the configured maximum level: passed.
- Docker Composer audit of the locked dependency set: no security advisories.
- Docker validation of the prepared theme catalog: valid, zero approved releases.
- English and German admin views checked in the browser against a local Docker
  preview using fixture catalog entries, not published package approvals.
- Full Docker conformance: existing `ingest` and `native_ingest` concurrency
  failures remain (`concurrent WU requests were not all accepted` and
  `concurrent discovery failed`). All other conformance groups passed. These are
  the failures already recorded during theme extraction; this change does not
  modify ingest processing.

## Publication status

The implementation points to `weewx-php/theme-catalog`. Read-only GitHub checks
did not find that repository or `weewx-php/theme-demo` / `theme-cookbook`.
The catalog is prepared locally at `4_Themes/theme-catalog` and is intentionally
empty. Publishing reviewed theme commits and their approved catalog entries is
still required for live downloads. No placeholder commit or false review was
added to the real catalog, and no GitHub repository was created or published.
