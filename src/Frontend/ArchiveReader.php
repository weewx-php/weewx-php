<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Generator;
use SQLite3;
use Throwable;
use WeewxPhp\Archive\Derivable;
use WeewxPhp\Archive\Derived;
use WeewxPhp\Archive\History;
use WeewxPhp\Archive\How;
use WeewxPhp\Archive\Site;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Weewx\UnitSystem;

/** @internal Read-only connection; indexed reads share the caller's row, statement and time budget. */
final class ArchiveReader implements History
{
    private readonly SQLite3 $db;
    /** @var list<string> */
    public readonly array $columns;
    /** @var list<string> */
    public readonly array $daily;
    public readonly ?int $first;
    public readonly ?int $last;
    public readonly UnitSystem $units;
    public readonly int $dailyThrough;
    public readonly bool $hardware;
    /** @var array<string, true> */
    private array $checkedDaily = [];

    public function __construct(public readonly ArchiveConfig $config, private readonly ReadBudget $budget)
    {
        $this->db = new SQLite3($config->database, SQLITE3_OPEN_READONLY);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(25);
        $columns = [];
        $keyed = false;
        foreach ($this->rows('PRAGMA table_info("archive")', [], $budget) as $row) {
            if (is_string($row['name'])) {
                $columns[] = $row['name'];
            }
            if ($row['name'] === 'dateTime' && $row['pk'] === 1 && is_string($row['type']) && strtoupper($row['type']) === 'INTEGER') {
                $keyed = true;
            }
        }
        if (!in_array('dateTime', $columns, true) || !in_array('usUnits', $columns, true) || !in_array('interval', $columns, true)) {
            throw new QueryError('Archive needs dateTime, usUnits and interval');
        }
        $this->columns = $columns;
        if (!$keyed) {
            throw new QueryError('Archive dateTime must be an INTEGER PRIMARY KEY for bounded reads');
        }
        $daily = [];
        $hasMetadata = false;
        $hardware = false;
        foreach ($this->rows("SELECT name FROM sqlite_master WHERE type = 'table' AND (name GLOB 'archive_day_*' OR name = 'weewx_hardware')", [], $budget) as $row) {
            if ($row['name'] === 'weewx_hardware') {
                $hardware = true;
                continue;
            }
            if ($row['name'] === 'archive_day__metadata') {
                $hasMetadata = true;
            }
            if (is_string($row['name']) && $row['name'] !== 'archive_day__metadata') {
                $daily[] = substr($row['name'], 12);
            }
        }
        $this->daily = $daily;
        $this->hardware = $hardware;
        $first = $this->one('SELECT dateTime, usUnits FROM archive ORDER BY dateTime ASC LIMIT 1', [], $budget);
        $last = $this->one('SELECT dateTime FROM archive ORDER BY dateTime DESC LIMIT 1', [], $budget);
        $archiveLast = is_int($last['dateTime'] ?? null) ? $last['dateTime'] : 0;
        if ($hardware) {
            $hwFirst = $this->one('SELECT stop AS dateTime, usUnits FROM weewx_hardware ORDER BY stop LIMIT 1', [], $budget);
            $hwLast = $this->one('SELECT stop AS dateTime FROM weewx_hardware ORDER BY stop DESC LIMIT 1', [], $budget);
            if ($hwFirst !== null && ($first === null || $hwFirst['dateTime'] < $first['dateTime'])) {
                $first = $hwFirst;
            }
            if ($hwLast !== null && ($last === null || $hwLast['dateTime'] > $last['dateTime'])) {
                $last = $hwLast;
            }
        }
        $this->first = isset($first['dateTime']) && is_int($first['dateTime']) ? $first['dateTime'] : null;
        $this->last = isset($last['dateTime']) && is_int($last['dateTime']) ? $last['dateTime'] : null;
        $unit = $first['usUnits'] ?? null;
        $this->units = is_int($unit) ? (UnitSystem::tryFrom($unit) ?? $config->unitSystem) : $config->unitSystem;
        $metadata = [];
        if ($hasMetadata) {
            foreach ($this->rows('SELECT name, value FROM archive_day__metadata LIMIT 32', [], $budget) as $row) {
                if (is_string($row['name']) && is_string($row['value'])) {
                    $metadata[$row['name']] = $row['value'];
                }
            }
        }
        $through = ($metadata['Version'] ?? null) === '4.0' && ctype_digit($metadata['lastUpdate'] ?? '') ? (int) $metadata['lastUpdate'] : 0;
        $this->dailyThrough = $hardware && $through >= $archiveLast ? max($through, $this->last ?? 0) : $through;
    }

