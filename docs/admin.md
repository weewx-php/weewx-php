# Administration

The core interface is served at `/admin/`. It uses PHP forms, SQLite and a small
JavaScript enhancement. It has no frontend build step. Themes cannot replace
the admin navigation, templates, authentication or controls.

## Setup

Serve `public/` over HTTPS. Keep the configuration and data directory outside
the document root, accessible only to the application account. The web process
and CLI must be able to write the configuration and private data directory.
Set `WEEWX_PHP_CONF` when the configuration is not `weewx-php.conf` in the
repository root. Behind a reverse proxy, configure the web server's HTTPS
indicator; the admin does not trust arbitrary forwarded headers.

Provision the single administrator account from the CLI. Supply a password of
12–72 bytes on standard input, for example with a hidden Bash prompt:

```bash
read -r -s -p 'Admin password: ' admin_password
printf '%s\n' "$admin_password" | php bin/weewx-php admin password-stdin
unset admin_password
```

The same command resets the password and invalidates existing sessions.
There is no default password or public setup endpoint. Sessions expire after
two hours. Login attempts are rate limited; all mutations require a session and
CSRF token. Connection credentials are revealed only by an authenticated action.

For local development only, `WEEWX_PHP_ADMIN_HTTP=1` permits HTTP when the direct
client address is `127.0.0.1` or `::1`. It does not enable public HTTP access.

## Archives and stations

- **Stations:** inspect incoming fields, adopt, reject, restore and rename
  stations. Adoption allows subsequent readings into the journal; it does not
  assign fields to an archive.
- **Archives:** create a WeeWX database, connect an existing database, select
  stations and configure location, timezone and interval. Databases managed by
  the admin must reside beneath the private data directory. A connected archive
  starts disabled, with no station assignments.
  Connecting detects storage units from the existing `archive.usUnits` values;
  empty archives and archives with mixed or unknown units are rejected. The
  storage-unit selector applies only when creating a new archive.
- **Fields:** choose one archive. Its stations appear together, in separate
  sections. Each row connects one source field to a compatible archive column.
  Unassigned fields remain unassigned when new uploads arrive.

### Import an existing archive

**Upload file** transfers a SQLite archive in 1 MiB blocks, up to 64 GiB. Pause
and resume are supported; after reloading the page, select the same file to
resume its acknowledged offset. **Discard import** removes its temporary data.
PHP's multipart upload limit does not constrain the whole file, but the host
must accept each block and provide sufficient disk space. Host/FPM request
timeouts still apply to inspection and copying a webspace database.

**Search webspace** finds unconnected WeeWX SQLite files, excluding linked
archives, import staging and this installation's backup directory. The default
scope is the document-root parent when it contains the configuration directory;
otherwise it is the configuration directory. `WEEWX_PHP_WEBSPACE` can specify
the search root explicitly. Resolved symlinks must remain inside that root.
Search advances in batches and reports truncated results at its safety limits
(100 candidates, 20,000 directories or 3 MiB cursor state).

Selected webspace files are copied into private import storage using SQLite's
online backup API, including committed WAL data. Their existing paths remain
untouched and are excluded from later searches after connection. Browser uploads
must be consistent standalone SQLite files, for example a WeeWX/SQLite snapshot.

Archive IDs are generated automatically. Storage units are read from
`archive.usUnits`; existing data is not relabelled. New archives default to US;
incoming measurements are converted into the selected storage units.

Choose the archive timezone or use **Detect automatically**. Detection tests
all IANA zones against up to two completed summary days, grouping zones with
identical day boundaries to avoid repeating calculations. Fractional offsets
and DST transitions are supported. Equivalent zones remain selectable: the
database cannot prove an IANA name, and a summer-only archive cannot distinguish
zones by winter behavior. With no usable matching summaries, select manually.

Connecting checks sums, counts, weights and day boundaries. A mismatch or missing
summary data triggers a resumable daily-summary rebuild before connection.
Raw archive rows remain unchanged. Known summary extrema are reassigned using
their timestamps; other high-resolution extrema absent from archive records
cannot be recovered. **No original-file backup is created on the webspace.**
Only old summary tables are retained temporarily during rebuilding, then removed.
The staged database becomes the archive without another full copy. The archive
starts disabled with no station assignments.

