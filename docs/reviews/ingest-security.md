# HTTP ingest security review

Reviewed: 2026-09-05. Reviewer: Codex primary agent.
Security-sensitive: **YES**. Status: **ISSUES_FIXED**.

Scope: `src/Ingest/`, `IngestCommand`, configuration integration, Runtime's
ingest lifecycle, the unit-group additions, PHP entry points, Apache routing,
the development router, and their unit/conformance tests. This iteration
adds sender limits, diagnostics, external ticks and credential replacement.
It introduces no admin HTTP endpoint or session system; the separately
developed administration layer is outside this review.

## Findings

| Category | Severity | Finding | Resolution |
|---|---|---|---|
| A02/A05 | Medium | TLS between a trusted proxy and PHP could make a plaintext console connection appear secure | Fixed: a configured proxy must supply valid, overwritten source and scheme headers; origin-hop TLS never substitutes for client TLS. Regression tests cover plaintext clients and invalid/missing forwarding headers. |
| A04/A07 | Medium | One console's malformed uploads could failure-block other established consoles behind its IP | Fixed: attributable failures and rate limits use a sender scope. Unknown failures block discovery only. Raw IP/global limits still apply to all requests. |
| A04/A09 | Low | Repeated HTTP 429 requests could fill rejection logs | Fixed: update bounded reason counters without writing a line for each throttled retry. |

No outstanding high or critical findings were identified in this review.

## OWASP review

| Category | Result | Evidence / boundary |
|---|---|---|
| A01 Access control | Pass | Ingest routes expose neither adoption nor credential replacement/disclosure. Only an adopted sender reaches LiveDb. Replacement preserves adoption and blocking; it cannot enable a sender. Unknown credentials, wrong paths and forbidden methods are refused. Pending/blocked uploads cannot trigger archives or ticks. |
| A02 Cryptography | Accepted transport constraint | Random setup secrets use PHP's CSPRNG (12 alphanumeric characters, about 71 bits). Assigned WU credentials are stored as SHA-256 digests, appropriate for generated high-entropy tokens rather than human passwords. HTTP remains explicitly allowed for limited consoles, as requested. TLS is terminated by the host. |
| A03 Injection / XSS | Pass | Bound SQLite parameters; fixed table names; no shell execution, templates or XML parsing on the ingest path. Form keys, UTF-8, controls, duplicates, arrays and numeric finiteness are checked. HTTP output contains fixed responses, never submitted text. A future UI must escape stored device metadata. |
| A04 Design | Pass within stated scope | Separate bounded discovery database, one sample per sender, shared rate limits and temporary blocks. Adoption is admission control; a copied hardware identity or credential is not cryptographically distinguishable from its owner. |
| A05 Configuration / XXE | Pass with host requirements | Disabled by default; explicit routes, method checks, size limits and no automatic redirects. No XML parser. Data/configuration stay outside the document root. Proxy trust requires exact addresses and overwritten headers. The host must cap headers and slow request reads. |
| A06 Components | No production dependencies | No Composer runtime packages or newly introduced dependencies. See audit result below; PHP, SQLite and the web server remain host-maintained components. |
| A07 Authentication | Pass within stated scope | Constant-time setup-secret comparison, assigned-token digest lookup, process-shared IP/global/sender throttling and temporary failure blocks. The sender is identified from validated credentials before parsing measurements; unsafe forms remain unattributed. Credential allocation and replacement each serialize under a SQLite write lock. Assigned WU credentials are checked again at write time, so replacement cannot be bypassed using a stale earlier lookup. |
| A08 Integrity / deserialization | Pass | No object deserialization. Only explicit mapped numeric observations reach packets. Write/adopt/block operations serialize through SQLite. Concurrent first-use requests resolve to one sender. Journal deduplication handles retransmission after a partial cross-database failure. |
| A09 Logging | Pass with host requirement | Fixed rejection reasons and validated peer IPs; CLI credential replacements logged without secrets. Samples also redact credential/auth fields. One diagnostic row per sender contains counters and timestamps, not uploaded credentials or measurement history. Outer refusals are not guessed to belong to a sender. Web-server/proxy URL and query logs must be disabled or redacted on ingest routes because these firmware protocols carry credentials there. |
| A10 SSRF | Pass | Reception does not fetch a submitted URL. public_url is local administrator configuration used only for displaying connection details. Existing configured upload destinations are unaffected. |

The free WU password and common Ecowitt path must remain recoverable for
console setup and are stored in the private ingest database. Ecowitt PASSKEY
is retained as device identity. These are not secrets embedded in source.
The repository's sample-retention and database placement guidance applies.
Replacing an assigned WU password displays it once through the CLI and
retains only its digest. The new value is checked against active assigned
and setup credentials. No user/test-installation credential was rotated
while implementing this feature; rotation tests use temporary databases.

