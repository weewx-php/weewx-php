<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\UnitPreferences;
use WeewxPhp\Frontend\Value;

final class UnitPreferencesTest extends TestCase
{
    public function testSelectionRejectsUntrustedShapesAndCanReturnToStationDefault(): void
    {
        foreach ([null, [], ['us'], "us\r\nX-Test: bad", '../../us', 'US'] as $invalid) {
            $units = UnitPreferences::resolve(['units' => $invalid], [UnitPreferences::COOKIE => 'metricwx'], 'us');
            self::assertSame('metricwx', $units->profile);
            self::assertSame('us', UnitPreferences::resolve(['units' => $invalid], [UnitPreferences::COOKIE => $invalid], 'us')->profile);
        }
        self::assertSame('us', UnitPreferences::resolve(['units' => 'us'], [UnitPreferences::COOKIE => 'metric'])->profile);
        $station = UnitPreferences::resolve(['units' => 'station'], [UnitPreferences::COOKIE => 'us']);
        self::assertSame('metric', $station->profile);
        self::assertSame('station', $station->selection);
    }

    public function testProfilesConvertMixedReportsAndHardwareSpansWithoutChangingTheirSource(): void
    {
        $point = ['start' => 1, 'end' => 2, 'value' => 0.0, 'coverage' => 1.0];
        $series = new Series([$point, array_replace($point, ['value' => null])], 'degree_C', 'group_temperature', fallback: [$point]);
        $report = new Report($series, [
            'temperature' => new Value(0, 'degree_C', 'group_temperature'),
            'difference' => new Value(10, 'degree_C', 'group_temperature', delta: true),
            'rain' => new Value(25.4, 'mm', 'group_rain'),
            'humidity' => new Value(0, 'percent', 'group_percent'),
        ], []);
        $output = (new UnitPreferences('us'))->output(new Output('de'));
        $converted = $output->apply($report);
        self::assertInstanceOf(Report::class, $converted);
        self::assertEqualsWithDelta(32, $converted->value('temperature')->raw, 0.00001);
        self::assertEqualsWithDelta(18, $converted->value('difference')->raw, 0.00001);
        self::assertSame('1,00 in', $converted->value('rain')->format());
        self::assertSame(0, $converted->value('humidity')->raw);
        self::assertNull($converted->periods->points[1]['value']);
        self::assertEqualsWithDelta(32, $converted->periods->fallback[0]['value'], 0.00001);
        self::assertSame('°F', $converted->periods->jsonSerialize()['unitLabel']);
        self::assertSame(2, $converted->value('rain')->jsonSerialize()['decimals']);
        self::assertSame('degree_C', $report->periods->unit);
        self::assertSame(0.0, $report->periods->points[0]['value']);
        self::assertSame(25.4, $report->value('rain')->raw);
    }

    public function testMetricDisplayUsesMillimetresAndWindProfileIsIndependentOfLanguage(): void
    {
        $rain = new Value(1, 'inch', 'group_rain');
        $wind = new Value(36, 'km_per_hour', 'group_speed');
        $metric = (new UnitPreferences())->output();
        $convertedRain = $metric->apply($rain);
        $convertedWind = $metric->apply($wind);
        self::assertInstanceOf(Value::class, $convertedRain);
        self::assertInstanceOf(Value::class, $convertedWind);
        self::assertSame('25.4 mm', $convertedRain->format());
        self::assertSame('36.0 km/h', $convertedWind->format());
        $mps = (new UnitPreferences('metricwx'))->output(new Output('de'))->apply($wind);
        self::assertInstanceOf(Value::class, $mps);
        self::assertSame('10,0 m/s', $mps->format());
        $pressure = (new UnitPreferences('us'))->output()->apply(new Value(29.92126, 'inHg', 'group_pressure'));
        self::assertInstanceOf(Value::class, $pressure);
        self::assertSame('29.92 inHg', $pressure->format());
    }

    public function testInvalidStationDefaultIsRejectedEvenWithAVisitorOverride(): void
    {
        $this->expectException(\WeewxPhp\Frontend\QueryError::class);
        UnitPreferences::resolve(['units' => 'us'], default: 'unknown');
    }
}
