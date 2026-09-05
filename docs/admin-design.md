# Admin interface design

Status: architecture and design reference. The core implementation and its
current boundaries are documented in [Administration](admin.md).

The admin interface belongs to the core. Its navigation, components and workflows
are fixed. English is the default language; every interface string and format
supports localization. Themes register typed settings that the core renders.

The first release covers archive creation and configuration, station adoption
and rejection, field mapping, and creation of archive columns for received fields.
It retains the PHP 8.1 and SQLite deployment model, without a daemon or a required
frontend build step on the host.

## 1. Decisions

- A station supplies source fields. An archive owns columns. A mapping connects
  a station field to a column in one archive.
- A station can supply several archives with independent mappings.
- Adoption permits subsequent uploads into the journal. Archive membership and
  mapping are separate decisions.
- New archives use explicit station membership and explicit accepted mappings.
  Suggestions never become active merely because a station starts sending.
- Every effective mapping is visible, including inherited behavior in existing
  configurations and calculated observations.
- A column has one configured station source at a time. Calculations have an
  explicit policy. Combining stations requires a future, explicit source policy.
- Mapping changes take effect at an archive interval boundary. Existing records
  remain unchanged unless an operator requests a separate rebuild.
- Application metadata remains outside WeeWX archive databases.
- UI and CLI call the same application services and enforce the same rules.
- Interface copy consists of labels, values, actions and actionable errors.
  Explanations appear only when needed to make a consequential choice.

## 2. Existing core and required changes

| Area | Existing implementation | Required work |
|---|---|---|
| Station admission | `Ingest/Store.php`: pending, adopted, ignored, blocked | Shared application actions and admin views; preserve current admission semantics |
| Incoming fields | `Ingest/Parser.php` normalizes known fields; unknown fields survive only in bounded raw samples | Persistent field inventory and explicit definitions for additional measurements |
| Archive settings | `Config/ArchiveConfig.php`, `Config/Settings.php` | Explicit mapping mode, enabled state, per-archive interval with global default |
| Mapping | `Archive/Mapping.php`: primary by name, secondary by placement, housekeeping exceptions | Effective mapping resolver, compatibility and collision validation, revision history |
| Schema | `Weewx/Schema.php`, `Archive/ArchiveDb.php` | Read-only inspection and recoverable create/add operations |
| Units and aggregation | `Weewx/Units.php`, `Weewx/Policy.php` | Custom observation definitions shared by ingest, archive and frontend |
| Configuration | `Config/ConfFile.php` preserves comments and replaces files atomically | Revision checks, operation recovery and coordination with all writers |
| Processing | `Tick/Tick.php`, `Archive/Archiver.php`, `Live/LiveDb.php` | Consistent configuration snapshot under lock, revision-aware processing and archive-specific scheduling |
| Themes | `Frontend/Theme.php` provides rendering context and text lookup | Separate settings registry and admin translation service |

Read-only admin requests must not call `Archiver::open()`: it currently creates
databases and adds configured columns. Opening a page must not apply changes.

The limited review of weewx-evo informed the field table: source, latest value,
target, schema presence, history and competing writers belong together. Its
page structure and implementation are not dependencies of this design.

## 3. Domain model

| Object | Identity and responsibility |
|---|---|
| Station | Persistent sender ID; protocol identity, display name, admission state and reception status |
| Source field | Station ID plus stable field key; native name, normalized observation name when known, measurement definition and last observation |
| Measurement definition | Measurement kind, unit group, source-unit rule, numeric type and semantics such as instantaneous value or cumulative counter |
| Archive | Stable ID, database, enabled state, location, timezone, storage unit system and interval |
| Archive column | Archive ID plus technical column name; actual SQL type, observation definition and summary policy |
| Mapping revision | Immutable processing configuration for an archive, with activation boundary and configuration revision |
| Field assignment | Station field to target column, or explicit ignore, within a mapping revision |
| Theme settings definition | Theme ID, schema version, groups and typed settings |

Display names are editable and never act as identities. Technical source keys,
column names and stored enum values do not change with the interface language.

### Field discovery and normalization

