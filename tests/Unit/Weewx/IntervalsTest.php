<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\Intervals;

final class IntervalsTest extends TestCase
{
    public function testABoundaryBelongsToTheIntervalThatEndsThere(): void
    {
        self::assertSame(1200, Intervals::stop(1200, 300));
        self::assertSame(1500, Intervals::stop(1201, 300));
        self::assertSame(1200, Intervals::stop(1199, 300));
        self::assertSame(900, Intervals::start(1200, 300));
        self::assertSame(1200, Intervals::start(1201, 300));
    }

    public function testMidnightClosesThePreviousDay(): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $midnight = self::local('2026-05-14 00:00:00', $berlin);

        self::assertSame(self::local('2026-05-13 00:00:00', $berlin), Intervals::startOfArchiveDay($midnight, $berlin));
        self::assertSame($midnight, Intervals::startOfArchiveDay($midnight + 1, $berlin));
        self::assertSame($midnight, Intervals::startOfArchiveDay(self::local('2026-05-14 23:59:59', $berlin), $berlin));
    }

    public function testDaysAroundTheClockChangeAre23And25HoursLong(): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $spring = self::local('2026-03-29 00:00:00', $berlin);
        $autumn = self::local('2026-10-25 00:00:00', $berlin);

        self::assertSame(23 * 3600, Intervals::endOfDay($spring, $berlin) - $spring);
        self::assertSame(25 * 3600, Intervals::endOfDay($autumn, $berlin) - $autumn);
        // A record in the extra hour still belongs to the long day.
        self::assertSame($autumn, Intervals::startOfArchiveDay($autumn + 24 * 3600 + 1800, $berlin));
    }

    public function testTheZoneDecidesTheDay(): void
    {
        $utc = new DateTimeZone('UTC');
        $berlin = new DateTimeZone('Europe/Berlin');
        $timestamp = self::local('2026-05-14 01:30:00', $berlin);

        self::assertSame(self::local('2026-05-14 00:00:00', $berlin), Intervals::startOfArchiveDay($timestamp, $berlin));
        self::assertSame(self::local('2026-05-13 00:00:00', $utc), Intervals::startOfArchiveDay($timestamp, $utc));
    }

    private static function local(string $text, DateTimeZone $zone): int
    {
        return (new DateTimeImmutable($text, $zone))->getTimestamp();
    }
}
