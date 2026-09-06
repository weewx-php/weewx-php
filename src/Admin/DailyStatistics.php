<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use DateTimeZone;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\Schema;

/** Bounded sampling of completed days; timezone names sharing boundaries remain ambiguous. */
final class DailyStatistics
{
    /** @return list<int> */
    public static function samples(string $path, int $now): array
    {
        $db = Sqlite::readOnly($path);
        try {
            $schema = Schema::read($db);
            $first = $db->scalar('SELECT dateTime FROM archive ORDER BY dateTime LIMIT 1');
            $last = $db->scalar('SELECT dateTime FROM archive ORDER BY dateTime DESC LIMIT 1');
            if (!is_int($first) || !is_int($last)) {
                return [];
            }
            $names = array_keys($schema->dayTypes);
            if (in_array('outTemp', $names, true)) {
                $names = ['outTemp', ...array_diff($names, ['outTemp'])];
            }
            foreach ($names as $name) {
                $table = '"' . str_replace('"', '""', 'archive_day_' . $name) . '"';
                $samples = [];
                foreach (['ASC', 'DESC'] as $order) {
                    // Exclude the initial partial day and the latest possibly unfinished day.
                    $stamp = $db->scalar('SELECT dateTime FROM ' . $table . ' WHERE dateTime > ? AND dateTime <= ? ORDER BY dateTime ' . $order . ' LIMIT 1', [$first, min($last, $now) - 90000]);
                    if (is_int($stamp)) {
                        $samples[] = $stamp;
                    }
                }
                if ($samples !== []) {
                    return array_values(array_unique($samples));
                }
            }
            return [];
        } finally {
            $db->close();
        }
    }

    /** @param list<int> $samples
     * @return array{status: string, days: int, milliseconds: float}
     */
    public static function check(string $path, DateTimeZone $zone, array $samples): array
    {
        $started = hrtime(true);
        $status = $samples === [] ? 'unavailable' : 'match';
        $weather = ArchiveDb::readOnly($path, new Policy(), $zone);
        try {
            if ($weather->schema()->version() !== Schema::DAY_SUMMARY_VERSION && $weather->schema()->version() !== null) {
                $status = 'mismatch';
            }
            foreach ($samples as $stamp) {
                if (Intervals::startOfDay($stamp, $zone) !== $stamp) {
                    $status = 'mismatch';
                    break;
                }
                [$fresh, $count] = $weather->dayFromRecords($stamp);
                $stored = $weather->loadDay($stamp, $weather->unitSystem());
                if ($count === 0) {
                    $status = 'unavailable';
                    break;
                }
                foreach ($weather->schema()->dayTypes as $name => $kind) {
                    $expected = $fresh->get($name)->statsTuple();
                    $actual = $stored->get($name)->statsTuple();
                    foreach (Schema::dayColumns($kind) as $index => $column) {
                        // LOOP-derived extrema need not equal archive averages.
                        if (in_array($column, ['min', 'mintime', 'max', 'maxtime', 'max_dir'], true)) {
                            continue;
                        }
                        $a = $actual[$index];
                        $b = $expected[$index];
                        if (($a === null || $b === null) ? $a !== $b : abs($a - $b) > 1e-8 * max(1.0, abs($a), abs($b))) {
                            $status = 'mismatch';
                            break 3;
                        }
                    }
                }
            }
        } finally {
            $weather->close();
        }
        return ['status' => $status, 'days' => count($samples), 'milliseconds' => round((hrtime(true) - $started) / 1e6, 1)];
    }

    /** @return array{zones: list<string>, days: int, groups: int, milliseconds: float} */
    public static function detect(string $path, int $now): array
    {
        $started = hrtime(true);
        $samples = self::samples($path, $now);
        $groups = [];
        if ($samples !== []) {
            foreach (DateTimeZone::listIdentifiers() as $name) {
                $zone = new DateTimeZone($name);
                $boundaries = [];
                foreach ($samples as $stamp) {
                    if (Intervals::startOfDay($stamp, $zone) !== $stamp) {
                        continue 2;
                    }
                    $boundaries[] = Intervals::endOfDay($stamp, $zone);
                }
                $groups[implode(':', $boundaries)][] = $name;
            }
        }
        $zones = [];
        foreach ($groups as $names) {
            if (self::check($path, new DateTimeZone($names[0]), $samples)['status'] === 'match') {
                array_push($zones, ...$names);
            }
        }
        sort($zones);
        return ['zones' => $zones, 'days' => count($samples), 'groups' => count($groups), 'milliseconds' => round((hrtime(true) - $started) / 1e6, 1)];
    }

    public static function zone(string $name): DateTimeZone
    {
        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new Problem('error.input', 'timezone');
        }
        return new DateTimeZone($name);
    }
}
