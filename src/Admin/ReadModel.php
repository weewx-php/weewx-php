<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\UnitSystem;

/** Domain reads use SQLite read-only connections and never initialize an archive. */
final class ReadModel
{
    /** @var array<string, list<array{boundary: int, file: ConfFile}>> */
    private array $revisions = [];
    public readonly Config $config;
    public readonly ConfFile $file;
    public readonly string $revision;

    public function __construct(public readonly string $path)
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw new Problem('error.configuration');
        }
        $this->file = ConfFile::parse($text);
        $this->revision = hash('sha256', $text);
        $resolved = realpath($path);
        $this->config = \WeewxPhp\Config\ConfigReader::read($this->file, dirname($resolved === false ? $path : $resolved));
    }

    /**
     * @return list<array<string, mixed>> */
    public function stations(): array
    {
        $found = [];
        foreach ($this->rows('ingest.sdb', 'ingest_sender', 'SELECT id, name, state, protocol, model, last_seen, received, stored FROM ingest_sender ORDER BY first_seen LIMIT 2000') as $row) {
            $found[Sqlite::text($row['id'])] = $row;
        }
        foreach ($this->rows('live.sdb', 'weewx_station', "SELECT sender AS id, name, state, 'weewx' AS protocol, COALESCE(json_extract(r.source, '$.model'), driver_module) AS model, r.source, last_seen, stored FROM weewx_station s LEFT JOIN weewx_sensor r USING (collector, station) ORDER BY first_seen LIMIT 2000") as $row) {
            $found[Sqlite::text($row['id'])] = $row;
        }
        foreach ($this->config->stations as $id => $station) {
            if (isset($found[$id])) {
                $found[$id]['name'] = $station->name;
            } else {
                $found[$id] = ['id' => $id, 'name' => $station->name, 'state' => 'adopted', 'protocol' => 'journal', 'model' => '', 'last_seen' => null];
            }
        }
        return array_values($found);
    }

    /**
     * @return list<array<string, mixed>> */
    public function fields(string $station): array
    {
        $fields = $this->rows('ingest.sdb', 'ingest_field', 'SELECT native, observation, value, unit, last_seen FROM ingest_field WHERE sender = ? ORDER BY native LIMIT 256', [$station]);
        $known = [];
        foreach ($fields as &$field) {
            $native = Sqlite::text($field['native']);
            $definition = $this->config->sources[$station][$native] ?? null;
            if ($definition !== null && $field['observation'] === null) {
                $field['observation'] = $definition->observation;
                $field['unit'] = $definition->unit;
            }
            if (is_string($field['observation'])) {
                $known[$field['observation']] = true;
            }
        }
        unset($field);
        $packets = $this->rows('live.sdb', 'packet', 'SELECT data, usUnits, dateTime FROM packet WHERE sender = ? ORDER BY dateTime DESC LIMIT 1', [$station]);
        foreach ($packets as $packet) {
            $unitSystem = \WeewxPhp\Weewx\UnitSystem::tryFrom((int) Sqlite::text($packet['usUnits']));
            foreach (Json::object(Sqlite::text($packet['data'])) as $name => $value) {
                if (isset($known[$name]) || count($fields) >= 256 || !\WeewxPhp\Ingest\Parser::measurementKey($name)) {
                    continue;
                }
                $fields[] = ['native' => $name, 'observation' => $name, 'value' => $value,
                    'unit' => $unitSystem === null ? null : \WeewxPhp\Weewx\Units::unitOf($unitSystem, $name, \WeewxPhp\Measurement\Catalog::groups($this->config->measurements))[0], 'last_seen' => $packet['dateTime']];
            }
        }
        return $fields;
    }

    public function primary(ArchiveConfig $archive): ?string
    {
        if ($archive->primary !== null) {
            return $archive->primary;
        }
        foreach ($this->rows('live.sdb', 'sender_identity', 'SELECT sender FROM sender_identity ORDER BY first_seen, sender') as $row) {
            $sender = Sqlite::text($row['sender']);
            if ($archive->selects($sender)) {
                return $sender;
            }
        }
        return null;
    }

    /** @return list<array{boundary: int, file: ConfFile}> */
    public function mappingRevisions(string $archive): array
    {
        if (!isset($this->revisions[$archive])) {
            $this->revisions[$archive] = [];
            foreach ($this->rows('state.sdb', 'archive_revision', 'SELECT boundary, config FROM archive_revision WHERE archive = ? ORDER BY boundary', [$archive]) as $row) {
                $this->revisions[$archive][] = ['boundary' => (int) Sqlite::text($row['boundary']), 'file' => ConfFile::parse(Sqlite::text($row['config']))];
            }
        }
        return $this->revisions[$archive];
    }

    /**
     * @return array{schema: Schema, first: ?int, last: ?int, units: ?int} */
    public static function archive(ArchiveConfig $archive): array
    {
        $db = Sqlite::readOnly($archive->database);
        try {
            $schema = Schema::read($db);
            foreach (['dateTime', 'usUnits', 'interval'] as $name) {
                if (!$schema->hasColumn($name)) {
                    throw new Problem('error.archive');
                }
            }
            $first = $db->one('SELECT dateTime, usUnits FROM archive ORDER BY dateTime ASC LIMIT 1');
            $last = $db->scalar('SELECT dateTime FROM archive ORDER BY dateTime DESC LIMIT 1');
            return ['schema' => $schema, 'first' => isset($first['dateTime']) && is_int($first['dateTime']) ? $first['dateTime'] : null,
                'last' => is_int($last) ? $last : null, 'units' => isset($first['usUnits']) && is_int($first['usUnits']) ? $first['usUnits'] : null];
        } finally {
            $db->close();
        }
    }

    /** Detect storage units once when connecting a path already confined by DatabasePath. */
    public static function detectArchiveUnits(string $path): UnitSystem
    {
        $db = Sqlite::readOnly($path);
        try {
            if (!Schema::read($db)->hasColumn('usUnits')) {
                throw new Problem('error.archive', 'database');
            }
            // A single first record cannot reveal a database with mixed storage units.
            $units = iterator_to_array($db->query('SELECT DISTINCT usUnits FROM archive LIMIT 2'), false);
            if ($units === []) {
                throw new Problem('error.archive_units_empty', 'database');
            }
            if (count($units) !== 1) {
                throw new Problem('error.archive_units_mixed', 'database');
            }
            $value = $units[0]['usUnits'];
            $system = is_int($value) ? UnitSystem::tryFrom($value) : null;
            return $system ?? throw new Problem('error.archive_units_unknown', 'database');
        } finally {
            $db->close();
        }
    }

    /** @param list<int|string> $params
     * @return list<array<string, mixed>> */
    public function rows(string $file, string $table, string $sql, array $params = []): array
    {
        $path = $this->config->settings->dataDir . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $db = Sqlite::readOnly($path);
        try {
            return in_array($table, $db->tables(), true) ? iterator_to_array($db->query($sql, $params), false) : [];
        } finally {
            $db->close();
        }
    }
}
