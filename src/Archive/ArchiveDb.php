<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use DateTimeZone;
use Generator;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\DbError;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Live\Packet;
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\SchemaError;
use WeewxPhp\Weewx\StatsKind;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\VecStats;
use WeewxPhp\Weewx\Wview;

/**
 * A WeeWX archive database, open for reading and writing.
 *
 * Everything here is written so that WeeWX 5 can pick the file up again
 * afterwards and not notice anyone else was in it:
 *
 *   * Columns come from the file, never from a list in this code. An
 *     installation with sensors we have never heard of keeps them.
 *   * Readings the database has no column for are dropped, not added.
 *     Adding a column changes the schema, and that is a decision a person
 *     makes with `[[[columns]]]`.
 *   * The daily summaries are maintained the way WeeWX maintains them, with
 *     the same weighting, and the metadata version stays at 4.0.
 *   * A new database is created with the statements WeeWX uses, word for
 *     word, so `sqlite_master` reads the same.
 */
final class ArchiveDb implements History
{
    private const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private Schema $schema;

    /** @var array<string, int> Fields dropped for want of a column, and how often. */
    private array $homeless = [];

    private ?bool $hasHardware = null;

    private function __construct(
        private readonly Sqlite $db,
        private readonly string $tableName,
        private readonly Policy $policy,
        private readonly DateTimeZone $zone,
        private readonly bool $created,
    ) {}

    /**
     * Open a database, creating it with the wview_extended schema when it
     * does not exist and `create` allows it.
     *
     * @param DateTimeZone $zone The zone whose midnight the daily summaries are keyed on.
     *
     * @throws DbError If the file cannot be opened.
     * @throws SchemaError If the file is not a WeeWX archive, or its daily summaries carry
     *     the known-bad weights of WeeWX 4.2 and 4.3.
     */
    public static function open(
        string $path,
        JournalMode $journalMode,
        Policy $policy,
        DateTimeZone $zone,
        bool $create = false,
        string $tableName = 'archive',
    ): self {
        $existed = is_file($path);
        if (!$existed && !$create) {
            throw new DbError(sprintf('Archive %s does not exist', $path));
        }
        $db = Sqlite::open($path, $create, $journalMode, 'FULL');
        $archive = new self($db, $tableName, $policy, $zone, !$existed);
        if (!$existed) {
            $archive->create();
        }
        $archive->schema = Schema::read($db, $tableName);
        $archive->checkVersion();
        return $archive;
    }

    public function close(): void
    {
        $this->db->close();
    }

    public function path(): string
    {
        return $this->db->path();
    }

