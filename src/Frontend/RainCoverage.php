<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** Prove a dry interval from unchanged cumulative readings on both sides of it. */
final class RainCoverage
{
    public static function uncertain(ArchiveReader $reader, Span $span, ReadBudget $budget): bool
    {
        if (!$reader->rainEvidence) {
            return false;
        }
        return $reader->one(
            "SELECT 1 FROM weewx_rain_evidence WHERE stop > ? AND start < ? AND (status <> 'complete' OR (amount > 0 AND (start < ? OR stop > ?))) LIMIT 1",
            [$span->start, $span->end, $span->start, $span->end],
            $budget,
        ) !== null;
    }

    public static function dry(ArchiveReader $reader, Span $span, ReadBudget $budget, ?int &$coveredThrough = null): bool
    {
        if ($span->length() <= 0) {
            return false;
        }
        // Imported logger intervals can overlap this day without fitting its grid.
        // Their known rain must never be erased by a different counter's zero proof.
        if ($reader->hardware && $reader->one(
            "SELECT 1 FROM weewx_hardware WHERE field = 'rain' AND stop > ? AND stop <= ? AND start < ? AND value > 0 LIMIT 1",
            [$span->start, $span->end + 86400, $span->end],
            $budget,
        ) !== null) {
            return false;
        }
        if ($coveredThrough === null) {
            // One unchanged counter can establish even an entirely missing day.
            if (self::counterCovers($reader, $span, $budget)) {
                return true;
            }
            $coveredThrough = $span->start;
        }
        while ($coveredThrough < $span->end) {
            // A reset outside a measurement gap must not disqualify the whole day.
            // Materialize a bounded page before opening the counter queries.
            $gaps = self::gaps($reader, new Span($coveredThrough, $span->end), $budget);
            if ($gaps === []) {
                return true;
            }
            foreach ($gaps as $gap) {
                if (!self::counterCovers($reader, $gap, $budget)) {
                    return false;
                }
                // Persist progress if the next gap exhausts this worker's read budget.
                $coveredThrough = $gap->end;
            }
        }
        return true;
    }

    /** @return list<Span> */
    private static function gaps(ArchiveReader $reader, Span $span, ReadBudget $budget): array
    {
        $ranges = 'SELECT MAX(?, dateTime - CAST(interval * 60 AS INTEGER)) AS start, dateTime AS stop FROM archive WHERE dateTime > ? AND dateTime <= ? AND rain = 0 AND interval > 0';
        $params = [$span->start, $span->start, $span->end];
        if ($reader->rainEvidence) {
            $ranges .= " UNION ALL SELECT MAX(?, start), MIN(?, stop) FROM weewx_rain_evidence WHERE stop > ? AND start < ? AND status = 'complete' AND amount = 0";
            array_push($params, $span->start, $span->end, $span->start, $span->end);
        }
        // The sentinel exposes a missing tail, including an entirely unmeasured day.
        $ranges .= ' UNION ALL SELECT ?, ?';
        array_push($params, $span->end, $span->end, $span->start);
        $sql = 'WITH ranges AS (' . $ranges . '), coverage AS (
            SELECT start, COALESCE(MAX(stop) OVER (ORDER BY start, stop ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), ?) AS previous FROM ranges
        ) SELECT previous, start FROM coverage WHERE start > previous ORDER BY start LIMIT 32';
        $gaps = [];
        foreach ($reader->rows($sql, $params, $budget) as $row) {
            $gaps[] = new Span(Cache::integer($row['previous']), Cache::integer($row['start']));
        }
        return $gaps;
    }

    private static function counterCovers(ArchiveReader $reader, Span $span, ReadBudget $budget): bool
    {
        foreach (['totalRain' => 'Y', 'yearRain' => 'Y', 'monthRain' => 'Y-m', 'dayRain' => 'Y-m-d'] as $field => $reset) {
            if (!in_array($field, $reader->columns, true)) {
                continue;
            }
            // Indexed and bounded: do not turn a sparse ancient series into a table scan.
            $before = $reader->one('SELECT dateTime, usUnits, "' . $field . '" AS total FROM archive WHERE dateTime BETWEEN ? AND ? AND "' . $field . '" IS NOT NULL ORDER BY dateTime DESC LIMIT 1', [$span->start - 2 * 86400, $span->start], $budget);
            $after = $reader->one('SELECT dateTime, usUnits, "' . $field . '" AS total FROM archive WHERE dateTime BETWEEN ? AND ? AND "' . $field . '" IS NOT NULL ORDER BY dateTime LIMIT 1', [$span->end, $span->end + 2 * 86400], $budget);
            if ($before === null || $after === null) {
                continue;
            }
            $first = Cache::integer($before['dateTime']);
            $last = Cache::integer($after['dateTime']);
            // Archive rows represent intervals ending at dateTime. Never bridge a known reset.
            if (Span::date($first - 1, $reader->config->timezone)->format($reset)
                !== Span::date($last - 1, $reader->config->timezone)->format($reset)) {
                continue;
            }
            $a = self::millimeters($before);
            $b = self::millimeters($after);
            if ($a === null || $b === null || abs($a - $b) > 1e-9) {
                continue;
            }
            // A visible reset or changing counter inside the bracket invalidates the proof.
            $range = $reader->one('SELECT MIN("' . $field . '") AS low, MAX("' . $field . '") AS high, MIN(usUnits) AS firstUnit, MAX(usUnits) AS lastUnit, MAX(rain) AS maxRain FROM archive WHERE dateTime BETWEEN ? AND ?', [$first, $last], $budget);
            if ($range === null || $range['firstUnit'] !== $range['lastUnit'] || $range['low'] !== $range['high'] || (Accumulator::number($range['maxRain']) ?? 0) > 0) {
                continue;
            }
            $budget->source('rain_counter');
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private static function millimeters(array $row): ?float
    {
        $value = Accumulator::number($row['total'] ?? null);
        $system = UnitSystem::tryFrom(Cache::integer($row['usUnits'] ?? null));
        if ($value === null || $value < 0 || $system === null) {
            return null;
        }
        [$unit] = Units::unitOf($system, 'rain');
        if ($unit === null) {
            return null;
        }
        return (float) Units::convert($value, $unit, 'mm');
    }
}
