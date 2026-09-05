# HTTP ingest

Ecowitt Custom uploads and the Weather Underground PWS protocol are received by
PHP on ordinary web hosting. The receiver requires no daemon or UDP socket.
Its administration and diagnostic commands are available through the CLI.

The separate Raspberry/LAN collector uses the [native WeeWX JSON contract](native-ingest.md):
HTTPS batches of original LOOP packets, stable station/event IDs, durable ACKs
and historical replay. It does not use the firmware form protocols below.

Serve **public/** as the document root, keep the configuration and data outside
it, and enable the receiver:

```ini
[Ingest]
    enabled = true
    public_url = https://weather.example.org
    http_ecowitt = true
    http_wunderground = true
```

The host must actually accept HTTPS and, for HTTP-only consoles, port 80.
These settings do not open ports or provision certificates. Prefer HTTPS on
consoles that support it. Ecowitt Custom commonly needs HTTP; select port 80
for it. Do not redirect these uploads to HTTPS. With `http_ecowitt = false`
or `http_wunderground = false`, plaintext requests for that protocol are
refused, never redirected.

## Connection details

```sh
php bin/weewx-php ingest endpoints
```

This initializes and displays a common, randomly generated twelve-character
Ecowitt path and the next unused twelve-character WU password. Running the
command repeatedly does not change either.

| Console setting | Ecowitt | Wunderground |
|---|---|---|
| Server | Your host name, without a scheme or path | Your host name |
| Port | 80 for HTTP; 443 if the console supports HTTPS | 443 for HTTPS, otherwise 80 |
| Protocol | Ecowitt | Wunderground |
| Path | The path displayed by `ingest endpoints` | Usually fixed in firmware |
| ID | Supplied automatically as PASSKEY | Any name; it need not be unique |
| PASSWORD / Station Key | No field needed | The displayed unused WU password |

Ecowitt uses `POST /<key>/ecowitt/`. `public/.htaccess` routes it on Apache
2.4 with mod_rewrite. Without rewriting, use
`/receive.php/<key>/ecowitt/`, provided the host enables PHP PATH_INFO.

WU accepts GET or form POST on `/weatherstation/updateweatherstation.php`.
The PHP endpoint is a real file and needs no rewrite. The `.asp` and
extensionless variants use the included rewrite rules. Firmware with a
fixed root path needs this endpoint at the domain root, even if the rest of
the application normally lives in a subdirectory. Hosts that disable
`.htaccess` directives need equivalent routing in their control panel.

The application never auto-detects a protocol on arbitrary public paths.
Only these routes accept uploads. No redirects or outbound HTTP requests are
used to receive a measurement.

## Discovery and adoption

```sh
php bin/weewx-php ingest list
php bin/weewx-php ingest sample ecowitt_<sender>
php bin/weewx-php ingest adopt ecowitt_<sender> "Garden"
```

A first valid upload creates a `pending` sender in `data/ingest.sdb`. It
keeps one credential-redacted data sample (up to 8 KiB), the device identity,
model, source address, transport, reception time and counters. Its sample is
replaced on the next upload and expires after a day without uploads; sender
identities and assigned credentials persist. The periodic tick, or
`ingest prune`, performs cleanup at most every ten minutes. Keep the tick
scheduled even while all senders are pending or reception is disabled.

Pending senders receive the protocol's normal acknowledgement. They do not
write to `live.sdb`, schedule archive intervals, or trigger ticks. Adoption
allows **subsequent** uploads into the journal; the discovery sample is not
replayed. Credentials are never returned by an HTTP response.

Ecowitt senders share their path and are distinguished by the console's
case-normalized PASSKEY. WU senders are distinguished by their assigned
password, not their non-unique `ID`. On the first valid WU upload, assigning
the current password and generating its replacement happen in one SQLite
transaction. Later uploads with an assigned password use the same sender,
including after PHP restarts. Invalid requests do not consume a password.
Only a SHA-256 digest of each assigned random WU credential is retained; the
currently unused credential and the Ecowitt path remain available for setup.

```sh
php bin/weewx-php ingest ignore <sender>
php bin/weewx-php ingest block <sender>
php bin/weewx-php ingest reset <sender>
```

Ignored and blocked senders retain their identity and assigned password but
store no new data sample or live packets. They still get an acknowledgement,
so a console is not trapped in a retry loop. `reset` returns a sender to
pending; `adopt` can also re-enable it directly. Previously recorded packets
are retained.

Adopted senders automatically become available to configuration and station
status. There is no need to add them under `[Stations]`. To select one in an
existing archive, use the sender id printed by `ingest list`:

```ini
[Archives]
    [[garden]]
        unit_system = METRICWX
        primary = ecowitt_<sender>
        senders = ecowitt_<sender>
```

Existing primary/sender/mapping choices are preserved. As before, an archive
with no explicit primary chooses its first selected sender; additional
senders need explicit field placement to avoid mixing measurements. Ingest
does not create archive columns.

With `tick_mode = auto` (default), a new accepted live packet can trigger
a tick at most once per minute, **only after a successful
fastcgi_finish_request()**. Other PHP runtimes leave work for the scheduled
tick. `tick_mode = external` always leaves archival work to that separate
tick, including on FastCGI. Reception never falls back to a slow inline tick
with an unconfirmed response. Schedule a tick every minute or every five
minutes, depending on the desired archive/upload latency. This also closes
the last interval when consoles stop and cleans expired discovery samples.

## Reception diagnostics

```sh
php bin/weewx-php ingest status
php bin/weewx-php ingest status <sender>
```

Each sender has one bounded diagnostic record. It counts `received`
attributable requests as `stored`, `duplicates`, `discarded` or `pending`.
Pending is a successful upload held out of the journal by adoption;
discarded includes rejected requests and measurements from ignored/blocked
senders. Duplicates are recognized by the existing live journal. These
counters start at `since`; an upgrade does not invent a breakdown for past
uploads. The older `ingest list` received/stored counters remain intact.

The record keeps the latest attributable reception, latest valid upload,
interval between the last two valid arrivals (seconds), last rejection and
its time. The last error stays dated after recovery. `time` is `device` or
`server`; `reason` distinguishes a usable device timestamp, requested/missing
server time, an invalid timestamp and one outside the accepted range.
`clock_offset` is reported device time minus reception time; negative can
also indicate transmission delay. Invalid/missing times have no offset.
The original valid timestamp's offset is retained even when out of range.
No measurement history is added to the discovery database.

Unattributable failures, including outer rate-limit refusals, are counted
globally by a fixed reason without guessing a station from its IP or WU ID.
HTTP 429 events update these counters without a log line for every retry.
If SQLite itself is unavailable, only the existing generic error log and
503 response are possible.

## Replace credentials

```sh
php bin/weewx-php ingest rotate wunderground
php bin/weewx-php ingest rotate <wu-sender>
php bin/weewx-php ingest rotate ecowitt
```

The first command discards the free WU password, leaving assignments alone.
The second replaces one sender's assigned password and displays it once;
only its digest is stored. The sender id, archive mappings, adoption/block
state, counters and live history stay intact. The free password is not used.
The third replaces the shared Ecowitt path; every Ecowitt console must be
updated, and its PASSKEY still selects the same sender.

The old credential stops working immediately. Replacement and first-use
assignment serialize in SQLite; concurrent uploads cannot assign an old
credential to a new station. `ingest endpoints` shows the current setup
credentials, but cannot recover a password assigned to an existing sender.

## Measurements

The first receiver normalizes outdoor and indoor temperature/humidity,
wind, pressure, radiation, UV, rain counters/rate, soil moisture/temperature,
extra temperature/humidity channels, WN34 probes, selected battery fields,
CO2, PM2.5/PM10 and lightning. Names and imperial units come from the stored
console captures and WU specification in `tests/uploads`; scalar values
must be finite. Missing readings remain null. Unknown fields are retained
in the redacted sample/raw upload within its size and retention limits.

The metric Observer dialect is recognized from its field names. Its rain
is millimetres and its UV value is irradiance, not an index. Select
`metric_wind = kph` (default) or `mps` to match that firmware. Known
WH2600GEN_V2.2.5/WH2650A_V1.2.1 pressure semantics are handled explicitly.
Ecowitt WN34 channels map to `extraTemp9` upwards, WH52 soil temperature to
`soilTemp1` upwards. Existing archive field mappings can change placement.
Rain totals are preserved as counters; the archiver derives interval rain.

### Ecowitt field semantics

`Ingest/EcowittFields` is the allowlisted catalogue for customized HTTP
uploads. Each entry defines its wire unit, normalized observation and,
where specified by the sensor, numeric range. Channel families have fixed
bounds. `Measurement/Catalog` supplies the measurement type and snapshot
semantics shared by mappings and archive policies. Unknown numeric fields
remain available in the bounded native inventory; reception never guesses
their units or creates archive columns.

| Wire field | Observation | Normalized unit | Archive rule |
|---|---|---|---|
| `wh57batt` | `lightning_Batt` | Level 0–5 | Last valid value |
| Derived from `wh57batt` | `lightningBatteryStatus` | 1 = low, 0 = normal | Last valid value |
| `wh65batt` | `outTempBatteryStatus` | 1 = low, 0 = normal | Last valid value |
| `wh80batt`, `wh90batt` | `wh80_batt`, `wh90_batt` | V | Last valid value |
| `tf_battN`, `soilbattN`, `soil_ec_battN` | Existing WN34 / WH51 / WH52 battery names | V | Last valid value |
| `winddir_avg10m` | `windDir10` | Degrees | Last console average |
| `maxdailygust` | `maxdailygust` | Archive speed unit; wire mph | Last console daily maximum |
| `last24hrainin` | `rain24` | Archive rain unit; wire inches | Last rolling 24-hour amount |
| `lightning_num` | `lightningDayCount` | Daily strike count | Last console counter |
| `vpd` | `vpd` | kPa in all three unit systems; wire inHg | Average |
| `soilmoistureN`, `soil_ec_humN` | `soilMoistPctN` | % | Average |
| `soil_ecN` | `soilECN` | µS/cm | Average |
| `soil_ec_hum_adN`, `soil_ec_adN` | Unmapped diagnostic inventory | Raw value | No default archive field |

Soil and probe families cover channels 1–16. Existing temperature/humidity
families retain their previous limits. Where the console supplies aliases
for one channel, the first valid catalogue entry wins; the native inventory
still records each alias's own value.

WH57 levels 0 and 1 mean low battery. Invalid levels (including disconnected
sentinels such as 9), fractions and nonfinite readings produce no valid
battery state. They are never interpreted as a healthy battery. The latest
packet can contain a missing state; archive intervals retain their last
valid reading, as with other missing measurements.

Snapshot types retain `last` aggregation when placed in a named custom
column. The administration form supplies this rule, and mapping validation
refuses incompatible types or snapshot aggregations. Rolling rain cannot
feed interval `rain`, a console daily gust cannot become the current
`windGust`, and percentage moisture cannot enter a centibar column. Actual
rain counters selected as `rain` continue through the existing delta
calculation and interval sum.

**Changes from the initial parser:** Ecowitt percentage moisture now uses
`soilMoistPctN`, leaving standard WeeWX `soilMoistN` in centibar. WH80/WH90
voltages have individual fields instead of sharing the WH65 warning flag.
The Ecowitt daily lightning counter uses `lightningDayCount` instead of the
interval observation `lightning_strike_count`. Existing archive history is
not reinterpreted or rewritten. Update previously configured placements to
the new source names and use appropriately typed columns for future data.

Protocol evidence: the [Ecowitt HTTP API documentation](https://oss.ecowitt.net/uploads/20260109/HTTP%20API%20interface%20Protocol%20%28Generic%29-%28V1.0.5-2025-10-08%29%20.pdf)
documents WH57 levels and the low-battery threshold; API wire encodings for
other models are not interchangeable with customized POST values. The
[WH52 manual](https://oss.ecowitt.net/uploads/20260130/WH52UserManual.pdf)
specifies 0–100% moisture and 0–10000 µS/cm conductivity. The
[ecowitt-exporter implementation](https://github.com/djjudas21/ecowitt-exporter/blob/main/ecowitt_exporter.py)
identifies HTTP `vpd` as inHg. Our HP2561 capture's `vpd=0.047` consequently
becomes approximately 0.15916 kPa, consistent with its temperature and RH.

UTC timestamps up to four hours old or sixty seconds ahead are retained.
Missing, invalid or implausible timestamps use reception time. Duplicate
timestamp/payload pairs use the journal's existing deduplication. Protocols
that report `dateutc=now` have no sequence number; identical uploads in
different reception seconds cannot reliably be distinguished from new
unchanged measurements.

## Limits and proxy configuration

Request bodies/queries are limited to 64 KiB, 768 fields, and 512 bytes per
value. Duplicate parameter names, parameter arrays and malformed encodings
are refused. The receiver stores at most `max_pending = 100` pending
senders and 2,000 identities in total. Reaching the discovery limit does not
consume the next WU password or stop existing senders.

Rate limiting is shared by all PHP workers through SQLite: by default
`requests_per_minute = 300` per source address and ten times that globally.
Every authenticated sender also gets `sender_requests_per_minute = 120`
across all source addresses. IP/global limits still apply before parsing.
Thirty attributable malformed uploads in five minutes block only their
sender for ten minutes. Unauthenticated failures temporarily block discovery
on their source address; already authenticated senders on that address can
continue within the outer request limits. Malformed forms that cannot be
authenticated are never attributed from an untrusted identity alone.
The limiter keeps at most 4,096 outer rows plus one row per known sender;
its small table is cleaned periodically during reception. HTTP 429 includes
Retry-After. Fixed windows permit a burst around a window boundary.
The web server must also limit header sizes and slow request-body reads;
PHP normally receives control after the server has read the request.

Forwarding headers are ignored unless the direct peer is explicitly listed:

```ini
[Ingest]
    trusted_proxies = 127.0.0.1, ::1
```

Such a proxy must **overwrite** X-Forwarded-For with one client IP and
X-Forwarded-Proto with the original scheme. Appended address chains are not
trusted. Do not list networks, arbitrary clients or a proxy that merely
passes these headers through. On shared hosting use the provider's
documented TLS/client-address setup.

Disable or redact URL/query logging for ingest routes at the web server and
proxy: Ecowitt's path and WU's query contain credentials. Application logs
record fixed rejection reasons and source addresses, never request URLs or
payloads. Adoption provides admission control, not cryptographic proof of
hardware identity. Unencrypted HTTP and copied credentials retain the
limitations discussed in the design.

## Local console test

Use a separate test configuration, without upload destinations:

```ini
data_dir = data/console-test
timezone = Europe/Berlin
[Ingest]
    enabled = true
    public_url = http://<LAN-IP>:8080
[Archives]
    [[test]]
        unit_system = METRICWX
```

```sh
php bin/weewx-php --config console-test.conf ingest endpoints
WEEWX_PHP_CONF=/absolute/path/console-test.conf php -S 0.0.0.0:8080 -t public bin/ingest-router.php
php bin/weewx-php --config console-test.conf ingest list
php bin/weewx-php --config console-test.conf ingest adopt <sender> "Test console"
php bin/weewx-php --config console-test.conf ingest status <sender>
php bin/weewx-php --config console-test.conf tick
```

Set the environment variable with `$env:WEEWX_PHP_CONF = 'D:\path\console-test.conf'`
on PowerShell before running the PHP server. The LAN host address and port
8080 must be reachable from the console. The built-in PHP server is only
for the local test; use the hosting provider's web server for deployment.

UDP broadcasts, USB/serial collection and API polling are outside this
receiver. A later local collector can forward those sources over HTTPS.