Maintain a bounded inventory across uploads, rather than using only the latest
packet as the field list. Intermittent fields remain visible with their last-seen
time; missing readings are not zero. Keep received values distinct from values
accepted as valid measurements.

Preserve native field identity separately from its normalized WeeWX name.
Known protocol definitions remain authoritative for their units. An unfamiliar
numeric field is a candidate, not automatically a temperature, counter or
archive column. Credentials and transport metadata are excluded before inventory
storage or display. Existing ingest size and rate limits remain effective; add
limits for field count, name length and sample retention.

`Define field` assigns a supported measurement kind and source unit to an unknown
candidate. Future accepted uploads then retain it in the journal as a typed
measurement. Discovery samples are not replayed, and expired raw data cannot be
recovered. A definition change must not reinterpret previously stored packets:
retain its version and unit semantics with the observation data.

Version any journal extension explicitly and preserve reads of existing packets
and documented external producer behavior. Unknown candidates can remain in
bounded discovery storage until defined; the archive pipeline consumes validated
measurements only. Conflicting native fields must not collapse silently into one
normalized key.

### Mapping and calculations

The resolver produces the full effective assignment set for one archive and
revision. Both processing and the UI consume that result. Validate:

- Source station exists and is selected by the archive.
- Target exists, or is created in the same change operation.
- Measurement kind, unit conversion and SQL representation are compatible.
  A shared unit group alone does not make station pressure and sea-level pressure
  interchangeable.
- No duplicate target among effective station assignments, including automatic
  mappings and battery or signal fields.
- Reserved columns `dateTime`, `usUnits` and `interval` are never assignable.
- Wind components and derived observations satisfy their required relationships.

Show calculation policy alongside the target: `Hardware`, `Calculated`, or
`Prefer hardware`. An explicit measured source with the existing calculation
fallback is one declared policy, not an undetected competing writer. Keep the
current WeeWX calculation behavior where the operator has not changed it.

Initially, one source field maps to at most one target in each archive. Mapping
that field to a different target in another archive is supported. Automatic
failover, averaging across stations and arbitrary expressions are deferred.

### History and activation

Let T be the next archive boundary after a change is applied. Intervals ending
at or before T use the previous revision; the first new interval is (T, T + I],
where I is that archive's interval. Show the activation time on the saved change.
Late packets and catch-up select revisions by interval time, not receipt time.

Snapshot mapping, membership, custom field definitions, site coordinates and
altitude, calibration, quality limits and calculation/aggregation policies needed
to interpret a revision.
Preserve source identity when seeding rain counters and other derived state;
a sensor replacement must not create a counter delta between unrelated sensors.

Ordinary rebuilds use the recorded timeline. A correction using another revision
requires an explicit range and preview, and is limited by retained input data.
Archive history predating revision tracking has unknown provenance. Timestamps
and non-null counts prove that values exist, not which station supplied them.

## 4. Fixed interface

| Navigation | Pages and actions |
|---|---|
| `Overview` | Reception, last archived record, backlog, actionable failures, pending station count |
| `Stations` | `Pending`, `Adopted`, `Rejected`; station details, readings, connection details |
| `Archives` | Archive list; `General`, `Stations`, `Fields`, `Columns`, `Maintenance` |
| `Fields` | Shared mapping editor with required archive selection and optional station filter |
| `Themes` | Active theme and registered settings |
| `Settings` | General settings, uploads, admin access, language and system status |

The `Fields` tabs within station/archive details open the same editor with
filters. There is one implementation of mapping behavior. URLs have stable
English paths; translations affect labels only.

### Station workflow

1. A valid upload creates a pending station with a redacted sample.
2. `Adopt` accepts subsequent uploads. `Reject` stops sample/journal storage while
   preserving identity and the normal protocol acknowledgement.
3. `Assign to archive` selects an archive and opens mapping suggestions.
4. Saving accepted assignments activates them at the stated boundary.

`Reject` maps to the existing `ignored` state. Existing `blocked` entries remain
distinguishable in details; opening or saving a page must not convert them.
`Restore` returns a rejected station to pending; `Adopt` can admit it directly.
Stopping an adopted station retains recorded data and mapping history. Admission
and reception health are separate: an adopted station may be offline.

### Archive workflow

