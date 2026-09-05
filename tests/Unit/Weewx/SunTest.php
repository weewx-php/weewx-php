<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\Sun;

/**
 * The shape of the sky. The numbers are compared against pyephem by the
 * conformance check `sun`.
 */
final class SunTest extends TestCase
{
    public function testTheSolsticesAndEquinoxes(): void
    {
        [$june] = Sun::solar(self::utc('2026-06-21 08:24:00'));
        [$december] = Sun::solar(self::utc('2026-12-21 20:50:00'));
        [$march] = Sun::solar(self::utc('2026-03-20 14:46:00'));

        self::assertEqualsWithDelta(23.44, $june, 0.01);
        self::assertEqualsWithDelta(-23.44, $december, 0.01);
        self::assertEqualsWithDelta(0.0, $march, 0.02);
    }

    public function testNoonAndMidnightInKirchdorf(): void
    {
        $latitude = 48.4596;
        $longitude = 11.6539;

        [$noon, $distance] = Sun::position(self::utc('2026-06-21 11:15:00'), $latitude, $longitude);
        [$midnight] = Sun::position(self::utc('2026-06-21 23:15:00'), $latitude, $longitude);

        self::assertEqualsWithDelta(90.0 - $latitude + 23.44, $noon, 0.1);
        self::assertLessThan(-15.0, $midnight);
        self::assertEqualsWithDelta(1.016, $distance, 0.001);
    }

    private static function utc(string $text): int
    {
        return (new DateTimeImmutable($text, new DateTimeZone('UTC')))->getTimestamp();
    }
}
