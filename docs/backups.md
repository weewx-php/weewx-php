# Backup and restore

The first tick of each local day creates a full installation TAR in
`data_dir/backups`. Defaults:

```ini
backup_enabled = true
backup_retention_days = 3
```

Both options are editable under **Settings**. **Backups** offers **Back up now**
(queued for the next tick), creation time, size, status and **Download**. Downloads
require the authenticated HTTPS admin session and are streamed with `no-store`.
The package includes credentials; keep the data directory outside the web root.
Keep a downloaded copy on another device if recovery must survive host loss.

## Contents and consistency

Each package contains the complete configuration, all configured archive
databases (including disabled archives), `live.sdb`, `state.sdb`, and `ingest.sdb`
when present. The manifest records package format, application version, creation
time, archive-to-file mapping, byte sizes and SHA-256 checksums. This includes
collector identities and receipts, sender admission, configuration history,
upload cursors and admin credentials. Analytics caches, logs, application code,
custom theme files and prior backups are excluded. Keep the matching application
version and custom theme code separately.

Creation holds the application writer lock and takes SQLite write reservations
on ingest, live, state and archive databases. Online backups read committed
contents through WAL using separate read-only connections. All reservations
are acquired before any database is copied, so application ingests and external
SQLite writers cannot change the participating databases during the snapshots.
Do not replace database files or hand-edit configuration during a backup;
admin configuration changes use the application lock. External configuration
changes detected during copying fail the backup.

Temporary snapshots are private; each passes `PRAGMA quick_check` before TAR
publication by rename. A failed or interrupted package is never listed for
download. The next attempt removes abandoned staging files. Failed work retries
after ten minutes. Only successful creation triggers retention. Retention uses
elapsed 24-hour days; scheduling uses local calendar dates, including DST changes.
Repeated manual requests coalesce. A manual successful backup also satisfies that
day's automatic backup.

## Runtime and space

PHP 8.1+, SQLite3 and Phar are required. TAR creation works with
`phar.readonly=1`; executable PHAR creation is not used. Allow space for the
temporary SQLite snapshots and TAR in addition to retained packages; the initial
free-space check conservatively includes WAL sizes. Disk-full or I/O failures
keep older packages and report a failed backup.

PHP's SQLite backup API copies a database in one call. Full backups run after
archive and maintenance work and are not skipped when that work exhausts the
archive budget. `time_budget` cannot interrupt an individual SQLite backup.
For large archives use a CLI cron tick with sufficient memory, execution time
and disk space, or run the standalone backup command from CLI cron. On a host
with an insufficient fixed HTTP execution limit, disable automatic backups in
HTTP ticks and schedule the standalone CLI backup daily. Ingest requests may
wait for the SQLite reservations and can receive a retryable storage error if
their busy timeout is exceeded; native collectors retain unacknowledged batches.

The overview and `status` report overdue ticks (15 minutes or three archive
intervals), overdue enabled backups (36 hours), backup failure and insufficient
estimated backup space. A failed full backup or maintenance job makes that tick
return `error`. An external monitor must poll status to notice a stopped tick.

## CLI

```sh
php bin/weewx-php --config /private/weewx-php.conf backup
php bin/weewx-php backup garden /private/garden-copy.sdb
php bin/weewx-php restore /private/backup-1788652800-0123456789abcdef.tar /private/recovered
```

`backup` without arguments creates a full package immediately, including when
automatic backups are disabled. The existing two-argument archive copy remains
available. A full backup returns a nonzero exit status on failure.

`restore` requires an existing parent and a **new** target directory. It does not
need a working installation configuration and never overwrites the running
installation. It accepts only manifest-listed regular files with expected names,
sizes and hashes; checks every database; relocates archive paths and historical
configuration paths; and publishes the directory only after validation. Files
with path traversal, links, unexpected contents, missing components, corrupted
data or unsupported formats are refused. Active sessions, login throttles,
maintenance jobs and backup scheduling state are reset; passwords, collector
credentials and processing/upload cursors remain. No ingestion or uploads run
during restore. Checksums detect corruption; they are not signatures, so restore
only packages from trusted storage.

The returned path is the recovered `weewx-php.conf`. To activate recovery, stop
the existing cron and ingress, point the application (`WEEWX_PHP_CONF`) and cron
at that configuration, run `check-config`, then resume ingress and the tick.
Do not run old and recovered installations against the same senders/upload
destinations simultaneously. The recovery point is the snapshot timestamp;
measurements accepted only after it require replay from the collector or another
copy and are not recovered by the backup itself.