On the 7.2 MiB, 17,963-record reference archive, the two-day check took 114 ms and
zone detection 116 ms locally. Sample cost depends on the selected days' records
and columns; unit validation can scan the entire archive. A complete rebuild
scales with archive history and runs in resumable batches of up to ten days.

Choose **New column…** directly in a source's destination selector. Known fields
provide their measurement type. For an unknown field, also supply its type and
source unit, such as `degree_C`, `degree_F`, `mm`, `volt` or `percent`. Save creates
the columns, defines additional sources and applies all station assignments in
one validated change. Failure leaves the submitted configuration unapplied.

New scalar columns support `REAL` and `INTEGER` storage, with average, sum,
minimum, maximum, first or last aggregation. Counter and state measurements use
last. New vector directions require a separate vector model; use the existing
wind fields for wind vectors. A cumulative rain source can be assigned to `rain`
to store its increments. Rolling rainfall windows are not cumulative counters.

A destination has one configured source. Selecting a populated destination shows
a warning with its count, time range and recorded source assignments. Saving
requires **Continue this column’s history with this source** for each changed
assignment. Confirmation applies only to that station, source and destination.
Unchanged assignments and empty columns need no confirmation. Mapping
changes start at the next complete archive interval. Existing records are retained;
rebuilding them is a separate maintenance action. Storage units are fixed for
populated archives; interval and timezone changes are rejected when records exist.

Existing configurations retain their primary/automatic mapping until explicitly
converted. **Use explicit mapping** materializes the currently observed effective
assignments. The legacy primary is resolved in journal reception order.

## Database and column history

Archive settings group general options, location, archive structure and
stations. Forecast settings belong to the installed extension's Admin menu.
Elevation uses separate numeric and m/ft controls; intervals are
entered in minutes. Existing archives show fixed timezone, interval and storage
units as read-only values. The server still validates structural changes.

The explicit place search uses Open-Meteo's GeoNames geocoding endpoint. Selecting
a result fills the place name and coordinates. It fills elevation only when that
field is empty; a configured station elevation is retained. Place elevation may
differ from the console's pressure-sensor elevation, so review before saving.
An empty archive may also receive the result's timezone; an existing archive's
timezone is never silently replaced. Queries require admin authentication and
CSRF, are cached for 24 hours and rate limited; no search happens while typing.

Station connection details show reception status, protocol, transport, hostname,
port and path separately. They use the configured `Ingest.public_url`, preserving
custom ports and installation subdirectories. HTTP without an explicit port means
80; HTTPS means 443. Configure HTTP for consoles without TLS support. Point the
console at the supplied Ecowitt path, not the website's root; enable `[Ingest]`
reception before expecting stations to appear.

### Ticks on page visits

`visit_tick_enabled` (default true) is available in Settings. The bundled
theme requests `/visit.php` after loading and once per minute while visible.
The endpoint only queues background work and returns 204; no station packet
is required. A shared nonblocking file lock throttles all visitors to one
wake-up per minute. Existing archive records do not become new measurements.
Cron remains necessary for a guaranteed schedule when no visitors or packets
arrive. Custom themes can include `assets/visit.js`.

In field assignment, **Rain calculation** identifies counters used as
calculation inputs without their own archive column. Assigning a gauge's
`rain` or cumulative rain observation selects its counter family. Explicit
exclusions are respected. Rolling hourly/24-hour totals and rates cannot be
mapped as interval rainfall. Custom sensors keep their explicitly declared
measurement semantics and do not borrow counters from another gauge.

**Mapping history** shows saved source assignments and their effective periods.
It is configuration history, not proof of the producer of each individual row.
Imported records and the initial baseline have **Source unknown** unless later
recorded assignments provide evidence. Changing today's mapping never attributes
undocumented old records to today's station. Rain gap evidence is stored
separately from the standard archive columns.

## Languages

