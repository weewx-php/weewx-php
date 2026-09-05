<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

final class ReadingsTest extends TestCase
{
    public function testReadsInWhateverUnitAServiceWants(): void
    {
        $readings = new Readings(['dateTime' => 1_787_734_200, 'usUnits' => 17, 'outTemp' => 20.0, 'barometer' => 1013.25, 'windSpeed' => 5, 'outHumidity' => 61, 'model' => 'x']);

        self::assertSame(1_787_734_200, $readings->timestamp());
        self::assertSame(UnitSystem::METRICWX, $readings->system());
        self::assertSame(20.0, $readings->get('outTemp'));
        self::assertSame(Units::cToF(20.0), $readings->get('outTemp', 'degree_F'));
        self::assertSame(Units::convert(5.0, 'meter_per_second', 'mile_per_hour'), $readings->get('windSpeed', 'mile_per_hour'));
        self::assertSame(Units::convert(1013.25, 'mbar', 'inHg'), $readings->in('barometer', UnitSystem::US));
        self::assertSame(61.0, $readings->in('outHumidity', UnitSystem::US));
        self::assertTrue($readings->has('windSpeed'));
        self::assertFalse($readings->has('model'));
        self::assertFalse($readings->has('rain'));
        self::assertNull($readings->get('rain', 'inch'));
        self::assertNull($readings->get('model'));
    }

    public function testWritesTheWidthsTheProtocolsDefine(): void
    {
        $readings = new Readings(['usUnits' => 1, 'outHumidity' => 61.0, 'windSpeed' => 3.1, 'windDir' => 7.0, 'rain' => 0.125, 'outTemp' => 68.35]);

        self::assertSame('061', $readings->text('outHumidity', 'percent', '%03.0f'));
        self::assertSame('3.1', $readings->text('windSpeed', 'mile_per_hour', '%03.1f'));
        self::assertSame('007', $readings->text('windDir', 'degree_compass', '%03.0f'));
        // Ties go to even on the value itself, as C and Python have it.
        self::assertSame('0.12', $readings->text('rain', 'inch', '%.2f'));
        self::assertSame('68.3', $readings->text('outTemp', 'degree_F', '%.1f'));
        self::assertNull($readings->text('dewpoint', 'degree_F', '%.1f'));
    }

    public function testAnUnknownSystemReadsAsUs(): void
    {
        $readings = new Readings(['outTemp' => 50.0]);
        self::assertSame(UnitSystem::US, $readings->system());
        self::assertSame(10.0, $readings->get('outTemp', 'degree_C'));
        self::assertSame(0, $readings->timestamp());
    }
}
