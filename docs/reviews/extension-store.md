# Extension store review — 2026-09-06

Subsequent API 2 settings, package releases and the authorized ALL-INKL deployment
are recorded in [the follow-up review](extension-settings-and-forecast.md).
The following records the earlier stage before that deployment.

## Result

- Published [extension-climate](https://github.com/weewx-php/extension-climate),
  including source, license, tests, real import measurements and a review artifact.
  Approved code commit: `22f61419a795ab46ae2d98fedbf1ae4682ba6891`.
- Published [extension-catalog](https://github.com/weewx-php/extension-catalog),
  with `catalog.json`, standalone validation tooling and CODEOWNERS.
  `main` requires one codeowner approval and resolved conversations; force pushes
  and deletion are disabled. Organization administrators retain their normal bypass.
- The core checkout implements Admin → Extensions: catalog, install, activate,
  deactivate, update and remove. All package code and climate tests have moved
  out of the core to the separate repository. Existing unrelated changes remain.

## Validation

- 160 core tests / 1,161 assertions passed: Extension, Admin, Tick, Frontend,
  Backup and Forecast. Full PHPStan maximum-level analysis passed (PHP 8.1 target).
- Additional recovery, tampered-installation, size/lock and archive-scope tests
  subsequently passed: 15 Extension tests / 85 assertions.
- Separate climate repository: 7 tests / 133 assertions passed, PHPStan passed.
- Formatting checks passed for changed core PHP files and package PHP.
- Catalog validator retrieved and verified all ten published package files.
- Real isolated local installation from the published GitHub catalog succeeded:
  install + activation 0.816 seconds, 4 MiB peak PHP memory. The first extension
  tick downloaded one ERA5 reference year; no measured archive was created.
  [Recorded results](extension-store-live.json). TLS verification remained enabled.
- No browser visual sign-off: the earlier browser access restriction was respected.
  Authenticated page rendering, escaping, forms and controller actions are tested.
- No real shared hosting deployment was performed. Core changes remain in the
  working checkout; only the two requested independent repositories were published.

Git publication used GitHub's API after the local Git shell/SSL environment failed.
The connected GitHub token lacks workflow scope, so no Actions workflow was
published. The standalone catalog validator ran locally; no remote CI pass is claimed.

## Security review

**Security-Sensitive: YES. Reviewed by: primary agent using security-review skill.**

Reviewed: `Extension/{Release,Files,Catalog,Installer}.php`,
`Admin/{ExtensionService,ExtensionPage,Controller,Changes,Page}.php`, existing
authentication and HTTP transport behavior, catalog validator and package review.

| OWASP category | Result |
|---|---|
| A01 Access control | PASS. Existing HTTPS/session and CSRF checks precede actions. Anonymous reads do not fetch the catalog. Configuration revision and writer locks prevent stale changes. |
| A02 Cryptography | PASS. TLS stays enabled. SHA-256 verifies every file against the fresh catalog's fixed commit. No new secrets. |
| A03 Injection | PASS. Strict IDs, repositories, commit hashes and portable path components. No shell commands, SQL construction, HTML from metadata, archive extraction or package evaluation during install. |
| A04 Design | PASS. Complete staging precedes atomic configuration switch. Existing versions remain for active workers. Failure/retry/update/restore paths covered. New installs disabled. |
| A05 Configuration | PASS. Private data root and deny file; managed paths stay in the store, symlink components are rejected. Deployment uses the existing private-data/web-root contract. |
| A06 Components | PASS. No new runtime dependencies. `composer audit --no-dev --format=json`: no packages to audit. |
| A07 Authentication | PASS. Existing auth, session expiry and CSRF unchanged; no public installer endpoint or new identity system. |
| A08 Integrity | PASS. Fresh approval required on install and activation; displayed release fingerprint must match. Code reverified before activation. Mutable refs, duplicate/case-colliding paths, invalid JSON and checksums rejected. |
| A09 Logging | PASS. Configuration actions record package ID through existing audit recovery. Rejected requests audited; technical failures log only exception class. |
| A10 SSRF | PASS. Catalog URL fixed; file URLs built only from approved organization/repository, full commit and validated path. Existing clients reject redirects and bound response bodies. |

**Security Review Status: PASS. No deferred critical/high findings.**

The catalog is an explicit code-trust authority, not a sandbox. Its reviewers
must inspect code before adding a version; checksum validation alone does not
constitute that review. Removing approval prevents new installation/activation,
but does not remotely shut down already active code. No automatic code updates.
Removing an installation keeps its data and code versions for later manual cleanup.

The extension-only configuration path skips archive work only after comparing
the entire configuration with Extensions removed. Editing any other section
still invokes normal archive/path validation. It cannot use an operation name
alone to bypass validation.