English is the default. German is included. Select the admin language under
Settings; `?lang=de` also selects a locale for the current request. Translation
packs live in `resources/admin/locales/<language>.json` and provide a display
name, messages, plural rule, number separators, date format and `ltr`/`rtl`
direction. Valid locale filenames appear in the language selector automatically.
Missing messages fall back to English. Theme translations use separate files.

## Theme settings

A theme registers `themes/<id>/settings.json`, with schema version 1 and a list
of fields. Registration executes no PHP. The core validates and renders the
declaration. Supported types are `text`, `boolean`, `integer`, `number`, `select`,
`color`, `archive` and `archive_field`; numbers can declare `min` and `max`.

```json
{
  "theme": "example",
  "schema_version": 1,
  "fields": [
    {"key": "show_wind", "type": "boolean", "label": "settings.wind", "default": true},
    {"key": "days", "type": "integer", "label": "settings.days", "default": 7, "min": 1, "max": 30}
  ]
}
```

Theme labels are resolved in `themes/<id>/locales/<language>.json`, with English
fallback. Settings are stored under `[Themes] / [[<id>]]` in the configuration.
The field names `action`, `csrf`, `revision`, `theme`, `activate` and
`schema_version` and `directory` are reserved. Archive-field values use `archive_id:column`.

Load typed settings in a theme through the shared adapter:

```php
$theme = \WeewxPhp\Frontend\Theme::configured($configPath, 'example');
$showWind = $theme->extras['show_wind']; // bool
$days = $theme->extras['days'];         // int
```

Omit the theme ID to use `[Themes] active` (default: `basic`). The public entry
point loads that theme’s renderer and JSON snapshot. External package directories
can be set under `[Themes] / [[<id>]] / directory`, relative to the configuration.
See [installation and package contract](themes.md).

## Operations and recovery

Archive settings, columns and mappings use the same application service from
HTTP and CLI. Each submission carries the configuration revision it was based
on. A stale form is rejected instead of overwriting another change. Tick,
catch-up and rebuild reload configuration after taking the writer lock.

Schema work is staged in a private operation journal. Adding an already-created
column can be resumed after interruption. New databases use an atomic hard-link
installation that cannot replace an existing file; the data filesystem must
support hard links. If recovery finds an independently changed configuration or
cannot establish ownership of an installed file, it stops with a conflict.
In particular, older Windows PHP builds may not expose hard-link file identity
after interruption between installation and its operation checkpoint. Preserve
both files and resolve ownership before clearing a pending operation.

Configuration snapshots retain processing rules for rebuilding old intervals.
The initial snapshot cannot reconstruct configuration predating installation.
Passwords, upload credentials and theme settings are excluded from these
processing snapshots. The private pending-operation record temporarily holds the
desired full configuration and is removed after successful installation.

Archives expose backup, daily-summary verification and journal rebuild jobs.
The central **Backups** page manages full daily installation packages and
authenticated downloads; **Settings** controls daily scheduling and retention
(default three days). See [backup and restore](backups.md).
The existing tick processes queued work. Verification and rebuild advance by
day; the PHP SQLite backup API performs one complete copy. Backup paths are
shown in the archive's maintenance history. Rebuild ranges must be in the past
and within journal retention.

CLI commands for the same service:

```bash
php bin/weewx-php admin revision
php bin/weewx-php admin apply mapping.save_all draft.json
```

`draft.json` carries `revision`, `archive`, nested
`mapping[station][observation]` assignments and optional
`columns[station][observation]` definitions. New destinations use `__new__`.
Undeclared native source keys use `native:<field>` and require a measurement kind
and source unit in their column definition. See the integration tests in
`tests/Unit/Admin/AdminTest.php` for complete command examples.

## Extensions

**Extensions** lists packages from the organization’s
[reviewed catalog](https://github.com/weewx-php/extension-catalog). Install a
package, then activate it. Updates retain its options and activation state.
Deactivation removes its tags and worker from subsequent requests; removal
also removes its configuration. Package data and old code versions are retained.

All actions require the existing authenticated session and CSRF token. Downloads
use fixed GitHub commit URLs and per-file SHA-256 verification. PHP code is not
loaded during installation or activation; it joins the normal tick and tag
registry on subsequent application requests. See [extensions](extensions.md).
