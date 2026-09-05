# Configuration reference

Every option of `weewx-php.conf`, in the order the example file has them.
The file has the shape of `weewx.conf`: a section is a name in brackets, a
section inside it has one bracket more, a list is separated by commas, a
value with a comma or a `#` in it is quoted, and anything after `#` is a
comment. The application reads the file and writes it back with the
comments and the order kept.

A duration is a number of seconds, or a number followed by `s`, `m`, `h`
or `d`: `90`, `5m`, `2h`, `7d`.

Paths are relative to the directory the file is in, or to `data_dir`
where an option says so.

## Settings

The top of the file, before any section.

### data_dir

Where the databases, the log and the lock file live. Default: `data`.
Inside it: `live.sdb` (the journal), `ingest.sdb` (discovery, adoption and
ingest credentials), `state.sdb` (the application's own state),
`log/weewx-php.log` and the day's rotations beside it, `tick.lock`,
and the archive databases where `database` puts them.

### timezone

The zone a day is counted in, for every archive that does not name its
own. A daily summary is keyed on local midnight. Default: `UTC`.

### archive_interval

How long one archive record is, for every archive. A whole number of
minutes between `1m` and `1h`. Default: `5m`. Every record carries its
interval, so the value can change at any time.

### archive_delay

Seconds an interval is held back after it ends, so that a packet which is
merely slow does not force the interval to be built twice. 0 to 300.
Default: `15`.

### loop_hilo

Whether the packets of an interval sharpen the day's highs and lows, as
WeeWX's option of the same name does. Off, the record's own values are the
extremes. Default: `true`.

### late_packets

What happens to a packet that arrives after its interval was archived.
`ignore` clears the mark and leaves the record alone. `rebuild` builds the
interval again and replaces the record, its share of the daily sums
included. Default: `ignore`.

### live_retention

How long a packet stays in the journal. At least `1h`. Default: `7d`.
Rebuilding a span needs its packets, so this is how far back a rebuild
reaches.

### raw_retention

How long the raw upload stays stored beside a packet, for looking at what
a console actually sent. 0 to `1d`. Default: `1h`.

### time_budget

Seconds one tick may spend building records, over all archives together.
1 to 3600. Default: `20`. Under PHP's own execution limit, two thirds of
that limit apply instead. What does not fit stays marked for the next
tick.

### max_intervals_per_run

How many intervals one archive may build in one tick. At least 1.
Default: `100`.

### journal_mode

SQLite's journal mode for every database: `wal` or `delete`. Default:
`wal`. `delete` is for a data directory on a network file system, where
WAL is not safe.

### tick_token

What a call of `tick.php` has to carry, as `?token=` or as the header
`X-Tick-Token`. Without a token, every call is refused. The command line
needs none. Default: none.

### log_level

The lowest level written to the log: `debug`, `info`, `warning` or
`error`. Default: `info`. The log starts a new file each day and keeps
fourteen.

## [Ingest]

HTTP reception for Ecowitt, Wunderground and native WeeWX collectors. See
[HTTP ingest](ingest.md) and [native ingest](native-ingest.md) for routing,
adoption, transport and proxy requirements.

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Enable the receiver |
| `public_url` | empty | Public HTTP(S) base URL, including a subdirectory if needed; used for connection details |
| `http_ecowitt` | `true` | Allow plaintext Ecowitt uploads |
| `http_wunderground` | `true` | Allow plaintext WU uploads |
| `trusted_proxies` | empty | Exact proxy IP addresses whose overwritten forwarding headers may be used |
| `requests_per_minute` | `300` | Requests per source address; the global limit is ten times this |
| `sender_requests_per_minute` | `120` | Requests per authenticated sender or native collector across source addresses |
| `tick_mode` | `auto` | `auto`: tick only after a completed FastCGI response; `external`: scheduled tick only |
| `max_pending` | `100` | Maximum unadopted legacy senders, or native stations per collector; existing senders continue receiving |
| `max_native_receipts` | `2000000` | Native WeeWX receipt capacity; size for 30 days of all stations, see [native ingest](native-ingest.md) |
| `metric_wind` | `kph` | Metric Observer firmware's wind units: `kph` or `mps` |

The transport options refuse HTTP when false; they do not configure the
web server's ports, TLS or redirects.

## [Stations]

One section per sender, under the id the sender writes into its packets.
An archive's `primary` and `senders` refer to these ids.
Adopted HTTP senders are available automatically without a section here.
An explicit section using their generated sender id can refine their name
and silence thresholds.

### name

What the status calls the station. Default: the id.

### expected_interval

Seconds between two packets, as a duration. Only for judging the
station's silence; without it, a station that has been heard from is
always `ok`. Default: none.

### stale_after

Intervals of silence until the station counts as `stale`. Default: `3`.

### down_after

Intervals of silence until the station counts as `down`. Default: `20`.

## [Archives]

One section per WeeWX database, under an id of its own. Everything about
a series hangs here.

### name

What the status calls the archive. Default: the id.

### location

The place, as WeeWX's `location`. Default: the name.

### latitude, longitude

Decimal degrees, south and west negative. Without them the clear-sky
radiation and evapotranspiration are not worked out. Default: none.

### altitude

The height above sea level and its unit: `440, meter` or `1443, foot`. A
number alone is metres. Without it the pressures, the cloud base and
evapotranspiration are not worked out. Default: none.

### database

The path of the WeeWX database, relative to `data_dir`. Default:
`archives/<id>.sdb`. A file that does not exist is created with WeeWX's
`wview_extended` schema; a file that exists is used as it is.

### unit_system

The unit system a database made here is created in: `US`, `METRIC` or
`METRICWX`. Default: `US`. A database that exists keeps its own.

### timezone

This archive's zone, if not the installation's. Default: the setting
`timezone`.

### primary

The one sender whose readings go to the columns of their names. Must be
among `senders`. Default: none, and then the selected sender that was
heard first.

### senders

The senders this archive reads, or `*` for every one that delivers.
Default: every one.

### auto_mapping

Whether the primary's readings go to the columns of their names even in a
database this application did not create. Default: `false`, and then such
a database is not written until `[[[fields]]]` places the primary's
readings or this is set.

### [[[members]]]

One section per sender, under its id.

#### indoor

Whether the sender's `inTemp`, `inHumidity` and `inDewpoint` belong in
this series. A reading placed under `[[[fields]]]` goes regardless.
Default: `true`.

### [[[columns]]]

Columns added to the database if missing, one line per column: the name
and its SQL type, `REAL`, `INTEGER` or `TEXT`. Each gets its daily
summary table, as WeeWX's `weectl database add-column` gives it. Default:
none.

### [[[fields]]]

One section per sender, under its id, with one line per reading: the
reading's name and the column it goes to, or `-` to drop it. For the
primary this moves or drops readings that would otherwise go by name; for
every other sender it is the whole of what the sender writes, plus
readings whose names end in `Batt`, `batt`, `_rssi`, `_sig` or
`BatteryStatus`. Default: none.

### [[[extractors]]]

How a reading is taken out of an interval into the record, for readings
that are not averaged: `avg`, `sum`, `first`, `last`, `min`, `max`,
`count`, `wind` or `noop`. WeeWX's `[Accumulator]` section. Default:
WeeWX's own table, which sums `rain` and `ET`, keeps the last value of the
counters, and averages the rest.

### [[[calculate]]]

Per derived reading, what decides it: `prefer_hardware` takes the
station's value where it sent one and works the reading out otherwise,
`hardware` never works it out, `software` always does. The readings are
`pressure`, `altimeter`, `barometer`, `appTemp`, `cloudbase`, `dewpoint`,
`ET`, `heatindex`, `humidex`, `inDewpoint`, `maxSolarRad`, `rainRate`,
`rain`, `windchill`, `windrun`, `windDir` and `windGustDir`. Default:
`prefer_hardware` for all but the two directions, which are `software`,
meaning a direction with no wind behind it is cleared.

### [[[qc]]]

One line per reading: the minimum, the maximum, and optionally the unit
the two are written in, as `outTemp = -40, 60, degree_C`. A reading
outside its limits is set to null, as WeeWX's `[StdQC]` sets it. Without a
unit the limits are in the unit `unit_system` names for the reading, or,
without that as well, in the archive's own. Default: none.

#### unit_system

The unit system limits without a unit are written in: `US`, `METRIC` or
`METRICWX`. Default: none, meaning the archive's own.

### [[[calibrate]]]

One section per sender, under its id, with one line per reading: the
offset and optionally the scale, applied as `value * scale + offset` in
the archive's units, before the limits and before anything is derived.
The reading is named as it is in the archive, after `[[[fields]]]` has
placed it. Default: none.

## [Uploads]

One section per service the readings go to, under a name of your own.
Two accounts with the same service are two sections. Every section has
the keys below and then its kind's own.

### kind

Which service: `wunderground`, `pwsweather`, `wow`, `windy`,
`weathercloud`, `cwop`, `mqtt` or `influx`. Required.

### archive

The id of the archive whose readings go. Required when there is more than
one archive; a registration with a weather service is for one place, and
the coordinates sent along come from it. Default: the only archive.

### trigger

When the upload runs. `record` sends what the archive holds since the last
time, after every tick. `interval` runs on its own rhythm, on the hour's
grid, see `every`. `live` sends the newest packet every tick and is for
`mqtt` only. `manual` runs only for `weewx-php upload run`. Default:
`record`, except `interval` for `cwop` and `live` for `mqtt`.

### every

Seconds between runs on the `interval` trigger, as a duration, on the
hour's grid: `10m` runs at :00, :10, :20. `1m` to `1d`. Default: `10m`
for `cwop`, `15m` otherwise.

### catch_up

How many records one run may send when the service missed some. 0 means
only the newest. Never more than six hours back, whatever the number.
0 to 288, `influx` up to 1000000. Default: `12`; `5000` for `influx`;
`0` for `cwop`, `weathercloud` and `mqtt`, which take no timestamp with a
reading and are only ever sent the newest.

### timeout

Seconds one request may take. 1 to 60, `influx` up to 300. Default:
`10`; `30` for `influx`.

### stale

How old the newest record may be and still be sent as current, as a
duration, by a service that takes no timestamp. At least `1m`. Default:
`15m`; `10m` for `cwop`.

### wunderground, pwsweather

`station`, the station id; `password`, the station key for Weather
Underground and the account password for PWSweather; `indoor`, whether
`inTemp` and `inHumidity` go too, default `false`.

### wow

`station`, the site id; `password`, the six-digit authentication key.

### windy

`api_key`; `station`, which of the key's stations, counted from zero,
default `0`.

### weathercloud

`wid`, the device id; `key`, the device key; `indoor`, default `false`.

### cwop

`station`, the CWOP id such as DW1234, or a licensed callsign; `passcode`,
default `-1`, which is right for a DW or EW station; `latitude` and
`longitude`, default the archive's; `servers`, tried in order, default
`cwop.aprs.net:14580, cwop.aprs.net:23`.

### mqtt

`host`, required; `port`, default 1883, or 8883 with `tls`; `username`
and `password`; `client_id`, default one made up; `tls`, default `false`;
`tls_verify`, default `true`; `topic`, default `weather`: each reading
goes to `<topic>/<name>` and the whole record to `<topic>/loop`;
`unit_system`, `US`, `METRIC` or `METRICWX`, default empty for whatever
the archive holds; `append_units`, whether the names carry the unit, as in
`outTemp_C`, default `true`; `aggregate`, the JSON document, default
`true`; `individual`, one topic per reading, default `true`; `retain`,
default `true`; `qos`, `0` or `1`, default `0`; `home_assistant`, whether
the readings are announced under `homeassistant/` so the station appears
as a device, default `false`; `discovery_prefix`, default `homeassistant`;
`station`, what Home Assistant calls the device, default the archive's
name; `keepalive`, default `60`.

### influx

`url`, required, such as `http://influxdb:8086`; `api`, `v2` or `v1`,
default `v2`; `bucket`, required, the database for `v1`; `org`, for `v2`;
`token`, required for `v2`; `username` and `password`, for `v1`;
`measurement`, default `weather`; `location`, the tag every point
carries, default none; `unit_system`, what the points are written in,
`METRICWX`, `METRIC` or `US`, default `METRICWX`.
