<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\UnitError;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Spot checks against the examples in the docstrings of weewx.units. The
 * whole table is compared against WeeWX itself by the conformance check
 * `units`; this is what runs without WeeWX.
 */
final class UnitsTest extends TestCase
{
    public function testConvertsTheDocstringExamples(): void
    {
        self::assertEqualsWithDelta(30.017, Units::convert(1016.5, 'mbar', 'inHg'), 0.0005);
        self::assertEqualsWithDelta(1016.59, Units::convert(30.02, 'inHg', 'mbar'), 0.005);
        self::assertEqualsWithDelta(30.48, Units::convert(1.2, 'inch', 'mm'), 0.005);
        self::assertSame(68.0, Units::convert(20.0, 'degree_C', 'degree_F'));
    }

    public function testLeavesTheSameUnitAndNullAlone(): void
    {
        self::assertSame(7, Units::convert(7, 'minute', 'minute'));
        self::assertNull(Units::convert(null, 'degree_C', 'degree_F'));
        self::assertTrue(Units::canConvert('mile_per_hour', 'knot'));
        self::assertFalse(Units::canConvert('mile_per_hour', 'inch'));
    }

    public function testRefusesAPairTheTableDoesNotHold(): void
    {
        $this->expectException(UnitError::class);
        Units::convert(1.0, 'mile_per_hour', 'inch');
    }

    public function testKnowsGroupsAndStandardUnits(): void
    {
        self::assertSame('group_temperature', Units::groupOf('outTemp'));
        self::assertNull(Units::groupOf('unknownProbe'));
        self::assertSame(['inch', 'group_rain'], Units::unitOf(UnitSystem::US, 'rain'));
        self::assertSame(['cm', 'group_rain'], Units::unitOf(UnitSystem::METRIC, 'rain'));
        self::assertSame(['mm', 'group_rain'], Units::unitOf(UnitSystem::METRICWX, 'rain'));
        self::assertSame(['meter_per_second', 'group_speed'], Units::unitOf(UnitSystem::METRICWX, 'windSpeed'));
        self::assertSame(['km_per_hour', 'group_speed'], Units::unitOf(UnitSystem::METRIC, 'windSpeed'));
        self::assertSame(['meter', 'group_altitude'], Units::unitOf(UnitSystem::METRICWX, 'altitude'));
        self::assertSame([null, null], Units::unitOf(UnitSystem::US, 'unknownProbe'));
    }

    public function testConvertsARecordTheWayToStdSystemDoes(): void
    {
        $metric = ['dateTime' => 194758100, 'outTemp' => 20.0, 'usUnits' => 16, 'barometer' => 1015.9166,
            'interval' => 15, 'vpd' => 1.2, 'model' => 'HP2561AE', 'rain' => null];

        $us = Units::toSystem($metric, UnitSystem::US);

        self::assertSame(194758100, $us['dateTime']);
        self::assertSame(15, $us['interval']);
        self::assertEqualsWithDelta(68.0, $us['outTemp'], 1e-9);
        self::assertEqualsWithDelta(30.0, $us['barometer'], 0.0005);
        self::assertSame(1.2, $us['vpd']);
        self::assertSame('HP2561AE', $us['model']);
        self::assertNull($us['rain']);
        self::assertSame(1, $us['usUnits']);

        self::assertSame($metric, Units::toSystem($metric, UnitSystem::METRIC));
    }

    public function testRefusesARecordWithoutAUnitSystem(): void
    {
        $this->expectException(UnitError::class);
        Units::toSystem(['outTemp' => 20.0], UnitSystem::US);
    }
}
