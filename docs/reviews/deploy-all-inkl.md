# All-Inkl core deployment review

Date: 2026-09-06

Scope: `scripts/deploy_all_inkl.py`, its English and German locale files,
deployment configuration example, Docker integration tests and update guide.
No PHP runtime code or installed package contents were changed for this task.

## Security review

**Security-Sensitive:** YES
**Reviewed By:** Codex primary agent, using `security-review`
**OWASP Categories Checked:** 10/10

| Category | Result | Evidence |
| --- | --- | --- |
| A01 Access control | PASS | Explicit apply flag, fixed runtime payload, verified installation root, deployment lock; configuration, data and optional packages excluded. Local and remote symlinks rejected. |
| A02 Cryptography | PASS | Explicit FTPS with certificate and hostname verification, TLS 1.2 minimum and protected data transfers. Authenticated TLS session reused for data sockets. No insecure option. |
| A03 Injection | PASS | No shell commands in the deployment script. Path traversal, backslashes and control characters rejected; credentials cannot inject FTP commands. No SQL, templates or browser output. |
| A04 Insecure design | PASS | Verified backups and staging before publication; attempted operations rolled back on failure. Failed recovery retains the lock. Whole-release atomicity and manual recovery limits documented. |
| A05 Misconfiguration | PASS | Public document root required in setup guide. Backup directory outside it, with its own deny rule. Host .htaccess preserved. Unsupported directory formats fail closed. No XML or DTD parsing. |
| A06 Dependencies | PASS | Production uses only Python's standard library. Docker test dependencies audited; no known vulnerabilities found. |
| A07 Authentication | PASS | Password prompted without echo or read from environment. Unsafe terminal fallback refused. No password arguments, saved passwords, debug tracing or raw server errors. |
| A08 Integrity | PASS | Strict JSON configuration schema; no executable deserialization. Byte comparisons before and after publication; manifest records SHA-256 hashes. Source is the operator's trusted checkout. |
| A09 Logging | PASS | Localized outcome and recovery messages, retained per-update manifest and backups. Credentials and raw server errors excluded from output. |
| A10 SSRF | N/A | Local operator tool connects only to the configured FTP host. No web endpoint, remote URL fetch or user-triggered server request. Standard passive FTP uses the control peer for data connections. |

### Findings resolved

- MLSD alone can describe a symlink's target as a regular directory. Cross-check
  against Unix LIST metadata before entering or writing paths. Integration tests
  cover linked roots, directories and individual files.
- Incomplete rollback must retain the deployment lock to prevent another update
  from treating a mixed installation as its baseline.
- `getpass` must not fall back to echoed input when no suitable terminal exists.

The operator must prevent other tools or processes from modifying the same core
paths during deployment. FTP cannot guarantee a multi-file transaction or detect
every concurrent filesystem change. The lock coordinates this script's runs.
These operational constraints and recovery instructions are in the update guide.

### Dependency audit

Docker image: `weewx-php-deploy-tests`, Python 3.13, pyftpdlib 2.0.1,
pyOpenSSL 26.4.0 and pip-audit 2.10.1.

```text
docker run --rm --tmpfs /tmp -e XDG_CACHE_HOME=/tmp/cache weewx-php-deploy-tests pip-audit --progress-spinner off
No known vulnerabilities found
```

**Security Review Status:** ISSUES_FIXED

## Validation

All deployment tests run in Docker with external networking disabled. They cover
the full checkout payload, encrypted uploads, repeated updates, preserved runtime
data and optional packages, backups, staging failures, failed acknowledgements
after renames, successful and failed rollback, locking, wrong roots, symlinks,
untrusted certificates, path/config validation, non-echoing password handling and
English/German previews without network access.

The local preview selects 284 files (2,637,924 bytes). The complete payload is
published and compared byte for byte against a local FTPS test server. No live
All-Inkl credentials were used and no hosting account was modified.
