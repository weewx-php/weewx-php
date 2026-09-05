<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use DateTimeImmutable;
use DateTimeZone;

/**
 * When something on its own rhythm next runs: on the hour's grid, not
 * counted from whenever it last happened to run. Ten minutes means :00,
 * :10, :20 and stays there across restarts and missed ticks, so a report
 * every ten minutes lands on the minute CWOP expects.
 *
 * The local hour, not the epoch: where the clock on the wall is half an
 * hour out of step with UTC, a two-hourly run would otherwise land on
 * odd-numbered half hours. An interval that does not divide the hour gets
 * a short slot before the hour rather than drifting into the next one.
 * weewx-evo's `schedule.py`, with the zone made explicit.
 */
final class Schedule
{
    private const HOUR = 3600;

    private function __construct() {}

    /** Seconds into the local hour a moment is. */
    public static function intoHour(int $now, DateTimeZone $zone): int
    {
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        return (int) $local->format('i') * 60 + (int) $local->format('s');
    }

    /**
     * The next moment on the grid `every` makes, counted from the local
     * hour. Always after `now`: a caller asking at exactly 14:10:00 has
     * just run and wants the one after.
     */
    public static function nextSlot(int $now, int $every, DateTimeZone $zone): int
    {
        if ($every <= 0) {
            return $now;
        }
        $into = self::intoHour($now, $zone);
        $started = $now - $into;
        if ($every >= self::HOUR) {
            // Longer than an hour: the grid is whole hours.
            return $started + self::HOUR * max(1, (int) round($every / self::HOUR));
        }
        $slot = $started + (intdiv($into, $every) + 1) * $every;
        return min($slot, $started + self::HOUR);
    }

    /** Whether a run on `every`'s grid is due: never run, or the slot after the last run has passed. */
    public static function due(int $now, ?int $lastRunAt, int $every, DateTimeZone $zone): bool
    {
        return $lastRunAt === null || self::nextSlot($lastRunAt, $every, $zone) <= $now;
    }
}
