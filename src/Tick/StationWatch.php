<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use WeewxPhp\Config\StationConfig;
use WeewxPhp\State\StationStatus;

/**
 * How a station's silence is judged: against the interval it announced,
 * in multiples the configuration names. A station that announced no
 * interval is never stale, and one never heard from is not silent but
 * unknown -- a console nobody has connected yet.
 */
final class StationWatch
{
    private function __construct() {}

    public static function judge(StationConfig $station, ?int $lastSeen, int $now): StationStatus
    {
        if ($lastSeen === null) {
            return StationStatus::Unknown;
        }
        if ($station->expectedInterval === null) {
            return StationStatus::Ok;
        }
        $silence = $now - $lastSeen;
        if ($silence > $station->expectedInterval * $station->downAfter) {
            return StationStatus::Down;
        }
        if ($silence > $station->expectedInterval * $station->staleAfter) {
            return StationStatus::Stale;
        }
        return StationStatus::Ok;
    }
}
