# How weewx-php is built

Nobody needs to read this to run the application; the
[README](../README.md) and the [configuration reference](configuration.md)
are for that. This is for whoever wants to know why it is built the way it
is, where it departs from WeeWX, and what the conformance checks prove.

weewx-php is a port of the core of [weewx-evo](https://github.com/hilman2/weewx-evo),
which built this design in Python and measured it against WeeWX. What
changed in the port follows from PHP on a shared host: no process that
runs, only ticks with a time budget; one configuration file instead of
five.

## Three parts and one table between them

```
stations ──> live journal ──> archiver ──> WeeWX archive
             (live.sdb)       per archive  (archives/<id>.sdb)
                    ▲              ▲
                    └── tick.php ──┘
```

**The live journal** is a table of packets as they arrived: which sender,
when, in what unit system, the readings as JSON, and a digest of the
readings so that a console retrying an upload is not counted twice. It is
the whole contract between whatever delivers and the archiver. An ingest
writes packets and marks the interval they fall in as pending for every
archive; it never touches an archive database.

```sql
CREATE TABLE packet (
    seq       INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    dateTime  INTEGER NOT NULL,     -- when the reading was taken
    received  INTEGER NOT NULL,     -- when it reached the server
    driver    TEXT    NOT NULL,     -- which ingest read it
    identity  TEXT    NOT NULL,     -- what the hardware calls itself
    sender    TEXT    NOT NULL,     -- the id [Stations] and archives use
    dialect   TEXT,                 -- NULL when the names are WeeWX's
    mapping   TEXT,                 -- a stored description of that dialect
    kind      TEXT    NOT NULL,     -- 'loop' or 'archive'
    usUnits   INTEGER NOT NULL,
    interval  REAL,                 -- minutes an archive-kind packet stands for
    digest    TEXT    NOT NULL,
    data      TEXT    NOT NULL,     -- the readings, as JSON
    raw       TEXT                  -- the upload as it came off the wire, for a while
);
CREATE TABLE pending (stop INTEGER NOT NULL, seconds INTEGER NOT NULL, archive TEXT NOT NULL, PRIMARY KEY (stop, archive));
CREATE TABLE sender_identity (sender TEXT PRIMARY KEY, driver TEXT, identity TEXT, label TEXT, first_seen INTEGER NOT NULL DEFAULT 0);
CREATE TABLE dialect_mapping (digest TEXT PRIMARY KEY, spec TEXT NOT NULL);
CREATE TABLE live_metadata (name TEXT PRIMARY KEY, value TEXT);
```

The schema is weewx-evo's, word for word, so a journal the two share
agrees about what a packet is. This core reads packets whose names are
WeeWX's, `dialect` NULL; a packet in a console's own vocabulary waits in
the journal for an ingest catalog that translates it, and is reported once.
`live_metadata.archives` holds the ids of the configured archives, so an
ingest outside this application knows what to mark pending.

The native WeeWX HTTP producer adds `weewx_collector`, `weewx_station`,
`weewx_receipt`, `weewx_replay` and `weewx_hold` alongside this unchanged packet
table. Native packets use a digest of their event ID for journal uniqueness,
preserving distinct equal-valued samples within a second. Receipts enforce
immutable content per event. The live database now uses `synchronous=FULL` so
an ACK can release the collector's local copy. See [the native contract](native-ingest.md).

**The archiver**, one per archive, turns the journal into records. An
archive record is a function of a time span: `build(stop)` reads the
packets of the interval ending at `stop`, and gives the same record now,
after a restart, or a week later. That is what makes a tick enough, and
what makes a rebuild honest.

**The tick** is everything the application does between two calls, under
a lock: build what is due for each archive within the budget, walk the
journal in full when that has not happened for an hour, judge the
stations' silence, prune the journal every ten minutes, and note the run
in `state.sdb`, the application's own state, so that the archive databases
hold nothing but what WeeWX put there.

## Keeping the one rule

An archive database must stay readable and writable by WeeWX, and a
record appended or a column added must be what WeeWX would have done.
That is kept by transcription rather than by reasoning:

- A new database is created with the statements WeeWX uses, word for
  word, so `sqlite_master` of a file made here reads as one WeeWX made.
  The archive table is `wview_extended`, 115 columns; every observation
  column gets a daily summary table, `wind` a vector one, and the
  metadata says version 4.0.
- The accumulator is `weewx.accum` step by step: the same statistics, the
  same extractors, the same weighting of a record in its day by
  `60 * interval`, the same handling of the wind vector down to the order
  the gust and the speed go in.
- A day is keyed on the local midnight of the archive's zone, and a
  record stamped exactly at midnight closes the previous day, as
  `startOfArchiveDay` has it. `lastUpdate` moves forward to the record
  written.
- Units are WeeWX's `conversionDict` transcribed, the not quite
  self-inverse table included: a conversion here drifts exactly as far
  as it drifts in WeeWX.
- The formulas are `wxformulas.py` and `uwxutils.py` expression by
  expression, constants included, with Python's rounding where the
  console's rounding is imitated.
- A record replaced takes its old share out of the day's sums before its
  new one goes in. Extremes cannot be taken back, a maximum does not
  remember the runner-up, so a rebuild builds each day from nothing and
  sharpens it from the packets afterwards.

## What the archiver does with a packet

```
place ── convert ── calibrate ── check ── derive ── accumulate ── record ── derive again
```

`Mapping` decides which of a packet's readings go to which columns: the
primary sender by name, every other sender only what `[[[fields]]]` placed
plus its battery and signal readings. `Units::toSystem` converts into the
archive's unit system, WeeWX's `StdConvert`. `Quality` applies the
corrections and then the limits, `StdCalibrate` and `StdQC`. `Derived` is
`StdWXCalculate` with its four helpers, the pressure cooker, the rain
rater, the counter delta and the plain formulas, in the order WeeWX's
default configuration lists them. Then the accumulator, and once the record
exists, the derivations once more for the two readings that need an
interval, `windrun` and `ET`.

Deriving per packet matters: the dew point of an average hour is not the
average of the dew points, and WeeWX derives per packet too.

### Determinism and the run-up

WeeWX carries state between packets: the last reading of a rain counter,
the rain of the last quarter hour, the temperature of twelve hours ago.
Here everything that state would hold is read from the journal before the
span: the packets of the quarter hour before it, and the last packet with
a counter before that, however old. The temperature of twelve hours ago
comes from the archive, as WeeWX takes it. So a build depends on the
journal and the archive and on nothing that was built before it, and a
rebuild gives what the first build gave.

### Where it departs from WeeWX

Each of these is deliberate, and each is checked rather than assumed.

- **Rain from counters.** `rain` is taken from whichever of `dayRain`,
  `totalRain` or `eventRain` the console sends, and a total that fell is a
  reset whose new value is the amount. WeeWX takes one total named in the
  configuration and books nothing on a reset. weewx-evo's decision, kept.
- **The rain rate's quarter hour** is seeded from the packets before the
  interval, not from the archive's records as WeeWX seeds it on start. The
  packets are what WeeWX itself would have seen had it been running.
- **Refraction.** WeeWX places the sun with pyephem, which lifts it by
  refraction; this program uses NOAA's arithmetic for the position and
  libastro's own refraction model on top, transcribed, so that
  `maxSolarRad` agrees to a fraction of a watt even at the horizon, where
  the lift is a tenth of the value.
- **Quality control** takes limits in a named unit, as WeeWX does, and
  also a `unit_system` for limits without one. Calibration is an offset
  and a scale rather than WeeWX's arbitrary expression.
- **A database this application did not create** is not written by name
  until the configuration says how. WeeWX has no such question; it is the
  price of pointing a second station at a database with a history.
- **Late packets** are ordinary packets. An interval that is archived
  already is left alone by default, or built again with `late_packets =
  rebuild`, which WeeWX cannot do at all.

Native collector replay explicitly repairs historical and dependent intervals
even with the default late-packet policy. A durable cursor and partial daily
summary checkpoint keep that work bounded across ticks, with retention held
until pending native archive work completes. The full behavior is specified in
[native-ingest.md](native-ingest.md#durability-and-replay).

## Sending readings on

An upload moves readings, not files: an archive record reshaped into what
Weather Underground or Windy or an APRS gateway wants, and sent. There
are eight, and the shape of the code is weewx-evo's `uploads` package:
one interface, `post(records)` with the records oldest first, a `Kind`
that says what each service is like, and the protocols transcribed from
where they are defined. WeeWX's `restx.py` for the Ambient protocol, WOW,
the CWOP packet and the three rain sums a service is given; the
extensions of Matthew Wall, by way of weewx-evo, for Windy, Weathercloud,
InfluxDB and the MQTT topic layout.

**An upload is handed nothing.** It reads the archive from wherever it
last got to, and the number is in `state.sdb`. That is the same rule the
rest of the application follows, and it is what lets a tick do what
WeeWX does with a thread per service: a restart costs nothing, and a
connection that was down for twenty minutes comes back and sends the
twenty minutes. A service that takes a timestamp with the reading gets
what it missed, up to `catch_up` records and never further back than six
hours; a service that does not, CWOP, Weathercloud and a broker, gets the
newest reading only, and only while it is younger than `stale`.

**In the tick, after the archives, within the budget.** The uploads run in
the order of who waited longest, so a slow service does not push the same
others past the budget every tick, and an upload starts only when its
timeout fits into what is left. A service that has stopped answering
costs its own timeout and nothing of the archiver's.

**Said once.** A wrong password is answered by most of these services
with 200 and a word in the body, so the body is read. A permanent refusal
switches the upload off, writes one line, and is tried again an hour
later, WeeWX's `retry_login`; a passing failure is counted and the record
kept for the next tick.

**What differs from WeeWX**, and why: the units go out through the same
conversion table as everything else, from whatever the archive holds; a
reading the archive does not have is left out of the request rather than
sent as zero, because a `rainin=0.00` from a station with no gauge is
kept by the service forever; the CWOP tocall is `APZPHP`, since `APWEE5`
is WeeWX's own and `APZ` is the range for software without one; and the
rain sums use WeeWX's SQL to the character, midnight counted into the new
day as Weather Underground counts it.

MQTT is a client of its own, the publisher's subset of 3.1.1 over a
socket: CONNECT, PUBLISH at QoS 0 or 1 waiting for its own PUBACK,
DISCONNECT. No subscriptions and no pings, because the connection lives
one tick. The `live` trigger publishes the newest packet through the same
steps an interval's packets go through, run-up included; it is live to
the extent the tick is, which with cron means every five minutes and with
an ingest calling the tick means seconds.

## Two databases of its own

`state.sdb` remembers which databases this application created and when,
when each archive was last walked in full, what each run did, and how
each station was judged. `live.sdb` is the journal. Both are SQLite in WAL
mode, like the archives, and `journal_mode = delete` switches all of them
for a network file system.

SQLite is reached through PHP's `SQLite3` extension rather than PDO. PDO
binds a float as a string of fourteen digits, and a value that went
through it came back a bit away from what WeeWX wrote; `SQLite3` binds a
double as a double.

## What the conformance checks prove

`tests/conformance/run.py` runs in a container that has WeeWX 5.5.0 and
pyephem installed beside the PHP command line, and holds the PHP side
against WeeWX on the same inputs. Exactly, where the code is a
transcription; to a stated tolerance where WeeWX takes a road this
program cannot.

| check | what it holds against WeeWX |
|---|---|
| `units` | every conversion in `conversionDict` at nine values, the groups and units of every observation type, records converted into each system: exact |
| `accum` | the accumulator over twenty cases, two thousand values, `weewx.accum` itself: exact |
| `formulas` | the formulas of `wxformulas.py` and `uwxutils.py` over a grid, the console's rounding included: exact; the clear-sky radiation within 0.5 W/m² of pyephem's |
| `sun` | the sun's position at six places and five days, every hour, against pyephem: the geometric elevation within 0.05°, the refracted one too |
| `derive` | forty packets and three records through WeeWX's own `StdWXCalculate` machinery and through `Derived`, in US and METRICWX: exact |
| `archive` | a file made here opened by `DaySummaryManager` with no schema, read, written to, its summaries dropped and rebuilt by WeeWX with the same sums; and WeeWX's file appended to by us |
| `difftest` | every day of a real WeeWX database rebuilt here and compared with what WeeWX stored: sums identical, no extreme sharper |
| `roundtrip` | three days of that database deleted and written back here: the archive table byte for byte, the summaries' sums exact |
| `tick` | a day of packets from two senders through the command line's `tick`, then WeeWX reads the archive, rebuilds its summaries with the same sums, and `verify` agrees |
| `uploads` | the Ambient query, the WOW query and the CWOP packet against `weewx.restx` for the same records, parameter for parameter and character for character; the rain sums against `RESTThread.get_record`; and, with weewx-evo mounted, Windy, Weathercloud, InfluxDB and MQTT against its modules |

The differences the checks allow are the ones WeeWX itself shows: a
stored extreme may be sharper than the records give, because it came from
a LOOP packet the record averaged away, and a rebuild by WeeWX writes a
row for every column of a day, empty or not.
