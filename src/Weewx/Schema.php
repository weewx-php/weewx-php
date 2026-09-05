<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use WeewxPhp\Db\Sqlite;

/**
 * What a WeeWX database actually looks like, read from the database.
 *
 * WeeWX ships schema files, but they only ever seed a new database. After
 * that the schema lives in the file: installations add columns for sensors
 * they own. Anything that reads a real installation has to ask the file,
 * not the shipped list.
 */
final class Schema
{
    public const DAY_SUMMARY_VERSION = '4.0';

    /** The columns of a scalar daily summary table, after dateTime. */
    public const SCALAR_COLUMNS = ['min', 'mintime', 'max', 'maxtime', 'sum', 'count', 'wsum', 'sumtime'];

    /** The columns of the vector table, after dateTime. */
    public const VECTOR_COLUMNS = ['min', 'mintime', 'max', 'maxtime', 'sum', 'count', 'wsum', 'sumtime',
        'max_dir', 'xsum', 'ysum', 'dirsumtime', 'squaresum', 'wsquaresum'];

    /**
     * @param list<string> $columns The archive table's columns, in order.
     * @param array<string, string> $columnTypes Declared SQL type by column.
     * @param array<string, StatsKind> $dayTypes Which daily summary tables exist, and of what kind.
     * @param array<string, string> $metadata The rows of the metadata table.
     */
    public function __construct(
        public readonly string $tableName,
        public readonly array $columns,
        public readonly array $columnTypes,
        public readonly array $dayTypes,
        public readonly array $metadata,
    ) {}

    /**
     * @throws SchemaError If the archive table is not there.
     */
    public static function read(Sqlite $db, string $tableName = 'archive'): self
    {
        $columns = [];
        $types = [];
        foreach ($db->columns($tableName) as $column) {
            $columns[] = $column['name'];
            $types[$column['name']] = $column['type'];
        }
        if ($columns === []) {
            throw new SchemaError(sprintf('No table %s in %s', $tableName, $db->path()));
        }

        $prefix = $tableName . '_day_';
        $metadataTable = $tableName . '_day__metadata';
        $dayTypes = [];
        $metadata = [];
        foreach ($db->tables() as $table) {
            if (!str_starts_with($table, $prefix) || $table === $metadataTable) {
                continue;
            }
            $names = array_column($db->columns($table), 'name');
            // A vector table is the scalar one plus the six wind columns.
            // Deciding by column count would break the day an extension adds one.
            $dayTypes[substr($table, strlen($prefix))] = in_array('xsum', $names, true) ? StatsKind::Vector : StatsKind::Scalar;
        }
        if (in_array($metadataTable, $db->tables(), true)) {
            foreach ($db->query(sprintf('SELECT name, value FROM "%s"', $metadataTable)) as $row) {
                $metadata[Sqlite::text($row['name'])] = $row['value'] === null ? '' : Sqlite::text($row['value']);
            }
        }
        return new self($tableName, $columns, $types, $dayTypes, $metadata);
    }

    public function hasColumn(string $name): bool
    {
        return array_key_exists($name, $this->columnTypes);
    }

    /**
     * This schema with columns added as REAL, the way `[[[columns]]]` adds
     * them: what a check holds a mapping against before a tick has run.
     *
     * @param list<string> $names
     */
    public function withColumns(array $names): self
    {
        $columns = $this->columns;
        $types = $this->columnTypes;
        foreach ($names as $name) {
            if (!isset($types[$name])) {
                $columns[] = $name;
                $types[$name] = 'REAL';
            }
        }
        return new self($this->tableName, $columns, $types, $this->dayTypes, $this->metadata);
    }

    /** The daily summary version, or null for a database without summaries. */
    public function version(): ?string
    {
        return $this->metadata['Version'] ?? null;
    }

    public function hasDaySummaries(): bool
    {
        return $this->dayTypes !== [];
    }

    /**
     * @return list<string> The observation columns: everything but dateTime, usUnits and interval.
     */
    public function observations(): array
    {
        return array_values(array_filter($this->columns, static fn(string $name): bool => !in_array($name, Wview::NOT_OBSERVATIONS, true)));
    }

    /** @return list<string> */
    public static function dayColumns(StatsKind $kind): array
    {
        return $kind === StatsKind::Vector ? self::VECTOR_COLUMNS : self::SCALAR_COLUMNS;
    }
}
