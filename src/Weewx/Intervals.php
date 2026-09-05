<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Where an archive interval and an archive day begin and end.
 *
 * Both are half-open at the start: a timestamp exactly on a boundary belongs
 * to the span that ends there. WeeWX has always done it that way, and the
 * daily summaries are keyed on the result.
 */
final class Intervals
{
    private function __construct() {}

    /**
     * The end of the archive interval a timestamp belongs to: weewx-evo's
     * `interval_stop`, the same boundary WeeWX's `startOfInterval` plus the
     * interval gives.
     */
    public static function stop(int $timestamp, int $seconds): int
    {
        return (intdiv($timestamp - 1, $seconds) + 1) * $seconds;
    }

    /** The start of the archive interval a timestamp belongs to: WeeWX's `startOfInterval`. */
    public static function start(int $timestamp, int $seconds): int
    {
        return self::stop($timestamp, $seconds) - $seconds;
    }

    /**
     * The start of the local day an archive record belongs to: WeeWX's
     * `startOfArchiveDay`. A record stamped exactly at midnight closes the
     * previous day.
     */
    public static function startOfArchiveDay(int $timestamp, DateTimeZone $zone): int
    {
        $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
        $midnight = $local->setTime(0, 0, 0);
        if ($midnight->getTimestamp() === $timestamp) {
            $midnight = $midnight->modify('-1 day');
        }
        return $midnight->getTimestamp();
    }

    /**
     * The start of the local day a moment falls in: WeeWX's `startOfDay`,
     * where midnight begins the new day. Not {@see startOfArchiveDay()},
     * which hands midnight to the day that just ended; the two differ for
     * exactly that one second, and a daily rain total posted to Weather
     * Underground counts the midnight record by this one.
     */
    public static function startOfDay(int $timestamp, DateTimeZone $zone): int
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->setTime(0, 0, 0)->getTimestamp();
    }

    /**
     * The end of the local day that starts at a midnight: the next midnight,
     * which is 23 or 25 hours away on the days the clocks change. WeeWX's
     * `daySpan`, which walks a calendar day rather than adding 86400.
     */
    public static function endOfDay(int $startOfDay, DateTimeZone $zone): int
    {
        return (new DateTimeImmutable('@' . $startOfDay))->setTimezone($zone)->modify('+1 day')->getTimestamp();
    }
}
