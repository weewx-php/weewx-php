<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\Intervals;

final class StartOfDayTest extends TestCase
{
    public function testMidnightBeginsTheDayWhereTheArchiveDayEndsIt(): void
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $midnight = (new DateTimeImmutable('2026-08-26 00:00:00', $berlin))->getTimestamp();

        self::assertSame($midnight, Intervals::startOfDay($midnight, $berlin));
        self::assertSame($midnight - 86400, Intervals::startOfArchiveDay($midnight, $berlin));
        self::assertSame($midnight, Intervals::startOfDay($midnight + 1, $berlin));
        self::assertSame($midnight, Intervals::startOfDay($midnight + 86399, $berlin));
        self::assertSame($midnight - 86400, Intervals::startOfDay($midnight - 1, $berlin));
    }
}