    /** Whether this call created the file. */
    public function created(): bool
    {
        return $this->created;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function reloadSchema(): void
    {
        $this->schema = Schema::read($this->db, $this->tableName);
    }

    // -- creation ---------------------------------------------------------

    /** Lay out a fresh database, statement for statement as WeeWX would. */
    private function create(): void
    {
        $this->db->transaction(function (): void {
            $this->db->exec(self::createTableSql($this->tableName, Wview::ARCHIVE_TABLE));
            foreach (Wview::daySummaries() as $obsType => $kind) {
                $this->createDayTable($obsType, $kind);
            }
            $this->db->exec(self::createTableSql($this->tableName . '_day__metadata', [
                ['name', 'CHAR(20) NOT NULL PRIMARY KEY'],
                ['value', 'TEXT'],
            ]));
            $this->db->exec(sprintf('INSERT INTO %s_day__metadata VALUES(?, ?)', $this->tableName), ['Version', Schema::DAY_SUMMARY_VERSION]);
        });
    }

    private function createDayTable(string $obsType, StatsKind $kind): void
    {
        $types = ['dateTime' => 'INTEGER NOT NULL PRIMARY KEY', 'count' => 'INTEGER', 'mintime' => 'INTEGER',
            'maxtime' => 'INTEGER', 'sumtime' => 'INTEGER', 'dirsumtime' => 'INTEGER'];
        $columns = [['dateTime', $types['dateTime']]];
        foreach (Schema::dayColumns($kind) as $column) {
            $columns[] = [$column, $types[$column] ?? 'REAL'];
        }
        $this->db->exec(self::createTableSql(sprintf('%s_day_%s', $this->tableName, $obsType), $columns));
    }

    /**
     * The statement `weedb.Cursor.create_table` issues: names unquoted,
     * columns joined by ', ', a semicolon at the end.
     *
     * @param list<array{0: string, 1: string}> $columns
     */
    private static function createTableSql(string $table, array $columns): string
    {
        $parts = array_map(static fn(array $column): string => $column[0] . ' ' . $column[1], $columns);
        return sprintf('CREATE TABLE %s (%s);', $table, implode(', ', $parts));
    }

    /**
     * Refuse a database whose daily summaries carry known-bad weights.
     *
     * WeeWX 4.2.0 read version-2 sums as version 1, and 4.3.0's repair left
     * `dirsumtime` unweighted. Both are fixable, but by WeeWX: `weectl
     * database rebuild-daily`. Writing new records on top of the damage
     * would mix two weighting schemes in one table and make it unfixable.
     */
    private function checkVersion(): void
    {
        $version = $this->schema->version();
        if ($version === null || $version === Schema::DAY_SUMMARY_VERSION) {
            return;
        }
        throw new SchemaError(sprintf(
            'The daily summaries of %s are at version %s, not %s. Let WeeWX repair them first (weectl database rebuild-daily).',
            $this->db->path(),
            $version,
            Schema::DAY_SUMMARY_VERSION,
        ));
    }

    // -- reading ----------------------------------------------------------

    /** The unit system of the first record, which is the database's: WeeWX asks the same way. */
    public function unitSystem(): ?UnitSystem
    {
        $value = $this->db->scalar(sprintf('SELECT usUnits FROM %s LIMIT 1', $this->quoted($this->tableName)));
        if ($value === null && $this->hasHardware()) {
            $value = $this->db->scalar('SELECT usUnits FROM weewx_hardware LIMIT 1');
        }
        return is_int($value) ? UnitSystem::tryFrom($value) : null;
    }

    public function hasHardware(): bool
    {
        return $this->hasHardware ??= in_array('weewx_hardware', $this->db->tables(), true);
    }

    /** Original mapped hardware records survive live retention and target-grid changes.
     * @param array<string, mixed> $record
     */
    public function preserveHardware(string $source, array $record): bool
    {
        $stop = $record['dateTime'] ?? null;
        $interval = $record['interval'] ?? null;
        $units = $record['usUnits'] ?? null;
        if (!is_int($stop) || (!is_int($interval) && !is_float($interval)) || $interval <= 0 || $interval > 1440 || !is_int($units)) {
            throw new IntervalError('Invalid original hardware interval');
        }
        $start = $stop - (int) round($interval * 60);
        if (!$this->hasHardware()) {
            $this->db->exec(Hardware::SCHEMA);
            $this->hasHardware = true;
        }
        $json = Packet::canonical($record);
        return $this->db->transaction(function () use ($source, $record, $start, $stop, $units, $json): bool {
            $previous = $this->db->scalar('SELECT record FROM weewx_hardware WHERE source = ? AND start = ? AND stop = ? LIMIT 1', [$source, $start, $stop]);
            if ($previous === $json) {
                return false;
            }
            $this->db->exec('DELETE FROM weewx_hardware WHERE source = ? AND start = ? AND stop = ?', [$source, $start, $stop]);
            $day = Intervals::startOfArchiveDay($stop, $this->zone);
            foreach ($record as $field => $value) {
                if (!in_array($field, Wview::NOT_OBSERVATIONS, true) && (is_int($value) || is_float($value)) && is_finite((float) $value)) {
                    $this->db->exec('INSERT INTO weewx_hardware VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$field, $source, $start, $stop, $day, $units, $value, $json]);
                }
            }
            return true;
        });
    }

    /** @return list<array{source: string, start: int, stop: int, record: array<string, mixed>}> */
    public function hardwareRecords(int $start, int $stop): array
    {
        if (!$this->hasHardware()) {
            return [];
        }
        $records = [];
        foreach ($this->db->query('SELECT DISTINCT source, start, stop, record FROM weewx_hardware WHERE stop > ? AND stop <= ? ORDER BY stop, source', [$start, $stop]) as $row) {
            $records[] = ['source' => Sqlite::text($row['source']), 'start' => (int) Sqlite::text($row['start']),
                'stop' => (int) Sqlite::text($row['stop']), 'record' => Json::object(Sqlite::text($row['record']))];
        }
        return $records;
    }

    public function firstTimestamp(): ?int
    {
        $value = $this->db->scalar(sprintf('SELECT MIN(dateTime) FROM %s', $this->quoted($this->tableName)));
        return is_int($value) ? $value : null;
    }

    public function lastTimestamp(): ?int
    {
        $value = $this->db->scalar(sprintf('SELECT MAX(dateTime) FROM %s', $this->quoted($this->tableName)));
        return is_int($value) ? $value : null;
    }

    public function count(): int
    {
        $value = $this->db->scalar(sprintf('SELECT COUNT(*) FROM %s', $this->quoted($this->tableName)));
        return is_int($value) ? $value : 0;
    }

    public function exists(int $timestamp): bool
    {
        return $this->db->one(sprintf('SELECT 1 FROM %s WHERE dateTime = ?', $this->quoted($this->tableName)), [$timestamp]) !== null;
    }

    /**
     * One record, without its NULL columns. Dropping them is correct rather
     * than tidy: the accumulator distinguishes "no value" from "value
     * null", and a record padded out to every column would create daily
     * summary rows for sensors the station never had.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $timestamp): ?array
    {
        $row = $this->db->one(sprintf('SELECT * FROM %s WHERE dateTime = ?', $this->quoted($this->tableName)), [$timestamp]);
        return $row === null ? null : self::withoutNulls($row);
    }

    /**
     * The record closest to a moment, within a tolerance: WeeWX's
     * `getRecord(ts, max_delta)`, which the station-pressure calculation
     * asks for the temperature of twelve hours ago.
     *
     * @return array<string, mixed>|null
     */
    public function recordNear(int $timestamp, int $maxDelta): ?array
    {
        $row = $this->db->one(
            sprintf('SELECT * FROM %s WHERE dateTime >= ? AND dateTime <= ? ORDER BY ABS(dateTime - ?) ASC LIMIT 1', $this->quoted($this->tableName)),
            [$timestamp - $maxDelta, $timestamp + $maxDelta, $timestamp],
        );
        return $row === null ? null : self::withoutNulls($row);
    }

    /**
     * The records in (start, stop], in time order, without NULL columns.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function records(int $start, int $stop): Generator
    {
        $sql = sprintf('SELECT * FROM %s WHERE dateTime > ? AND dateTime <= ? ORDER BY dateTime', $this->quoted($this->tableName));
        foreach ($this->db->query($sql, [$start, $stop]) as $row) {
            yield self::withoutNulls($row);
        }
    }

    /**
     * Up to `limit` records newer than a moment, oldest first, without
     * their NULL columns: what an upload still owes a service.
     *
     * @return list<array<string, mixed>>
     */
    public function recordsAfter(int $after, int $limit): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE dateTime > ? ORDER BY dateTime LIMIT ?', $this->quoted($this->tableName));
        $found = [];
        foreach ($this->db->query($sql, [$after, max(1, $limit)]) as $row) {
            $found[] = self::withoutNulls($row);
        }
        return $found;
    }

    /**
     * The newest record, without its NULL columns, or null for an empty archive.
     *
     * @return array<string, mixed>|null
     */
    public function newest(): ?array
    {
        $row = $this->db->one(sprintf('SELECT * FROM %s ORDER BY dateTime DESC LIMIT 1', $this->quoted($this->tableName)));
        return $row === null ? null : self::withoutNulls($row);
    }

    /**
     * The rain that fell in a span, with the unit systems it was recorded
     * in: WeeWX's `RESTThread.get_record` asks the archive exactly this for
     * the hour, the day and the last twenty-four hours a service wants.
     *
     * @param bool $inclusiveStart Whether a record stamped at `start` counts. WeeWX counts the
     *     midnight record into the day that begins with it, and nothing else on its left edge.
     *
     * @return array{0: float|null, 1: int|null, 2: int|null}|null A 3-way tuple (the sum, the
     *     lowest and the highest usUnits in the span); the sum null when nothing fell or was
     *     recorded; null altogether when the archive has no `rain` column.
     */
    public function rainSum(int $start, int $stop, bool $inclusiveStart): ?array
    {
        if (!$this->schema->hasColumn('rain')) {
            return null;
        }
        $row = $this->db->one(
            sprintf(
                'SELECT SUM(rain) AS total, MIN(usUnits) AS lowest, MAX(usUnits) AS highest FROM %s WHERE dateTime %s ? AND dateTime <= ?',
                $this->quoted($this->tableName),
                $inclusiveStart ? '>=' : '>',
            ),
            [$start, $stop],
        );
        if ($row === null) {
            return null;
        }
        $total = $row['total'];
        return [
            is_int($total) || is_float($total) ? (float) $total : null,
            is_int($row['lowest']) ? $row['lowest'] : null,
            is_int($row['highest']) ? $row['highest'] : null,
        ];
    }

    /**
     * The aggregates the evapotranspiration formula needs over a span:
     * WeeWX's `ETXType` asks the archive for exactly these.
     *
     * @return array<string, mixed>|null
     */
    public function etWindow(int $start, int $stop): ?array
    {
        if (!$this->schema->hasColumn('radiation') || !$this->schema->hasColumn('windSpeed')
            || !$this->schema->hasColumn('outTemp') || !$this->schema->hasColumn('outHumidity')) {
            return null;
        }
        // WeeWX asks for (start, stop] while the record ending at `stop` is
        // not in the table yet. Here it may be, when a span is rebuilt, so
        // the end is left out and the rows are the ones WeeWX saw.
        return $this->db->one(
            sprintf(
                'SELECT MAX(outTemp) AS t_max, MIN(outTemp) AS t_min, AVG(radiation) AS rad_avg, AVG(windSpeed) AS wind_avg,'
                . ' MAX(outHumidity) AS rh_max, MIN(outHumidity) AS rh_min, MAX(usUnits) AS units_max, MIN(usUnits) AS units_min'
                . ' FROM %s WHERE dateTime > ? AND dateTime < ?',
                $this->quoted($this->tableName),
            ),
            [$start, $stop],
        );
    }

    // -- writing ----------------------------------------------------------

    /**
     * Write one archive record and fold it into the daily summaries.
     *
     * Returns false if a record for that timestamp was already there and
     * `replace` is not set. That is the normal case when catching up: the
     * primary key makes the write idempotent, so replaying packets cannot
     * double-count anything.
     *
     * @param array<string, mixed> $record
     * @param bool $replace Whether an existing record is replaced. Its old contribution to the
     *     daily sums is taken back out first; its extremes are not, see rebuildDay().
     * @param bool $updateDaily Whether to fold the record into its day. Off when a caller
     *     batches a day itself.
     *
     * @throws DbError If the record has no dateTime.
     */
    public function addRecord(array $record, bool $replace = false, bool $updateDaily = true): bool
    {
        $known = [];
        foreach ($record as $column => $value) {
            if ($this->schema->hasColumn($column)) {
                $known[$column] = $value;
            } else {
                $this->noteHomeless($column);
            }
        }
        if (!array_key_exists('dateTime', $known)) {
            throw new DbError('Record has no dateTime');
        }
        $names = implode(', ', array_map($this->quoted(...), array_keys($known)));
        $marks = implode(', ', array_fill(0, count($known), '?'));
        $verb = $replace ? 'INSERT OR REPLACE' : 'INSERT OR IGNORE';
        $sql = sprintf('%s INTO %s (%s) VALUES (%s)', $verb, $this->quoted($this->tableName), $names, $marks);

        return $this->db->transaction(function () use ($record, $known, $replace, $updateDaily, $sql): bool {
            if ($replace && $updateDaily) {
                $old = $this->record(self::timestampOf($record));
                if ($old !== null) {
                    $this->unapplyDaily($old);
                }
            }
            if ($this->db->exec($sql, array_values(array_map(self::bindable(...), $known))) === 0) {
                return false;
            }
            if ($updateDaily) {
                $this->applyDaily($known);
            }
            return true;
        });
    }

    /**
     * Write many records in time order, touching each day's summaries once.
     *
     * WeeWX reads and rewrites all of a day's summary tables for every
     * single record. At one record every five minutes nobody notices;
     * catching up a year turns it into millions of statements. Here a day
     * is loaded once and written once, which is the same arithmetic in the
     * same order: the accumulator does not care whether records arrive one
     * at a time or in a batch.
     *
     * A record that replaces one already there keeps the old one's share
     * of the sums; rebuild the day afterwards, as `Archiver::rebuild` does.
     *
     * @param iterable<array<string, mixed>> $records
     *
     * @return int How many were written.
     */
    public function addRecords(iterable $records, bool $replace = false): int
    {
        $written = 0;
        $daySod = null;
        $day = null;
        foreach ($records as $record) {
            $timestamp = self::timestampOf($record);
            $sod = Intervals::startOfArchiveDay($timestamp, $this->zone);
            if ($sod !== $daySod) {
                if ($day !== null && $daySod !== null) {
                    $this->db->transaction(fn() => $this->storeDay($daySod, $day, $day->stop() === $daySod ? null : $this->lastRecordTimestamp($day)));
                }
                $daySod = $sod;
                $day = null;
            }
            if (!$this->addRecord($record, $replace, false)) {
                continue;
            }
            $written++;
            try {
                $weight = self::weightOf($record);
            } catch (IntervalError) {
                continue;
            }
            $day ??= $this->loadDay($sod, self::unitSystemOf($record));
            $day->addRecord($this->knownOnly($record), true, $weight);
        }
        if ($day !== null && $daySod !== null) {
            $this->db->transaction(fn() => $this->storeDay($daySod, $day, $this->lastRecordTimestamp($day)));
        }
        return $written;
    }

    /**
     * Say once, per field, that a reading has nowhere to live.
     *
     * A reading only survives the archive interval if the table has a
     * column for it. Ecowitt hardware can fill four times the 113 the
     * standard schema has, so dropping some is normal; dropping them
     * silently is how a sensor ends up missing from a series for a year
     * before anybody notices. The count is what `columns` reports.
     */
    private function noteHomeless(string $column): void
    {
        $this->homeless[$column] = ($this->homeless[$column] ?? 0) + 1;
    }

    /** @return array<string, int> Fields dropped for want of a column, and how often, since opening. */
    public function homeless(): array
    {
        return $this->homeless;
    }

    /**
     * Which columns hold anything at all: `{column: [count, last timestamp]}`.
     *
     * This is what stands between placing a field and ruining a series: a
     * column with history came from some other sensor, and a second one in
     * there mixes two measurements nothing afterwards can separate.
     *
     * @return array<string, array{0: int, 1: int|null}>
     */
    public function occupied(): array
    {
        $observations = $this->schema->observations();
        if ($observations === []) {
            return [];
        }
        $counted = [];
        foreach ($observations as $name) {
            $column = $this->quoted($name);
            $counted[] = sprintf('COUNT(%s), MAX(CASE WHEN %s IS NOT NULL THEN dateTime END)', $column, $column);
        }
        $row = $this->db->one(sprintf('SELECT %s FROM %s', implode(', ', $counted), $this->quoted($this->tableName)));
        if ($row === null) {
            return [];
        }
        $values = array_values($row);
        $found = [];
        foreach ($observations as $index => $name) {
            $count = $values[$index * 2];
            $last = $values[$index * 2 + 1];
            if (is_int($count) && $count > 0) {
                $found[$name] = [$count, is_int($last) ? $last : null];
            }
        }
        return $found;
    }

    /**
     * Give a reading somewhere to live: WeeWX's `add_column`, which also
     * creates the column's daily summary table. Returns false if the
     * column already exists.
     *
     * @throws DbError If the name is not a usable column name.
     */
    public function addColumn(string $name, ColumnType $type): bool
    {
        if (preg_match(self::COLUMN_PATTERN, $name) !== 1 || in_array($name, Wview::NOT_OBSERVATIONS, true)) {
            throw new DbError(sprintf('%s is not a usable column name', var_export($name, true)));
        }
        if ($this->schema->hasColumn($name)) {
            return false;
        }
        $this->db->transaction(function () use ($name, $type): void {
            $this->db->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $this->tableName, $name, $type->value));
            if ($this->schema->hasDaySummaries() && !isset($this->schema->dayTypes[$name])) {
                $this->createDayTable($name, StatsKind::Scalar);
            }
        });
        $this->reloadSchema();
        unset($this->homeless[$name]);
        return true;
    }

    // -- daily summaries --------------------------------------------------

    /**
     * The weight one archive record carries in a daily summary: WeeWX's
     * `_calc_weight` for summaries at version 2.0 and up.
     *
     * @param array<string, mixed> $record
     *
     * @throws IntervalError If the record has no positive interval.
     */
    public static function weightOf(array $record): float
    {
        if (!array_key_exists('interval', $record)) {
            throw new IntervalError("Missing value for record field 'interval'");
        }
        $interval = $record['interval'];
        if ((!is_int($interval) && !is_float($interval)) || $interval <= 0) {
            throw new IntervalError(sprintf("Non-positive value for record field 'interval': %s", var_export($interval, true)));
        }
        return 60.0 * $interval;
    }

    /**
     * A day's accumulator, primed with every type the database keeps a
     * summary for. Types with no stored row are initialised empty rather
     * than left out: WeeWX writes a row for every known observation on
     * every day it touches, even one that stayed null all day.
     */
    public function loadDay(int $startOfDay, ?UnitSystem $unitSystem): Accum
    {
        $day = new Accum($startOfDay, Intervals::endOfDay($startOfDay, $this->zone), $unitSystem, $this->policy);
        foreach ($this->schema->dayTypes as $obsType => $kind) {
            $columns = implode(', ', array_map($this->quoted(...), Schema::dayColumns($kind)));
            $row = $this->db->one(
                sprintf('SELECT %s FROM %s WHERE dateTime = ?', $columns, $this->quoted(sprintf('%s_day_%s', $this->tableName, $obsType))),
                [$startOfDay],
            );
            /** @var list<int|float|null>|null $tuple */
            $tuple = $row === null ? null : array_values($row);
            $day->setStats($obsType, $tuple);
        }
        return $day;
    }

    /**
     * Write a day's statistics, one row per type the database keeps, and
     * move `lastUpdate` forward.
     *
     * @param int|null $lastUpdate The timestamp of the record that caused this, or null to leave
     *     the metadata alone.
     */
    public function storeDay(int $startOfDay, Accum $day, ?int $lastUpdate): void
    {
        $this->db->transaction(function () use ($startOfDay, $day, $lastUpdate): void {
            foreach ($day->types() as $obsType) {
                $kind = $this->schema->dayTypes[$obsType] ?? null;
                if ($kind === null) {
                    // No daily table for this observation. WeeWX ignores it too;
                    // the tables are made when the database is, not on the fly.
                    continue;
                }
                $columns = array_merge(['dateTime'], Schema::dayColumns($kind));
                $sql = sprintf(
                    'INSERT OR REPLACE INTO %s (%s) VALUES (%s)',
                    $this->quoted(sprintf('%s_day_%s', $this->tableName, $obsType)),
                    implode(', ', array_map($this->quoted(...), $columns)),
                    implode(', ', array_fill(0, count($columns), '?')),
                );
                $this->db->exec($sql, [$startOfDay, ...$day->get($obsType)->statsTuple()]);
            }
            if ($lastUpdate !== null) {
                $this->advanceLastUpdate($lastUpdate);
            }
        });
    }

    /**
     * Recompute one day's summaries from the archive table: the derivation
     * the summaries are a cache of. It also dulls any extreme that only
     * ever existed in a LOOP packet; the archiver sharpens afterwards from
     * the live journal, as far as that reaches.
     *
     * @return int How many records went into the day.
     */
    public function rebuildDay(int $startOfDay): int
    {
        [$day, $count] = $this->dayFromRecords($startOfDay);
        $this->db->transaction(function () use ($startOfDay, $day, $count): void {
            foreach (array_keys($this->schema->dayTypes) as $obsType) {
                $this->db->exec(sprintf('DELETE FROM %s WHERE dateTime = ?', $this->quoted(sprintf('%s_day_%s', $this->tableName, $obsType))), [$startOfDay]);
            }
            if ($count > 0) {
                $this->storeDay($startOfDay, $day, null);
            }
        });
        return $count;
    }

    /** Publish a completed replay checkpoint, removing extrema of observations now absent. */
    public function replaceDay(int $startOfDay, Accum $day): void
    {
        $this->db->transaction(function () use ($startOfDay, $day): void {
            foreach ($this->schema->dayTypes as $obsType => $kind) {
                $this->db->exec(sprintf('DELETE FROM %s WHERE dateTime = ?', $this->quoted(sprintf('%s_day_%s', $this->tableName, $obsType))), [$startOfDay]);
                if (!$day->has($obsType)) {
                    $day->setStats($obsType);
                }
            }
            foreach ($this->records($day->start(), $day->stop()) as $record) {
                $this->storeDay($startOfDay, $day, null);
                break;
            }
        });
    }

    /**
     * A day's statistics from the archive table alone, without writing
     * them: what {@see rebuildDay()} stores, and what `verify` holds the
     * stored rows against.
     *
     * @return array{0: Accum, 1: int} A 2-way tuple (the day, how many records went into it).
     */
    public function dayFromRecords(int $startOfDay): array
    {
        $endOfDay = Intervals::endOfDay($startOfDay, $this->zone);
        $day = new Accum($startOfDay, $endOfDay, null, $this->policy);
        foreach ($this->schema->dayTypes as $obsType => $kind) {
            $day->setStats($obsType);
        }
        $count = 0;
        foreach ($this->records($startOfDay, $endOfDay) as $record) {
            try {
                $weight = self::weightOf($record);
            } catch (IntervalError) {
                continue;
            }
            $day->addRecord($record, true, $weight);
            $count++;
        }
        return [$day, $count];
    }

    /**
     * The days that have records, as their local midnights, in order.
     *
     * @return Generator<int, int>
     */
    public function days(): Generator
    {
        $seen = null;
        foreach ($this->db->query(sprintf('SELECT dateTime FROM %s ORDER BY dateTime', $this->quoted($this->tableName))) as $row) {
            $timestamp = $row['dateTime'];
            if (!is_int($timestamp)) {
                continue;
            }
            $sod = Intervals::startOfArchiveDay($timestamp, $this->zone);
            if ($sod !== $seen) {
                $seen = $sod;
                yield $sod;
            }
        }
    }

    /** @param array<string, mixed> $record Only known columns. */
    private function applyDaily(array $record): void
    {
        try {
            $weight = self::weightOf($record);
        } catch (IntervalError) {
            // WeeWX logs and moves on. The archive record still stands; only
            // its contribution to the daily average is lost.
            return;
        }
        $timestamp = self::timestampOf($record);
        $sod = Intervals::startOfArchiveDay($timestamp, $this->zone);
        $day = $this->loadDay($sod, self::unitSystemOf($record));
        $day->addRecord($record, true, $weight);
        $this->storeDay($sod, $day, $timestamp);
    }

    /**
     * Take one record's contribution back out, for a replacement. Only the
     * sums come out: extremes cannot be reversed, a maximum does not
     * remember the runner-up, so a day whose records changed needs
     * rebuildDay() for its highs and lows.
     *
     * @param array<string, mixed> $record
     */
    private function unapplyDaily(array $record): void
    {
        try {
            $weight = self::weightOf($record);
        } catch (IntervalError) {
            return;
        }
        $sod = Intervals::startOfArchiveDay(self::timestampOf($record), $this->zone);
        $day = $this->loadDay($sod, self::unitSystemOf($record));
        $negative = new Accum($sod, Intervals::endOfDay($sod, $this->zone), self::unitSystemOf($record), $this->policy);
        $negative->addRecord($record, false, $weight);
        foreach ($negative->types() as $obsType) {
            if (!$day->has($obsType)) {
                continue;
            }
            $mine = $day->get($obsType);
            $theirs = $negative->get($obsType);
            if ($mine instanceof ScalarStats && $theirs instanceof ScalarStats) {
                $mine->sum -= $theirs->sum;
                $mine->count -= $theirs->count;
                $mine->wsum -= $theirs->wsum;
                $mine->sumtime -= $theirs->sumtime;
            } elseif ($mine instanceof VecStats && $theirs instanceof VecStats) {
                $mine->sum -= $theirs->sum;
                $mine->count -= $theirs->count;
                $mine->wsum -= $theirs->wsum;
                $mine->sumtime -= $theirs->sumtime;
                $mine->xsum -= $theirs->xsum;
                $mine->ysum -= $theirs->ysum;
                $mine->dirsumtime -= $theirs->dirsumtime;
                $mine->squaresum -= $theirs->squaresum;
                $mine->wsquaresum -= $theirs->wsquaresum;
            }
        }
        $this->storeDay($sod, $day, null);
    }

    /**
     * `lastUpdate` the way WeeWX keeps it: the time of the last record
     * folded into the summaries. Only ever moved forward, so a replayed or
     * replaced record cannot make WeeWX backfill what is already there.
     */
    private function advanceLastUpdate(int $timestamp): void
    {
        $current = $this->getMeta('lastUpdate');
        if ($current !== null && is_numeric($current) && (int) $current >= $timestamp) {
            return;
        }
        $this->setMeta('lastUpdate', (string) $timestamp);
    }

    private function lastRecordTimestamp(Accum $day): ?int
    {
        // The latest timestamp any statistic in the day saw is the last
        // record that went in: what WeeWX writes as lastUpdate after a batch.
        $latest = null;
        foreach ($day->types() as $obsType) {
            $stats = $day->get($obsType);
            $time = $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->lasttime : null;
            if (is_int($time) && ($latest === null || $time > $latest)) {
                $latest = $time;
            }
        }
        return $latest;
    }

    // -- metadata ---------------------------------------------------------

    public function getMeta(string $name): ?string
    {
        if (!$this->schema->hasDaySummaries() && $this->schema->metadata === []) {
            return null;
        }
        $value = $this->db->scalar(sprintf('SELECT value FROM %s WHERE name = ?', $this->quoted($this->tableName . '_day__metadata')), [$name]);
        return $value === null ? null : Sqlite::text($value);
    }

    /** Write one metadata row the way WeeWX does: delete, then insert. */
    public function setMeta(string $name, string $value): void
    {
        $table = $this->quoted($this->tableName . '_day__metadata');
        $this->db->transaction(function () use ($table, $name, $value): void {
            $this->db->exec(sprintf('DELETE FROM %s WHERE name = ?', $table), [$name]);
            $this->db->exec(sprintf('INSERT INTO %s VALUES(?, ?)', $table), [$name, $value]);
        });
    }

    // -- maintenance ------------------------------------------------------

    /** Copy the database with SQLite's online backup. */
    public function backup(string $target): void
    {
        $this->db->backup($target);
    }

    /** @return list<string> What `PRAGMA integrity_check` says; ['ok'] when nothing is wrong. */
    public function integrityCheck(): array
    {
        $lines = [];
        foreach ($this->db->query('PRAGMA integrity_check') as $row) {
            $lines[] = Sqlite::text(reset($row));
        }
        return $lines;
    }

    /**
     * Run work inside one transaction on this database.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }

    // -- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed> The record without the fields the table has no column for.
     */
    private function knownOnly(array $record): array
    {
        return array_filter($record, fn(string $column): bool => $this->schema->hasColumn($column), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, mixed> $record */
    private static function timestampOf(array $record): int
    {
        $timestamp = $record['dateTime'] ?? null;
        if (is_int($timestamp)) {
            return $timestamp;
        }
        if (is_float($timestamp)) {
            return (int) $timestamp;
        }
        throw new DbError('Record has no dateTime');
    }

    /** @param array<string, mixed> $record */
    private static function unitSystemOf(array $record): ?UnitSystem
    {
        $value = $record['usUnits'] ?? null;
        return is_int($value) ? UnitSystem::tryFrom($value) : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $row): array
    {
        return array_filter($row, static fn(mixed $value): bool => $value !== null);
    }

    /** What a record value is bound as: numbers and text as they are, anything else as text. */
    private static function bindable(mixed $value): int|float|string|null
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function quoted(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
