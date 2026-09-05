<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Generator;
use WeewxPhp\Archive\Hardware;
use WeewxPhp\Db\Json;

/** Bounded, per-observation selection of indivisible intervals. No interpolation. */
final class HardwareHistory
{
    public function __construct(private readonly ArchiveReader $reader) {}

    public static function field(string $observation): string
    {
        return match ($observation) {
            'wind', 'windvec' => 'windSpeed', 'windgustvec' => 'windGust',
            'heatdeg', 'cooldeg', 'growdeg' => 'outTemp', default => $observation,
        };
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(string $observation, int $start, int $end, int $after, ReadBudget $budget): Generator
    {
        $field = self::field($observation);
        $column = in_array($field, $this->reader->columns, true) ? 'a.' . Catalog::identifier($field) : 'NULL';
        $extra = [];
        foreach (['windSpeed', 'windDir', 'windGust', 'windGustDir'] as $name) {
            $extra[] = (in_array($name, $this->reader->columns, true) ? 'a.' . Catalog::identifier($name) : 'NULL') . ' AS ' . Catalog::identifier($name);
        }
        $valid = Hardware::unambiguous();
        $sql = "SELECT h.stop AS dateTime, (h.stop-h.start)/60.0 AS interval, h.usUnits, h.start,
                h.value, h.record, 'hardware' AS source, NULL AS windSpeed, NULL AS windDir, NULL AS windGust, NULL AS windGustDir
            FROM weewx_hardware h WHERE h.field = ? AND h.stop > ? AND h.stop <= ? AND h.start >= ? AND $valid
            UNION ALL
            SELECT a.dateTime, a.interval, a.usUnits, a.dateTime-CAST(ROUND(a.interval*60) AS INTEGER) AS start,
                $column AS value, NULL AS record, 'archive' AS source, " . implode(', ', $extra) . "
            FROM archive a WHERE a.dateTime > ? AND a.dateTime <= ?
                AND a.dateTime-ROUND(a.interval*60) >= ? AND $column IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM weewx_hardware h WHERE h.field = ?
                    AND h.stop > a.dateTime-ROUND(a.interval*60) AND h.stop <= a.dateTime+86400
                    AND h.start < a.dateTime AND h.start >= ? AND h.stop <= ? AND $valid)
            ORDER BY dateTime LIMIT 512";
        foreach ($this->reader->rows($sql, [$field, max($start, $after), $end, $start, max($start, $after), $end, $start, $field, $start, $end], $budget) as $row) {
            $record = is_string($row['record']) ? Json::object($row['record']) : $row;
            $record[$field] = $row['value'];
            $record['_source'] = $row['source'];
            $record['_start'] = $row['start'];
            $record['_durationWeighted'] = true;
            yield $record;
        }
    }

    /** Daily candidates include days for which the fixed archive has no records at all.
     * @return Generator<int, array<string, mixed>>
     */
    public function days(string $observation, string $table, int $after, int $end, ReadBudget $budget): Generator
    {
        $sql = "SELECT dateTime FROM $table WHERE dateTime > ? AND dateTime < ?
            UNION SELECT day AS dateTime FROM weewx_hardware WHERE field = ? AND day > ? AND day < ?
            ORDER BY dateTime LIMIT 512";
        yield from $this->reader->rows($sql, [$after, $end, self::field($observation), $after, $end], $budget);
    }

    /** Whole logger spans available beyond the requested grid, never allocated to a bucket.
     * @return Generator<int, array<string, mixed>>
     */
    public function fallback(string $observation, int $start, int $end, int $after, ReadBudget $budget): Generator
    {
        $sql = 'SELECT h.* FROM weewx_hardware h WHERE h.field = ? AND h.stop > ? AND h.stop <= ? AND h.start < ? AND '
            . Hardware::unambiguous() . ' ORDER BY h.stop LIMIT 512';
        yield from $this->reader->rows($sql, [self::field($observation), max($start, $after), $end + 86400, $end], $budget);
    }
}
