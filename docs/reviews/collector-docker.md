# Collector → PHP Docker integration

Run: 2026-09-05. Result: **PASS**. No application-code changes were needed in
either project. Added a reusable Docker fixture and test runner in weewx-php.

The full Python collector runs in one Linux container: its CLI supervisor,
two real WeeWX Simulator workers and separate uploader. A second container
runs the actual PHP application behind a test TLS terminator. The collector
verifies the certificate; an untrusted certificate is explicitly refused.
Each application has its own database directory. Observations cross HTTPS,
not a shared database or a replacement receiver implementation.

| Measurement | Result |
|---|---:|
| Python / WeeWX / PHP | 3.13.15 / 5.5.0 / 8.4.24 |
| Simulator instances | 2, at 0.4 s and 0.7 s cadence |
| Persisted and received observations | 539 |
| HTTPS requests | 32 |
| Duplicate acknowledgements after a deliberately lost ACK | 3 |
| PHP receiver interruption | At least 70 seconds |
| Buffered observations at outage checkpoint | 282 |
| Separate archive records | 3 per station |
| Archive values compared against original WeeWX | 54 |
| Full scenario duration | 167.6 seconds |
| Existing collector tests in Linux Docker | 67 passed, none skipped |

The machine-readable result is [collector-docker-result.json](collector-docker-result.json).
Counts and duration vary with scheduling on subsequent runs.

## What passed

1. Pending discovery creates two separate stations and keeps unconfirmed
   observations in the local spool. PHP admission uses the actual collector CLI.
2. Killing one Simulator worker restarts that worker; the other worker keeps its
   PID and continues collecting. Both retain their separate station identities.
3. A real PHP commit followed by a deliberately dropped HTTP response keeps the
   affected events queued. Restarting the complete collector preserves their IDs;
   PHP acknowledges the retries without double-counting measurements.
4. Stopping the PHP HTTP process for more than one minute grows the local spool.
   The collector is stopped and restarted during the outage. Its original queued
   IDs and payloads survive unchanged and are delivered after PHP starts again.
5. Every event persisted by either worker appears exactly once in PHP's journal
   and receipt table. Station assignment, original timestamps, unit systems and
   every transmitted observation match. No events remain queued or quarantined.
6. PHP ticks run during the outage and after recovery. Once all intervals close,
   both archives match original WeeWX accumulation of the received observations.
   Daily temperature extrema match the original LOOP values; no replay jobs or
   retention holds remain outstanding.
7. Neither application's logs contain the temporary collector token. Requests
   stay within the native body-size limit and no event is rejected.

The existing collector suite additionally ran its real PHP integration test
inside Docker (`WEEWX_PHP_ROOT=/repo`), rather than skipping that optional test.
This run tests the Simulator and application integration; physical Davis/USB
devices and Raspberry storage behavior remain hardware acceptance work.

## Repeat the test

Run from the weewx-php project with weewx-php-ingest beside it. Docker Compose
mounts both source trees read-only. Build requires package access; runtime uses
an internal Docker network with no published ports.

```sh
docker compose -f tests/docker/compose.yml build conformance
docker compose -f tests/docker/collector.compose.yml build receiver
docker compose -f tests/docker/collector.compose.yml up -d receiver
docker compose -f tests/docker/collector.compose.yml run --rm collector
docker compose -f tests/docker/collector.compose.yml run --rm --no-deps unit
docker compose -f tests/docker/collector.compose.yml cp receiver:/fixture/report.json docs/reviews/collector-docker-result.json
docker compose -f tests/docker/collector.compose.yml down --volumes
```

The scenario normally takes about three minutes because it observes the real
60-second admission retry and real archive boundaries. Use a fresh fixture
volume on each run; the last command removes only this named test stack's
temporary setup/control volume. Application databases are in each container's
`/tmp`. The test adds no production HTTP control endpoint. Its small shared
fixture volume carries only test setup, control commands and comparison results.

- [Docker Compose](../../tests/docker/collector.compose.yml)
- [Integration runner](../../tests/integration/collector_docker.py)
- [Test Dockerfile](../../tests/docker/Dockerfile.collector)

## Security review

Security-sensitive: **YES**, for the test fixture's temporary credentials,
TLS proxy and database inspection. Reviewer: Codex `/root`. Status: **PASS**.
The collector and PHP production code were not modified in this task.

| OWASP category | Result |
|---|---|
| A01 Access control | No new product/admin route. Control uses the isolated fixture volume, unavailable to HTTP clients. CLI actions are fixed test operations. |
| A02 Cryptography | Verified TLS with a generated, short-lived test certificate. Private key stays in the receiver container. Random token is generated by the PHP CLI; token file mode is 0600. |
| A03 Injection | No shell execution with interpolated input. SQL inspection uses fixed statements. Station IDs pass through the real PHP CLI's validation. |
| A04 Design | Bounded request bodies, subprocess timeouts and scenario deadlines. Databases remain separate and assertions compare persisted event counts with receipts and journal rows. |
| A05 Configuration | Non-root application containers, read-only source mounts, internal network, no published ports. Production configuration is untouched. |
| A06 Components | Test image dependency audit: 42 packages checked, no known vulnerabilities. No production dependency added. |
| A07 Authentication | Collector uses the actual native token and receiver authentication; no test bypass is added. |
| A08 Integrity | JSON test control is parsed as data, never executable content. Assertions verify immutable retries, exact observation transport and archive results. |
| A09 Logging | Temporary tokens are absent from both application logs. Exported results contain counts and versions, no credentials or raw request headers. |
| A10 SSRF | Test TLS proxy has one fixed loopback PHP target. Runtime network has no external connectivity. |

Dependency audit ran against the built Python image in a disposable container:
`pip-audit`, 42 dependencies, no findings, exit 0. Test-image dependencies include
pytest 9.1.1 and cryptography 50.0.1. There are no deferred security findings.

## Tested source fingerprints

The collector working directory had no Git commit to identify. SHA-256:

```text
collector runtime.py
977434bceceb84ee8e4569e2c40091c77a01b8e657a7b6e68edd11ca42447287
collector uploader.py
68d7b8da81fd8bfec062f7873826d932e9a484c454946ef4a8a79f62430446d9
PHP NativeReceiver.php
c90d83845ecf6f6c9282394e2813873f1c3707c94c9e80f9898eade610610111
integration runner
48ff9573d02b4a393b30dd68bfd4514d0370a088eb6754f95974cb183c3295c7
```
