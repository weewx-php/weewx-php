<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\Wview;

/** Original intervals are indivisible. Each observation owns its own coverage. */
final class Hardware
{
    public const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS weewx_hardware (
            field TEXT NOT NULL, source TEXT NOT NULL, start INTEGER NOT NULL,
            stop INTEGER NOT NULL, day INTEGER NOT NULL, usUnits INTEGER NOT NULL,
            value REAL NOT NULL, record TEXT NOT NULL,
            PRIMARY KEY (field, source, start, stop)
        );
        CREATE INDEX IF NOT EXISTS weewx_hardware_time ON weewx_hardware(field, stop);
        CREATE INDEX IF NOT EXISTS weewx_hardware_day ON weewx_hardware(field, day);
        CREATE INDEX IF NOT EXISTS weewx_hardware_stop ON weewx_hardware(stop);
        CREATE INDEX IF NOT EXISTS weewx_hardware_source ON weewx_hardware(source, start, stop);
        SQL;

    /** Conflicting overlaps are retained but not combined. Identical spans have a stable winner. */
    public static function unambiguous(string $alias = 'h'): string
    {
        return "NOT EXISTS (SELECT 1 FROM weewx_hardware conflict WHERE conflict.field = {$alias}.field
            AND conflict.stop > {$alias}.start AND conflict.stop <= {$alias}.stop + 86400
            AND conflict.start < {$alias}.stop
            AND (conflict.start != {$alias}.start OR conflict.stop != {$alias}.stop OR conflict.source < {$alias}.source))";
    }

    /**
     * Only a complete, non-overlapping tiling may replace a target archive field.
     * @param list<array{source: string, start: int, stop: int, record: array<string, mixed>}> $inputs
     * @return array<string, mixed>
     */
    public static function aggregate(int $start, int $stop, array $inputs, Policy $policy): array
    {
        $fields = [];
        foreach ($inputs as $input) {
            if ($input['start'] < $start || $input['stop'] > $stop || $input['start'] >= $input['stop']) {
                continue;
            }
            foreach ($input['record'] as $field => $value) {
                if (in_array($field, Wview::NOT_OBSERVATIONS, true) || (!is_int($value) && !is_float($value))) {
                    continue;
                }
                $key = $input['source'] . ':' . $input['start'] . ':' . $input['stop'];
                $fields[$field][$key] = $input;
            }
        }
        $result = [];
        foreach ($fields as $field => $values) {
            $rows = array_values($values);
            usort($rows, static fn(array $a, array $b): int => [$a['start'], $a['stop'], $a['source']] <=> [$b['start'], $b['stop'], $b['source']]);
            $cursor = $start;
            $sum = $weighted = 0.0;
            $numbers = [];
            foreach ($rows as $row) {
                if ($row['start'] !== $cursor) {
                    continue 2;
                }
                $cursor = $row['stop'];
                $raw = $row['record'][$field];
                if (!is_int($raw) && !is_float($raw)) {
                    continue 2;
                }
                $value = (float) $raw;
                $numbers[] = $value;
                $sum += $value;
                $weighted += $value * ($row['stop'] - $row['start']);
            }
            if ($cursor !== $stop) {
                continue;
            }
            if (count($rows) === 1) {
                $result[$field] = $rows[0]['record'][$field];
                continue;
            }
            $extractor = match ($field) {
                'windSpeed' => Extractor::Avg, 'windGust' => Extractor::Max,
                default => $policy->of($field)->extractor,
            };
            if ($field === 'windDir') {
                $x = $y = 0.0;
                foreach ($rows as $row) {
                    $speed = $row['record']['windSpeed'] ?? null;
                    if (!is_int($speed) && !is_float($speed)) {
                        continue 2;
                    }
                    $weight = $speed * ($row['stop'] - $row['start']);
                    $direction = $row['record'][$field];
                    if (!is_int($direction) && !is_float($direction)) {
                        continue 2;
                    }
                    $x += $weight * sin(deg2rad($direction));
                    $y += $weight * cos(deg2rad($direction));
                }
                $result[$field] = hypot($x, $y) < 1e-10 ? null : fmod(rad2deg(atan2($x, $y)) + 360, 360);
            } elseif ($field === 'windGustDir') {
                $gust = null;
                foreach ($rows as $row) {
                    $speed = $row['record']['windGust'] ?? null;
                    if ((is_int($speed) || is_float($speed)) && ($gust === null || $speed > $gust)) {
                        $gust = $speed;
                        $result[$field] = $row['record'][$field];
                    }
                }
            } else {
                $value = match ($extractor) {
                    Extractor::Avg => $weighted / ($stop - $start),
                    Extractor::Sum, Extractor::Count => $sum,
                    Extractor::First => $numbers[0], Extractor::Last => $numbers[count($numbers) - 1],
                    Extractor::Min => min($numbers), Extractor::Max => max($numbers),
                    default => null,
                };
                if ($value !== null) {
                    $result[$field] = $value;
                }
            }
        }
        return $result;
    }
}
