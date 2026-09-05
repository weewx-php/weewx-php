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
- **Fields:** choose one archive. Its stations appear together, in separate
  sections. Each row connects one source field to a compatible archive column.
  Unassigned fields remain unassigned when new uploads arrive.

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

The Fields view shows the archive record count, oldest and newest timestamps and
their age. Every selected destination shows its own non-NULL value count and
first/last value timestamps. Zero is a stored value. Empty columns are identified
explicitly. Choosing a different destination loads its history without saving.

Counts come from the actual archive, using a single read-only aggregate for the
displayed destinations. They are not inferred from journal counters or daily
summary counts. Large archives therefore require an archive scan for this view.

**Mapping history** shows saved source assignments and their effective periods.
It is configuration history, not proof of the producer of each individual row.
Imported records and the initial baseline have **Source unknown** unless later
recorded assignments provide evidence. Changing today's mapping never attributes
undocumented old records to today's station. The weather database contains no
new administration or provenance tables.

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
`schema_version` are reserved. Archive-field values use `archive_id:column`.

Load typed settings in a theme through the shared adapter:

```php
$theme = \WeewxPhp\Frontend\Theme::configured($configPath, 'example');
$showWind = $theme->extras['show_wind']; // bool
$days = $theme->extras['days'];         // int
```

Omit the theme ID to use `[Themes] active`. The adapter loads settings and render
context; the public entry point remains responsible for choosing its renderer.
The demo theme registers and uses its default chart range as an example.

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
