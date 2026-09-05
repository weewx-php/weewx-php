# Command reference

```
php bin/weewx-php [--config <file>] <command> [arguments]
```

The configuration is read from `weewx-php.conf` beside the application,
or from the file `--config` or the environment variable `WEEWX_PHP_CONF`
names. Exit status 0 when the command did what it says, 1 when something
in the installation stopped it, 2 when the command line was wrong.

## ingest

Local administration of HTTP push reception; no admin UI is required.

| Command | Effect |
|---|---|
| `ingest endpoints` | Show Ecowitt/WU credentials and the native WeeWX endpoint |
| `ingest list` | List discovered senders, state and reception counters |
| `ingest status [<sender>]` | Reception outcomes, last error, send interval and device-time diagnostics; without a sender, also global rejection counts |
| `ingest sample <sender>` | Show the latest credential-redacted sample |
| `ingest adopt <sender> [<name>]` | Admit subsequent uploads to `live.sdb` |
| `ingest ignore <sender>` | Acknowledge uploads without retaining their measurements |
| `ingest block <sender>` | Block measurement storage for this identity |
| `ingest reset <sender>` | Return an existing sender to pending |
| `ingest rotate wunderground` | Replace only the currently free WU password |
| `ingest rotate <wu-sender>` | Replace an assigned WU password, preserving the sender and its state |
| `ingest rotate ecowitt` | Replace the common path; all Ecowitt consoles need the new path |
| `ingest prune` | Run due ingest retention cleanup; also performed by the regular tick |

Senders appear on their first valid upload. There is no create command.
Assigned WU passwords remain valid; consuming the free password generates
the next one atomically. Previously stored journal packets survive state
changes. See [HTTP ingest](ingest.md) for setup and limits.

Rotation immediately invalidates the replaced credential. An assigned WU
replacement is displayed once and is stored only as a digest. Rotation
does not adopt a sender, lift a block, or remove recorded data. Detailed
diagnostic counters start on the first request after this feature is
installed; `since` identifies their start.

## tick

Builds every interval that is due for every archive within the time
budget, walks the journal in full when that has not been done for an
hour, judges the stations' silence, and every ten minutes lets packets
older than `live_retention` go. Prints what `tick.php` would answer.
Exit status 1 when an archive failed; the others are still done.

`tick.php` in `public/` does the same for a call from outside, with
`?token=` or the header `X-Tick-Token` matching `tick_token`, and answers
200 for `ok`, 503 for `busy` and 500 for `error`.

## status

For every archive: the database, how many records it holds and from when
to when, the last run, when the journal was last walked in full, how many
intervals are waiting, and the last error. For every station: whether it
is `ok`, `stale`, `down` or `unknown`, and when it was last heard.

## check-config

Reads the configuration and reports errors, warnings, and for every
archive whose database exists, whether the mapping may write it. Exit
status 1 when it may not, or the file does not hold together.

## mapping-suggest \<archive\>

Prints a `[[[fields]]]` block to start from: for the primary, which of its
readings have a column of their name; for every other selected sender, a
spare `extraTemp` or `extraHumid` column per temperature or humidity, and
`-` for a reading with no spare column of its kind. Worked out of the last
packet each sender sent and the columns that hold nothing yet.

## catchup [\<archive\>]

Builds every interval the journal covers that is not archived, for one
archive or all of them, without a time budget. What is archived already is
left alone. The tick does the same within its budget; this is for after a
long silence, or after `live_retention` was raised.

## rebuild \<archive\> \<from\> \<to\>

Works every interval between the two moments out again from the journal
and replaces the records, then the daily summaries of every day touched.
Moments are timestamps or local times in the archive's zone, such as
`"2026-09-05 14:00"`. For after a correction: a packet fixed in the
journal, a calibration or a limit changed, a reading placed elsewhere.

## columns [--add] \<archive\>

Lists the database's columns with how many records each holds, and what
`[[[columns]]]` would add. With `--add`, adds them now rather than at the
next tick.

## backup \<archive\> \<target file\>

Copies the database with SQLite's online backup, which reads through the
write-ahead log; a `cp` of a database in WAL mode does not. Refuses a
target that exists.

## verify \<archive\>

Runs SQLite's integrity check, then compares every day's stored summaries
with the ones its records give. A sum that differs is a problem. An
extreme that is sharper than the records is right, it came from a packet
the record averaged away; one that is duller is a problem. Exit status 1
when there are problems.

## upload list

Every configured upload: the service, the archive, the rhythm, how far it
has got, its last run, how many runs and readings, failures in a row, and
whether it is switched off and why.

## upload check [\<name\>]

Asks every service, or one, whether it takes the credentials, without
posting a reading: an empty update for the Ambient services, a login for
CWOP, a connection and a status message for a broker, an empty write for
InfluxDB. An upload that was switched off and now answers is switched on
again. Exit status 1 when a service refuses.

## upload run [\<name\>] [--since \<time\>] [--again]

Sends now, whatever the trigger, the budget and a block say: every upload,
or one. `--since` sends everything after a moment again, for a service
that lost its copy or a span that was rebuilt; a timestamp or a local
time such as `"2026-09-05 14:00"`. `--again` forgets how far the upload
got, so it starts from the newest record. Exit status 1 when an upload
failed.

## collector

`collector add <name>` provisions a collector UUID and displays its token once.
`collector list` lists registered collectors; `collector stations <collector>`
lists discovered stations with sender IDs, admission state and delivery counters.

`collector adopt <collector> <station> [<name>]` permits delivery and supplies
station metadata to archive configuration. `collector block <collector> <station>`
stops new observations without deleting history. `collector rotate <collector>`
replaces its token; `collector disable|enable <collector>` changes access while
preserving identity. See [native ingest](native-ingest.md) for the full contract.