`Create archive` takes a name, managed database filename, unit system, interval,
timezone and optional location. It creates a standard WeeWX database without
overwriting a file. Create it disabled until membership and mappings are ready.

`Connect existing archive` inspects a local database read-only before offering
activation. Read the actual schema, stored unit system, time range and available
summaries. An empty database with no recorded unit system requires an explicit
choice. Resolve duplicate database paths, including aliases, so two configured
archives cannot accidentally write the same file.

New archive intervals default to the existing global interval. In the first
release, interval and timezone are selected before writing; changes after data
exists require a dedicated maintenance migration. The stored unit system is
read-only once established. Editing name, location or other ordinary settings
does not rewrite records. Changes affecting calculations use a new revision.

Maintenance offers schema inspection, verification, backup and explicitly queued
rebuilds. Long operations run in bounded steps through the existing tick/CLI
model and expose progress and errors. Disabling an archive pauses its writes
without deleting its database, mappings or history.

### Field editor

The main table contains `Station`, `Source field`, `Latest value`, `Archive
column`, and `Status`. Value timestamps and units are available without opening
raw payloads. Filters include measurement kind, station, `Unmapped`, and
`Conflicts`. Use pagination or bounded queries for large inventories.

Each assignment offers compatible target columns, `Ignore`, and `Create column`.
`Suggest mappings` produces a draft. `Save` validates the complete assignment
set, including rows outside the current filter; `Cancel` discards the draft.
Keep edits on validation failure and mark the affected controls.

Column details show SQL type, unit, aggregation, current configured source,
recorded source changes and existing data. Expensive history counts are fetched
on demand or cached with a freshness timestamp, not scanned on every refresh.
Reusing a populated column for a different or unknown historical source requires
an explicit choice showing that history will continue in the same column.

### Column creation

`Create column` opens within the mapping draft and collects:

| Field | Rule |
|---|---|
| `Column name` | Stable SQL identifier; reject reserved names and case-insensitive collisions |
| `Label` | Editable display text, optionally localized |
| `Measurement type` | Supported semantic definition, suggested from the source |
| `Storage type` | `REAL` or `INTEGER` for the initial measured-field workflow |
| `Unit` | Derived from measurement definition and archive storage unit system |
| `Aggregation` | Compatible policy such as average, sum, minimum, maximum or last |

Existing `TEXT` columns remain inspectable; a text measurement pipeline is outside
the first release. Counters are distinguished from interval totals, and wind
uses the existing vector policy. The core determines daily-summary behavior.
Custom names use the same unit and policy registry in archiving and frontend
queries. Creating a column does not backfill it or fabricate zero values.

Saving the draft creates the column and required summary structure before
activating its mapping. Schema additions use the existing WeeWX-compatible
database operations. Do not silently add summaries to an imported archive that
does not have them; initializing/rebuilding summaries is a maintenance operation.
Export the custom observation unit and accumulator definitions needed by an
external WeeWX installation. SQL compatibility alone does not supply those
runtime definitions.

Column deletion, renaming and type conversion are deferred migrations.

## 5. Application boundaries and storage

Use small PHP application services under `src/Admin/` for reading admin views
and applying commands. Domain validation stays in the relevant Config, Ingest,
Archive and measurement modules. Controllers translate HTTP input into typed
commands; CLI commands call the same services. Neither shells out to the other.

Start with operations for station admission, archive create/connect/update,
mapping preview/apply, field definition and column addition. Return structured
error codes, parameters and field paths; the presentation layer translates them.

| Store | Ownership |
|---|---|
| `weewx-php.conf` | Desired configuration, custom definitions and namespaced theme settings; retains comments |
| `ingest.sdb` | Admission, credentials and bounded field discovery inventory |
| `live.sdb` | Accepted observation journal and pending work |
| `state.sdb` | Applied revision snapshots, operation recovery, processing progress, admin account and audit records |
| WeeWX archives | WeeWX records, schema and daily summaries only |

Revision snapshots are immutable execution history, not another independently
editable configuration. Backup/restore includes configuration and application
state if reproducible processing history is required.

### Applying changes consistently

File replacement and SQLite transactions do not form one atomic transaction.
Use a recorded, recoverable operation with an idempotency key:

1. Validate a draft against the configuration revision and inspected schema.
2. Acquire the shared application writer lock; reload and revalidate. A stale
   revision returns a conflict and preserves the operator's draft.
3. Record the prepared operation and intended revision in application state.
4. Create the database or add columns transactionally within the affected
   database. Never overwrite an existing database or remove a column on retry.
5. Replace the configuration atomically, record its hash and complete activation
   metadata. Return success only when the operation is consistent.

Recover incomplete operations before processing the affected archive. A failed
save may leave an unused additive column; it must not leave an active mapping to
a missing column. Recheck schema identity and type on retry. Other archives may
continue when their state is consistent.

All application archive writers, including tick, catch-up, rebuild and CLI schema
commands, participate in the lock. Load or revalidate their configuration after
acquiring it; a snapshot loaded before waiting can be stale. SQLite transactions
remain necessary for external WeeWX writers, which do not use this lock.

Keep ingest transactions short. Release admission/journal transactions before
attempting a tick or application lock; enforce one documented lock order to
avoid deadlocks. Station admission remains an independent store transaction.

Manual config edits are detected by hash. Validate and adopt valid changes as
new revisions at the next boundary; record failures without partially activating
them. Older config files cannot silently bypass new validation through the CLI.

## 6. Theme settings contract

Themes provide a declarative settings definition with theme ID, schema version,
translation domain, groups and fields. Each field declares a stable key, type,
label key, default, constraints and optional options/visibility dependencies.
Initial types: text, boolean, integer, number, select, color, archive reference
and archive-field reference. The core validates references and dependencies.

Example definition, illustrating the proposed contract:

```json
{
  "theme": "example",
  "schema_version": 1,
  "translation_domain": "theme.example",
  "groups": [{"id": "display", "label": "settings.display"}],
  "fields": [
    {
      "key": "show_wind",
      "group": "display",
      "type": "boolean",
      "label": "settings.show_wind",
      "default": true
    }
  ]
}
```

The core owns rendering, validation, persistence and form actions. The contract
accepts no arbitrary admin markup, JavaScript or routes. Theme settings occupy
their own namespace and cannot override core labels or settings. An invalid
definition disables that theme's settings page with an actionable error while
the core admin remains available. A scoped API is not a sandbox for installed
PHP theme code; themes remain trusted installation code.

Theme changes preserve inactive themes' values. Defaults apply only to unset
keys. Validate schema upgrades and retain the previous values on incompatibility.
Initially store settings per installed theme; multiple independently configured
site instances are deferred. Keep this registry separate from the existing
`Frontend/Theme` rendering context and adapt validated values into that context.

## 7. Admin shell, language and access

Serve a dedicated admin entry point under `public/admin/`, with server-rendered
HTML and small progressive JavaScript enhancements for filtering and drafts.
Ship its assets with the application. The shell and login must work without an
active public theme. Core actions remain usable without JavaScript.

Use a core translation domain, stable message IDs, named parameters, locale-aware
plural rules and centralized number/date formatting. English is complete and
the fallback; never compose sentences from translated fragments. Language packs
include formatting/plural metadata, and adding one must not require editing
controllers. Keep current PHP extension requirements when choosing the formatter.

Admin language, public-site language, display units and archive storage units are
independent. Archive times are displayed in the archive timezone with the zone
visible where boundaries matter. UTF-8, logical CSS properties and text direction
support RTL. User-defined names are preserved; optional localized labels fall
back to their default label and then the technical name.

Use proper labels, keyboard operation, visible focus, status text beyond color,
and responsive forms. Successful actions use short feedback such as `Saved`.
Show essential consequences at the relevant action rather than permanent
instructional paragraphs.

The initial access model is one administrator account provisioned/reset locally
through the CLI, a password hash and server-side sessions. There is no public
first-visitor ownership claim. Credentials and session state live outside the
web root. Require authorization on every admin route, CSRF protection on writes,
session regeneration at login, expiry, login throttling and secure cookie flags.
Use HTTPS for deployed admin access; local HTTP development is explicit.