Two new diagnostic tables are created additively. Existing sender ids,
credentials, counters and mappings are retained. New outcome counters have
their own start timestamp because historical duplicate/pending counts are
unavailable. Field-inventory updates no longer consume capacity; retention
scans run in the periodic tick at most every ten minutes. The raw limiter's
small table is maintained separately so pending-only reception stays usable.
Auto ticks finish the FastCGI response before taking the tick claim lock;
other runtimes and external mode rely on the configured periodic tick.

## Verification

- Unit tests cover admission before/after adoption, credentials, transport,
  spoofed proxy headers, parser boundaries, inventory capacity, periodic
  expiry without further uploads, sender/IP isolation, schema upgrade,
  timestamp fallback and storage failures with redacted diagnostics.
- A real local HTTP test runs four PHP workers and eight concurrent first-use
  WU requests; it also races replacement against eight uploads and verifies
  that old credentials fail, replacements retain the sender, blocked states
  survive, journal history/deduplication remain intact, and external/non-FastCGI
  auto mode do not claim or run a tick.
- WeeWX conformance checks cover normalized ingest-to-archive data and existing
  archiving/unit behavior.
- The full unit run passes 282 tests / 1,726 assertions; all WeeWX conformance
  checks pass. The optional weewx-evo upload comparison is skipped without its
  external mount. Formatting passes for the 17 touched PHP files. The last
  global PHPStan run aborted with an internal reflection error for
  `Frontend\\Weather::$outputProfile` in a file being edited in parallel.
  The separate PHPStan check passes for the ingest/integration files and tests;
  the final focused run passes 42 tests / 308 assertions.

Composer's production audit was attempted with `audit --locked --no-dev`.
It reports "No installed packages found" and exits nonzero; `composer.lock`
contains an empty production `packages` array. No production package advisory
result is therefore claimed. Development dependencies and the hosting runtime
are outside that production-package audit.

## Ecowitt field catalogue follow-up — 2026-09-05

**Security-Sensitive: YES. Reviewed by: /root. OWASP categories: 10/10.**

Reviewed the complete affected input/mapping and administration files:
`Ingest/Parser`, `Ingest/Field`, `Ingest/EcowittFields`,
`Measurement/Catalog`, `Config/ArchiveConfig`, `Archive/Mapping`,
`Archive/Archiver`, `Admin/Service`, `Admin/Page`, `Admin/Changes`,
`Admin/Jobs`, `Admin/Translator`, `Cli/Commands/VerifyCommand`,
`Cli/Commands/CheckConfigCommand` and `Frontend/Value`, together with
the unit-group/policy integration and the new parser/archive tests.

| Category | Result for this change |
|---|---|
| A01 Access control | Pass: discovery/adoption gate and administrator authentication/CSRF boundaries unchanged; pending integration test still creates no live journal. |
| A02 Cryptography | Pass within existing scope: no credential or transport changes; redacted samples remain bounded. |
| A03 Injection / XSS | Pass: fixed catalogue targets, bounded channel families, finite numeric conversions; existing identifier validation and HTML escaping also cover new kinds/units. |
| A04 Design | Fixed data-integrity defects: percentage/centibar distinction, separate battery level/status/voltage, snapshot aggregation and daily lightning counter. |
| A05 Configuration / XXE | Pass: unknown fields remain diagnostic inventory, no automatic schema mutation or new parser/endpoint. |
| A06 Components | No added dependencies; production Composer audit attempted again. The lock has zero production packages; Composer reports no installed packages rather than advisory results. |
| A07 Authentication | Pass within existing scope: no new identity input or admission bypass; invalid battery levels cannot turn into healthy status. |
| A08 Integrity | Pass: incompatible new measurement mappings and non-last snapshot aggregation are rejected; actual rain-counter-to-interval delta remains summed. |
| A09 Logging | Pass: no added raw request logging or secret output; existing reception diagnostics remain in place. |
| A10 SSRF | Pass: no network request is added to ingestion or field recognition. |

No critical/high security findings remain in these changes. Existing data
is not migrated or reinterpreted; renamed moisture, battery-voltage and
lightning-counter sources are documented in `docs/ingest.md`.

Validation: 311 unit tests / 3,161 assertions pass, including a real
pending → adopted → live → archive test with the new fields. Scoped PHPStan
passes. WeeWX conformance checks `units`, `accum`, `archive`, `ingest` and
`tick` pass (including multiworker HTTP discovery and 5,424 packets over a
day). Project formatting was applied to the changed PHP files.

The final targeted run passes 70 Admin/Ingest tests / 1,773 assertions.
It also covers a concurrently introduced undefined storage-unit variable
in the archive view, now resolved from the archive's stored unit system.
PHPStan passes for that final view/translator adjustment as well.

**Security review status: PASS within the existing HTTP/adoption design.**
