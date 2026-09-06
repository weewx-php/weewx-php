<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\RainCounter;

final class RainCounterTest extends TestCase
{
    private function tracker(): RainCounter
    {
        return new RainCounter(new DateTimeZone('Europe/Berlin'));
    }

    public function testTwelveHourGapAcrossMidnightUsesTheAnnualCounter(): void
    {
        $t = strtotime('2026-09-05 18:00:00 +0200');
        $r = $this->tracker();
        self::assertNull($r->read('a', $t, ['yearRain' => 100, 'dayRain' => 4], false)['amount']);
        $after = $r->read('a', $t + 43200, ['yearRain' => 107, 'dayRain' => 5], false);
        self::assertSame(7.0, $after['amount']);
        self::assertSame('yearRain', $after['counter']);
        self::assertSame($t, $after['start']);
        self::assertSame('complete', $after['status']);
    }

    public function testMidnightCannotBeDifferencedEvenIfTheNewDailyTotalIsHigher(): void
    {
        $t = strtotime('2026-09-05 23:50:00 +0200');
        $r = $this->tracker();
        $r->read('a', $t, ['dayRain' => 1], false);
        self::assertNull($r->read('a', $t + 7200, ['dayRain' => 3], false)['amount']);
        self::assertSame(1.0, $r->read('a', $t + 7500, ['dayRain' => 4], false)['amount']);
    }

    public function testManualResetRebasesAndAnotherContinuousCounterCanBridgeIt(): void
    {
        $r = $this->tracker();
        $r->read('a', 1000, ['totalRain' => 100, 'monthRain' => 10], false);
        self::assertSame(2.0, $r->read('a', 1060, ['totalRain' => 0.2, 'monthRain' => 12], false)['amount']);
        self::assertSame('reset', $r->read('a', 1120, ['totalRain' => 0, 'monthRain' => 0], false)['status']);
        self::assertSame(0.5, $r->read('a', 1180, ['totalRain' => 0.5, 'monthRain' => 0.5], false)['amount']);
    }

    public function testFallbackAndReturningCounterNeverDoubleCount(): void
    {
        $r = $this->tracker();
        $r->read('a', 1000, ['yearRain' => 100, 'dayRain' => 1], false);
        self::assertSame(1.0, $r->read('a', 1060, ['dayRain' => 2], false)['amount']);
        self::assertSame(1.0, $r->read('a', 1120, ['yearRain' => 102, 'dayRain' => 3], false)['amount']);
        self::assertSame(1.0, $r->read('a', 1180, ['yearRain' => 103, 'dayRain' => 4], false)['amount']);
        $r->read('a', 1240, ['yearRain' => 104, 'dayRain' => 5], true);
        self::assertSame(1.0, $r->read('a', 1300, ['yearRain' => 105, 'dayRain' => 6], false)['amount']);
        self::assertNull($r->read('b', 1300, ['yearRain' => 1000], false)['amount']);
    }

    public function testConflictingAndInvalidCountersDoNotInventAnAmount(): void
    {
        $r = $this->tracker();
        $r->read('a', 1000, ['yearRain' => 100, 'dayRain' => 1], false);
        self::assertSame('conflict', $r->read('a', 1060, ['yearRain' => 100, 'dayRain' => 2], false)['status']);
        self::assertNull($r->read('a', 1120, ['yearRain' => -1, 'dayRain' => INF], false)['amount']);
        self::assertSame(1.0, $r->read('a', 1180, ['yearRain' => 101, 'dayRain' => 3], false)['amount']);
    }

    public function testRollingWindowsAndRatesAreNotCounterInputs(): void
    {
        $d = new \WeewxPhp\Archive\Derived(new \WeewxPhp\Archive\Site(null, null, null, new DateTimeZone('UTC')), new FakeHistory(null), \WeewxPhp\Archive\Derivable::policy([]));
        $d->seed(['dateTime' => 1000, 'usUnits' => 17, 'hourRain' => 10, 'rainRate' => 5], 'a');
        self::assertArrayNotHasKey('rain', $d->applyPacket(['dateTime' => 1060, 'usUnits' => 17, 'hourRain' => 11, 'rainRate' => 8], 'a'));
    }
}