Mutation requests use POST and redirect after success. GET is read-only. Escape
station names, raw keys, translations and theme labels for their output context.
Database selection is restricted to configured local data roots with resolved
path checks; creating databases offers no arbitrary filesystem browser. Keep
secrets out of URLs, ordinary page responses and logs. Connection credentials
are revealed only through a deliberate authenticated setup action. Audit records
identify the action, object, revision and outcome without recording credentials.

Apply the repository security-review skill when implementing auth, input handling
and database changes, and complete its required review before a PR.

## 8. Compatibility and rollout

Existing configurations retain their current mapping mode until explicitly
converted. Display resolved primary, wildcard membership, housekeeping mappings,
indoor exclusions and overrides. Never label an implicit assignment as unmapped.
Detect existing collisions and expose them; block new conflicting changes without
silently rewriting historical or current assignments. Strict assignment rejection
applies to explicit mode and new edits; unchanged legacy mode retains its behavior
with diagnostics until converted.

Conversion to explicit mode previews all effective assignments and replaces
wildcards with the selected station set. Accepting it freezes those choices at
the activation boundary. New fields thereafter remain unassigned. An explicit
mode flag is required: today's primary-sender behavior otherwise continues to
map fields omitted from a `fields` block.

Legacy archives inherit the global interval. Extend pending-work registration
with archive-specific intervals while retaining the existing archive-ID metadata
for external producers. Catch-up must recover observations missed by an old
producer's global-interval hints; hints must never override the configured grid.
Exercise two archives with different intervals before enabling this feature.

Back up and version application-store migrations. Register a baseline revision
for the configuration observed at upgrade, without asserting knowledge of older
source history. Keep existing CLI syntax compatible and document new actions.

## 9. Implementation sequence and acceptance criteria

Each step is a bounded implementation change. Tests accompany changed behavior.

| Step | Deliverable | Acceptance |
|---|---|---|
| 1. Field and mapping model | Shared definitions, inventory, effective resolver and explicit mapping mode | Intermittent/unknown fields survive as bounded candidates; credentials never enter inventory; existing mappings resolve unchanged; incompatible or duplicate assignments are rejected |
| 2. Revisions and archive settings | Revision timeline, per-archive interval, enabled state and producer compatibility | Two archives process the same station independently; boundary, late-packet, catch-up and counter-source-change cases select the correct rules |
| 3. Shared change services | Config revision checks, create/connect/add operations and recovery, CLI integration | Stale saves conflict; interrupted operations recover; retries do not duplicate or overwrite; read operations never create files or columns |
| 4. Admin shell | Access control, routes, fixed navigation, components and English catalog | Login protection and CSRF tests pass; theme failure cannot break core access; second test locale, plural forms, long labels and RTL work |
| 5. Station/archive/field workflow | Adoption, archive setup, mapping table, inline column creation | Adopt station, create archive, map fields, add custom numeric field/column and verify resulting values through a tick |
| 6. Theme settings and remaining settings | Declarative registry, persistence, uploads/general settings forms | Invalid definitions stay contained; values survive theme changes; translations and server validation apply to every registered setting |
| 7. Maintenance and release verification | Bounded backup/verify/rebuild jobs, migration docs and security review | Recovery, WeeWX interoperability and browser workflows pass on the supported deployment model |

The first complete workflow uses two stations that both send `outTemp`: map one
to `outTemp`, the other to a separate temperature column, then add a previously
unknown numeric measurement with a declared source unit. Verify source separation,
unit conversion, interval aggregation and daily summaries. Also run the mapping
against a copied existing archive and prove older records remain unchanged.

Use the existing Docker lint, unit and WeeWX conformance suites. Extend focused
tests for actual changes, including config/store upgrade and failure recovery.
Custom-column conformance must open/read/write the database in WeeWX and use the
exported definitions when comparing units and aggregation. Browser verification
covers keyboard use, mobile layout, edits preserved on error, conflicts, English
fallback and translated/RTL forms. A static mockup does not count as completion
of a workflow backed by real application services.

## 10. Deferred scope

Multi-user roles, remote database URLs, theme marketplace installation, arbitrary
PHP/SQL/formula editors, station failover or fusion, raw-data backfill for unknown
fields, text observations, destructive column migrations, and multiple public
site instances are outside the first release. Existing databases are inspected
in place; browser file uploads/import transfers are a separate future workflow.
