# weewx-php

**WeeWX archives on a plain web host.** Stations post their readings into
a journal, an archiver turns them into WeeWX records and daily summaries,
and one `tick.php` keeps everything moving. No daemon, no Python, no
database server: PHP and SQLite, which every host has.

There is one rule, and everything else follows from it: **a WeeWX
database stays a WeeWX database.** Records are appended and columns are
added exactly the way WeeWX 5.5 does it, so WeeWX can open the file at any
time, read it, write to it, and rebuild its daily summaries from it, and
notice nothing. A test suite holds every part of that against WeeWX
itself; see [Checking it](#checking-it).

The application includes HTTP ingest for Ecowitt and Wunderground, the
journal, archiver, tick and outbound uploads. The fixed core admin at `/admin/`
manages stations, archives, field assignments, custom columns and registered
theme settings. English and German are included. See [Administration](docs/admin.md)
for account setup, workflows and extension points; the CLI uses the same services.

Themes can read values, aggregates, series and astronomy through
[`frontend.php`](frontend.php). Expensive calculations use a separate analytics
cache and the existing tick. See [PHP theme tags](docs/frontend.md) for the API,
refresh schedules, WeeWX/xaggs coverage and rainfall comparisons.
The [recipe guide](docs/frontend-recipes.md) covers output profiles, explicit
time references, theme ownership, prepared comparisons, live snapshots and diagnostics.
The [theme cookbook](docs/theme-cookbook.md) walks through PHP themes, runnable
Apache ECharts examples at `/cookbook.php`, opt-in public feeds at `/api/v1.php`,
HTML embeds and an installable WordPress sidebar plugin.

The [demo theme](themes/demo/README.md) at `public/index.php` shows current
readings, temperature and rainfall charts, and sunrise/sunset using those tags.

## What you need

- PHP 8.1 or newer with the `sqlite3` and `json` extensions. Nothing is
  installed with Composer at run time; the application is the files.
- Something that calls the tick: a cron job on the host, a call of
  `tick.php` from outside, or an ingest that calls it after delivering.
- A place for the data directory that the web does not serve. See below.

## Getting it running

Copy the repository to the host. Keep `data/` outside the web root if the
host lets you choose, or serve only `public/`; the directory gets an
`.htaccess` that refuses the web as a second line of defence.

Make the configuration from the example and change what applies: the
place, the sender ids, the token.

```bash
cp weewx-php.conf.example weewx-php.conf
```

Then let the application read it and say what it finds:

```bash
php bin/weewx-php check-config
```

Run the tick every five minutes:

```
*/5 * * * * php /path/to/weewx-php/bin/weewx-php tick
```

Or have something call `tick.php`, with the token from the configuration:

```
https://example.org/tick.php?token=change-me
```

It answers a small JSON: `ok`, or `busy` while another tick is running, or
`error` with the archives that failed. The details are in the log under
`data/log/`. And `php bin/weewx-php status` shows what every archive and
station is up to.

## Where the readings come from

USB/serial stations can be collected by the separate `weewx-php-ingest` project
on a Raspberry Pi and sent to the [native WeeWX JSON endpoint](docs/native-ingest.md).
The PHP receiver supports station admission, durable batch acknowledgements and
replay. The Python collector runtime is developed separately.

Ecowitt and Wunderground consoles can deliver directly to PHP. Enable
`[Ingest]`, run `php bin/weewx-php ingest endpoints`, then configure the
console with those connection details. A valid first upload discovers the
sender; `ingest list` and `ingest adopt <sender>` admit it to the journal.
Before adoption, only its latest bounded sample is kept in `ingest.sdb`.
See [HTTP ingest](docs/ingest.md) for hosting and console setup.

Stations do not talk to the archiver. They deliver packets into the live
journal, `data/live.sdb`, each under a sender id, and the archiver reads
the journal. That table is the whole contract between the two halves, so
an ingest can be written in anything that can open SQLite; the shape of
the table and what a packet has to carry are in
[docs/design.md](docs/design.md). Within this application,
`WeewxPhp\Live\LiveDb::add()` is the one call an ingest makes.

## Reading an existing WeeWX database

You can point an archive at a database WeeWX has been writing for years
and go on from there. WeeWX itself can go on writing it too; the two do
not need to agree on anything but the file.

1. Take a copy with SQLite's own backup, never with `cp`: a database in
   WAL mode keeps its latest records in a second file beside it.

   ```bash
   php bin/weewx-php backup kirchdorf /somewhere/safe/weewx.sdb
   ```

2. Name the file in `database`, and say which sender's readings go where.
   A database this application did not create is not written by name
   until you decide: either `auto_mapping = true`, which takes the
   columns of the same name, or a `[[[fields]]]` block that names each
   column. `mapping-suggest` prints one to start from, out of what the
   senders send and which columns are still free.

   ```bash
   php bin/weewx-php mapping-suggest kirchdorf
   ```

3. Run the tick. The journal's packets fill in from where the archive
   ends; `verify` afterwards compares the daily summaries with the
   records, the way `weectl database check` would.

The unit system of an existing database is kept, whatever the
configuration says; `unit_system` only decides a database made here.

## Several stations in one archive

An archive names one `primary` sender, whose readings go to the columns
of their names. Every other sender it reads writes only what
`[[[fields]]]` places, plus its battery and signal readings, which carry
their sensor in the name. That is what keeps a second console from writing
its own `outTemp` over the first one's.

```
        primary = ecowitt_kirchdorf
        senders = ecowitt_kirchdorf, dwd_freising
        [[[fields]]]
            [[[[dwd_freising]]]]
                outTemp = extraTemp1
                outHumidity = extraHumid1
```

A sender whose room readings do not belong in a series gets
`indoor = false` under `[[[members]]]`; a reading nobody wants is placed
nowhere with `inTemp = -`.

## Sending readings on

The readings can go to Weather Underground, PWSweather, the Met Office's
WOW, Windy, Weathercloud, CWOP, an MQTT broker and InfluxDB. Each is a
section under `[Uploads]` with the account details the service gave you:

```
[Uploads]
    [[wu]]
        kind = wunderground
        station = IBAYERN123
        password = "station-key"
```

Try it before trusting it. Most of these services answer a wrong password
with a cheerful 200 and a word in the body, so an upload being refused
looks exactly like one that works until somebody asks:

```bash
php bin/weewx-php upload check
```

From then on every tick sends what the archive holds since the last time.
A connection that was down for twenty minutes comes back and sends the
twenty minutes, not the current reading and a hole; a service that takes
no timestamp gets the newest reading only, and only while it is fresh. A
service that refuses the credentials is switched off, said once in the
log, and tried again an hour later. `upload list` shows where each one
stands.

The rhythm is the tick's. With cron every five minutes, a record reaches a
service within five minutes of its interval closing, and the broker gets
the newest packet every five minutes as well; that is the price of a web
host without a process of its own. An ingest that calls the tick after
delivering makes the broker live.

The example configuration shows every kind; [docs/configuration.md](docs/configuration.md)
has every key.

## The commands

`php bin/weewx-php help` lists them; [docs/commands.md](docs/commands.md)
describes each.

| | |
|---|---|
| `tick` | what cron calls |
| `status` | every archive and station at a glance |
| `check-config` | read the configuration and report what is wrong with it |
| `mapping-suggest` | a `[[[fields]]]` block to start from |
| `catchup` | build everything the journal covers, whatever the time budget |
| `rebuild` | work a span out again after a correction |
| `columns` | the archive's columns against `[[[columns]]]` |
| `backup` | copy a database the safe way |
| `verify` | the daily summaries against the records |
| `upload` | list the uploads, ask the services, or send now |
| `ingest` | connection details, discovered senders and adoption |

## Checking it

Every test runs in Docker, with the repository read-only and nothing
written outside `/tmp`:

```bash
tests/run.sh
```

That is three things. `lint` is php-cs-fixer and PHPStan at its strictest
level. `unit` is PHPUnit. `conformance` is a Python harness in a container
that has WeeWX 5.5 installed: it runs the PHP side and WeeWX on the same
inputs and compares, exactly where the code is a transcription and to a
stated tolerance where it cannot be. Which checks there are and what each
one proves is in [docs/design.md](docs/design.md). With a checkout of
weewx-evo beside this repository, the uploads check compares the services
WeeWX does not have with its modules as well.

The separate Python collector can also be tested against PHP in two Docker
containers with real Simulator workers, TLS and outage recovery. See
[the Docker integration run](docs/reviews/collector-docker.md) for results
and repeatable commands.

## Documentation

- [docs/configuration.md](docs/configuration.md): every option, with its default.
- [docs/commands.md](docs/commands.md): every command, with its arguments and exit status.
- [docs/design.md](docs/design.md): how it is built, where it departs from WeeWX and why, and what the conformance checks prove.
- [docs/native-ingest.md](docs/native-ingest.md): native collector setup, JSON v1 contract, acknowledgements and replay.
- [docs/admin-design.md](docs/admin-design.md): the admin interface architecture and implementation plan.

## Licence

GPL-3.0-or-later, like WeeWX. See [LICENSE](LICENSE).