    public function close(): void
    {
        $this->db->close();
    }

    /**
     * @param list<int|float|string|null> $params
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(string $sql, array $params, ReadBudget $budget): Generator
    {
        $budget->source(str_contains($sql, 'archive_day_') ? 'daily_summary' : (str_contains($sql, 'FROM archive ') ? 'archive' : 'schema'));
        $budget->statement();
        $statement = null;
        $result = null;
        try {
            $statement = $this->db->prepare($sql);
            if ($statement === false) {
                throw new QueryError('Cannot prepare archive query');
            }
            foreach ($params as $index => $value) {
                $statement->bindValue($index + 1, $value, match (true) {
                    is_int($value) => SQLITE3_INTEGER, is_float($value) => SQLITE3_FLOAT, $value === null => SQLITE3_NULL, default => SQLITE3_TEXT
                });
            }
            $result = $statement->execute();
            if ($result === false) {
                throw new QueryError('Cannot execute archive query');
            }
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $budget->row();
                yield $row;
            }
        } catch (Throwable $error) {
            if ($error instanceof Deferred || $this->db->lastErrorCode() === 9 || $budget->expired()) {
                throw new Deferred('Archive read budget exhausted', 0, $error);
            }
            throw $error;
        } finally {
            if ($result instanceof \SQLite3Result) {
                $result->finalize();
            }
            if ($statement instanceof \SQLite3Stmt) {
                $statement->close();
            }
        }
    }

    /**
     * @param list<int|float|string|null> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params, ReadBudget $budget): ?array
    {
        foreach ($this->rows($sql, $params, $budget) as $row) {
            return $row;
        }
        return null;
    }

    public function has(string $observation): bool
    {
        Catalog::identifier($observation);
        return in_array($observation, $this->columns, true) || Derivable::knows($observation) || in_array($observation, ['wind', 'windvec', 'windgustvec', 'heatdeg', 'cooldeg', 'growdeg'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function derive(array $row, string $observation): array
    {
        if (in_array($observation, $this->columns, true) || !Derivable::knows($observation)) {
            return $row;
        }
        if ($observation === 'rainRate') {
            $at = $row['dateTime'] ?? null;
            if (!is_int($at) || !in_array('rain', $this->columns, true)) {
                return $row;
            }
            $total = 0.0;
            $count = 0;
            foreach ($this->rows('SELECT rain FROM archive WHERE dateTime > ? AND dateTime <= ? ORDER BY dateTime LIMIT 901', [$at - 900, $at], $this->budget) as $point) {
                $rain = Accumulator::number($point['rain']);
                if ($rain !== null) {
                    $total += $rain;
                    ++$count;
                }
            }
            $row['rainRate'] = $count === 0 ? null : $total * 4;
            return $row;
        }
        $policy = [];
        foreach (Derivable::DEFAULTS as $name => $how) {
            if ($name === $observation || (in_array($observation, ['altimeter', 'barometer'], true) && $name === 'pressure')) {
                $policy[$name] = How::PreferHardware;
            }
        }
        return (new Derived(Site::of($this->config), $this, $policy))->applyRecord($row);
    }

    public function recordNear(int $timestamp, int $maxDelta): ?array
    {
        $before = $this->one('SELECT * FROM archive WHERE dateTime >= ? AND dateTime <= ? ORDER BY dateTime DESC LIMIT 1', [$timestamp - $maxDelta, $timestamp], $this->budget);
        $after = $this->one('SELECT * FROM archive WHERE dateTime > ? AND dateTime <= ? ORDER BY dateTime LIMIT 1', [$timestamp, $timestamp + $maxDelta], $this->budget);
        $b = $before['dateTime'] ?? null;
        $a = $after['dateTime'] ?? null;
        return !is_int($a) ? $before : (!is_int($b) || $a - $timestamp < $timestamp - $b ? $after : $before);
    }

    public function etWindow(int $start, int $stop): ?array
    {
        if (array_diff(['outTemp', 'outHumidity', 'radiation', 'windSpeed'], $this->columns) !== []) {
            return null;
        }
        if ($stop - $start > 3600) {
            throw new QueryError('ET window exceeds one hour');
        }
        $values = [];
        foreach ($this->rows('SELECT outTemp, outHumidity, radiation, windSpeed, usUnits FROM archive WHERE dateTime > ? AND dateTime < ? ORDER BY dateTime LIMIT 3601', [$start, $stop], $this->budget) as $row) {
            foreach ($row as $name => $raw) {
                $value = Accumulator::number($raw);
                if ($value !== null) {
                    $values[$name][] = $value;
                }
            }
        }
        $result = [];
        foreach (['t_max' => ['outTemp', 'max'], 't_min' => ['outTemp', 'min'], 'rh_max' => ['outHumidity', 'max'], 'rh_min' => ['outHumidity', 'min'], 'rad_avg' => ['radiation', 'avg'], 'wind_avg' => ['windSpeed', 'avg'], 'units_min' => ['usUnits', 'min'], 'units_max' => ['usUnits', 'max']] as $key => [$name, $op]) {
            $numbers = $values[$name] ?? [];
            $value = $numbers === [] ? null : match ($op) {
                'min' => min($numbers), 'max' => max($numbers), default => array_sum($numbers) / count($numbers)
            };
            $result[$key] = $value !== null && $name === 'usUnits' ? (int) $value : $value;
        }
        return $result;
    }

    public function dailyTable(string $observation, ReadBudget $budget): string
    {
        $name = 'archive_day_' . $observation;
        $quoted = Catalog::identifier($name);
        if (!isset($this->checkedDaily[$name])) {
            foreach ($this->rows('PRAGMA table_info(' . $quoted . ')', [], $budget) as $row) {
                if ($row['name'] === 'dateTime' && $row['pk'] === 1 && is_string($row['type']) && strtoupper($row['type']) === 'INTEGER') {
                    $this->checkedDaily[$name] = true;
                }
            }
            if (!isset($this->checkedDaily[$name])) {
                throw new QueryError('Daily summaries need an INTEGER PRIMARY KEY');
            }
        }
        return $quoted;
    }

    /** Small persistent change token, including WAL commits that do not change the main file. */
    public static function fingerprint(string $path): string
    {
        clearstatcache(true, $path);
        $parts = [];
        foreach ([$path, $path . '-wal'] as $file) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $parts[] = 'absent';
                continue;
            }
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                throw new QueryError('Cannot inspect archive change token');
            }
            try {
                $stat = fstat($stream);
                $size = $stat === false ? 0 : $stat['size'];
                $head = fread($stream, 100);
                $tail = '';
                if ($size > 100) {
                    fseek($stream, max(0, $size - 100));
                    $tail = fread($stream, 100);
                }
                $parts[] = $size . ':' . ($stat === false ? 0 : $stat['mtime']) . ':' . ($head === false ? '' : $head) . ':' . ($tail === false ? '' : $tail);
            } finally {
                fclose($stream);
            }
        }
        // WAL files can reuse preallocated frames without changing length or mtime.
        // The WAL-index header includes the commit frame and its checksums. Reader
        // marks start after these 96 bytes and must not invalidate cached results.
        $walSize = is_file($path . '-wal') ? filesize($path . '-wal') : false;
        if ($walSize !== false && $walSize > 32 && is_file($path . '-shm')) {
            $stream = fopen($path . '-shm', 'rb');
            if ($stream !== false) {
                try {
                    $header = fread($stream, 96);
                    $parts[] = $header === false ? '' : $header;
                } finally {
                    fclose($stream);
                }
            }
        }
        return hash('sha256', implode('|', $parts));
    }
}
