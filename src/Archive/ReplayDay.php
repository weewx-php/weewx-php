<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Db\Json;
use WeewxPhp\Live\Packet;
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\AccumError;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

/** Checkpoint of a partially repaired day's summaries, never a transport format. */
final class ReplayDay
{
    public static function restore(?string $snapshot, int $start, int $stop, ?UnitSystem $units, Policy $policy): Accum
    {
        $day = new Accum($start, $stop, $units, $policy);
        if ($snapshot === null) {
            return $day;
        }
        $root = Json::object($snapshot);
        if (($root['start'] ?? null) !== $start || ($root['stop'] ?? null) !== $stop || ($root['units'] ?? null) !== $units?->value) {
            throw new AccumError('Replay checkpoint no longer matches the archive');
        }
        $stats = $root['stats'] ?? null;
        if (!is_array($stats)) {
            throw new AccumError('Invalid replay statistics');
        }
        foreach ($stats as $type => $tuple) {
            if (!is_string($type) || !is_array($tuple) || !array_is_list($tuple)) {
                throw new AccumError('Invalid replay statistic');
            }
            $values = [];
            foreach ($tuple as $value) {
                if ($value !== null && !is_int($value) && !is_float($value)) {
                    throw new AccumError('Invalid replay value');
                }
                $values[] = $value;
            }
            $day->setStats($type, $values);
        }
        return $day;
    }

    public static function snapshot(Accum $day): string
    {
        $stats = [];
        foreach ($day->types() as $type) {
            $stats[$type] = $day->get($type)->statsTuple();
        }
        return Packet::canonical(['start' => $day->start(), 'stop' => $day->stop(), 'units' => $day->unitSystem()?->value, 'stats' => $stats]);
    }
}
