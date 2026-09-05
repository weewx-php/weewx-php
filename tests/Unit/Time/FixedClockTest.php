<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Time;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Time\FixedClock;

final class FixedClockTest extends TestCase
{
    public function testStandsStillUntilAdvanced(): void
    {
        $clock = new FixedClock(1_700_000_000);

        self::assertSame(1_700_000_000, $clock->now());
        self::assertSame(0.0, $clock->monotonic());

        $clock->advance(2.5);

        self::assertSame(1_700_000_002, $clock->now());
        self::assertSame(2.5, $clock->monotonic());
    }
}
