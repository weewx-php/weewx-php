<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Derivable;
use WeewxPhp\Archive\Derived;
use WeewxPhp\Archive\History;
use WeewxPhp\Archive\How;
use WeewxPhp\Archive\Site;
use WeewxPhp\Config\Altitude;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Weewx\Formulas;
use WeewxPhp\Weewx\Units;

/**
 * The plumbing around the formulas: what decides a reading, what is
 * remembered between packets, and what a record gets that a packet does
 * not. The values themselves are checked against WeeWX by the conformance
 * run.
 */
final class DerivedTest extends TestCase
{
    private const T0 = 1_787_734_200;

    public function testTheStationsValueWinsUnlessToldOtherwise(): void
    {
        $packet = ['dateTime' => self::T0, 'usUnits' => 17, 'outTemp' => 20.0, 'outHumidity' => 50.0, 'dewpoint' => 5.0];
        $computed = Formulas::dewpointC(20.0, 50.0);

        self::assertSame(5.0, $this->derived()->applyPacket($packet, 'a')['dewpoint']);
        self::assertSame($computed, $this->derived(['dewpoint' => How::Software])->applyPacket($packet, 'a')['dewpoint']);
        self::assertSame(5.0, $this->derived(['dewpoint' => How::Hardware])->applyPacket($packet, 'a')['dewpoint']);

        unset($packet['dewpoint']);
        self::assertSame($computed, $this->derived()->applyPacket($packet, 'a')['dewpoint']);
        self::assertArrayNotHasKey('dewpoint', $this->derived(['dewpoint' => How::Hardware])->applyPacket($packet, 'a'));
        $packet['dewpoint'] = null;
        self::assertSame($computed, $this->derived()->applyPacket($packet, 'a')['dewpoint']);
    }

    public function testWhatCannotBeWorkedOutIsWrittenAsNull(): void
    {
        $packet = $this->derived()->applyPacket(['dateTime' => self::T0, 'usUnits' => 17, 'outTemp' => 20.0], 'a');

        self::assertArrayHasKey('dewpoint', $packet);
        self::assertNull($packet['dewpoint']);
        self::assertNull($packet['heatindex']);
        self::assertNull($packet['windchill']);
        self::assertNull($packet['cloudbase']);
        self::assertNull($packet['pressure']);
        self::assertNull($packet['barometer']);
        self::assertNull($packet['altimeter']);
        // A packet has no interval, so no windrun and no ET: WeeWX writes the same nulls.
        self::assertNull($packet['windrun']);
        self::assertNull($packet['ET']);
        // Whereas the wind direction is only ever cleared, never invented.
        self::assertArrayNotHasKey('windDir', $packet);
        self::assertSame(0.0, $packet['rainRate']);
    }

    public function testRainIsTheRiseOfACounterKeptPerSender(): void
    {
        $derived = $this->derived();
        $packet = static fn(float $dayRain, ?float $rain = null): array => array_filter(
            ['dateTime' => self::T0, 'usUnits' => 17, 'dayRain' => $dayRain, 'rain' => $rain],
            static fn(mixed $value): bool => $value !== null,
        );

        // The first reading of a total has nothing to differ from.
        self::assertArrayNotHasKey('rain', $derived->applyPacket($packet(1.0), 'a'));
        self::assertSame(0.5, $derived->applyPacket($packet(1.5), 'a')['rain']);
        // Another sender's counter is another counter.
        self::assertArrayNotHasKey('rain', $derived->applyPacket($packet(10.0), 'b'));
        self::assertSame(0.0, $derived->applyPacket($packet(1.5), 'a')['rain']);
        // A falling counter could also be a correction: rebase without inventing rain.
        self::assertArrayNotHasKey('rain', $derived->applyPacket($packet(0.2), 'a'));
        // The station's own rain is kept, and its total remembered all the same.
        self::assertSame(0.1, $derived->applyPacket($packet(0.7, 0.1), 'a')['rain']);
        self::assertEqualsWithDelta(0.2, $derived->applyPacket($packet(0.9), 'a')['rain'], 1e-12);
        self::assertSame(1.0, $derived->applyPacket($packet(11.0), 'b')['rain']);
    }

    public function testTheRainRateIsTheQuarterHourBeforeThePacket(): void
    {
        $derived = $this->derived();
        $rain = static fn(int $when, ?float $rain): array => ['dateTime' => $when, 'usUnits' => 17, 'rain' => $rain];

        $derived->seed($rain(self::T0 - 800, 1.0), 'a');
        // A packet's own rain is not in its rate; WeeWX's rater sees the packet afterwards.
        self::assertSame(4.0, $derived->applyPacket($rain(self::T0, 2.0), 'a')['rainRate']);
        self::assertSame(12.0, $derived->applyPacket($rain(self::T0 + 1, null), 'a')['rainRate']);
        self::assertSame(12.0, $derived->applyPacket($rain(self::T0 + 1, 0.0), 'a')['rainRate']);
        // Fifteen minutes on, the seeded event has left the window.
        self::assertSame(8.0, $derived->applyPacket($rain(self::T0 + 101, null), 'a')['rainRate']);
        // The finished record's rate comes from the same events.
        self::assertSame(8.0, $derived->applyRecord(['dateTime' => self::T0 + 300, 'usUnits' => 17, 'interval' => 5])['rainRate']);
    }

    public function testACalmWindHasNoDirection(): void
    {
        $derived = $this->derived();
        $wind = static fn(mixed $speed, mixed $gust = 5.0): array => ['dateTime' => self::T0, 'usUnits' => 17, 'windSpeed' => $speed, 'windDir' => 180, 'windGust' => $gust, 'windGustDir' => 190];

        self::assertNull($derived->applyPacket($wind(0), 'a')['windDir']);
        self::assertNull($derived->applyPacket($wind(0.0), 'a')['windDir']);
        self::assertNull($derived->applyPacket($wind(null), 'a')['windDir']);
        self::assertSame(180, $derived->applyPacket($wind(0.1), 'a')['windDir']);
        self::assertSame(190, $derived->applyPacket($wind(0.0), 'a')['windGustDir']);
        self::assertNull($derived->applyPacket($wind(3.0, 0), 'a')['windGustDir']);
        // No speed at all says nothing about the direction.
        $noSpeed = $derived->applyPacket(['dateTime' => self::T0, 'usUnits' => 17, 'windDir' => 180], 'a');
        self::assertSame(180, $noSpeed['windDir']);
    }

    public function testStationPressureComesFromTheBarometerAndTheTemperatureOfTwelveHoursAgo(): void
    {
        $history = new FakeHistory(['dateTime' => self::T0 - 12 * 3600 + 100, 'usUnits' => 1, 'outTemp' => 50.0, 'interval' => 5]);
        $derived = $this->derived(history: $history);
        $packet = ['dateTime' => self::T0, 'usUnits' => 17, 'outTemp' => 10.0, 'outHumidity' => 60.0, 'barometer' => 1013.25];

        $result = $derived->applyPacket($packet, 'a');
        $barometerInHg = (float) Units::convert(1013.25, 'mbar', 'inHg');
        $inHg = Formulas::sealevelToSensorPressureUS($barometerInHg, 440.0 / Units::METER_PER_FOOT, Units::cToF(10.0), 50.0, 60.0);
        self::assertNotNull($inHg);
        self::assertSame(Units::convert($inHg, 'inHg', 'mbar'), $result['pressure']);
        // The altimeter follows the pressure just worked out; the barometer is the station's.
        self::assertSame(Formulas::altimeterPressureMetric((float) $result['pressure'], 440.0), $result['altimeter']);
        self::assertSame(1013.25, $result['barometer']);
        self::assertSame([[self::T0 - 12 * 3600, 1800]], $history->asked);

        // Half an hour on, the answer is still good; beyond that it is looked up again.
        $derived->applyPacket(['dateTime' => self::T0 + 1800] + $packet, 'a');
        self::assertCount(1, $history->asked);
        $derived->applyPacket(['dateTime' => self::T0 + 1801] + $packet, 'a');
        self::assertCount(2, $history->asked);
    }

    public function testWithoutThatTemperatureThereIsNoStationPressure(): void
    {
        $result = $this->derived(history: new FakeHistory(null))->applyPacket(
            ['dateTime' => self::T0, 'usUnits' => 17, 'outTemp' => 10.0, 'outHumidity' => 60.0, 'barometer' => 1013.25],
            'a',
        );
        self::assertNull($result['pressure']);
        self::assertNull($result['altimeter']);
        self::assertSame(1013.25, $result['barometer']);
    }

    public function testTheBarometerFollowsTheStationPressure(): void
    {
        $result = $this->derived()->applyPacket(['dateTime' => self::T0, 'usUnits' => 17, 'outTemp' => 10.0, 'pressure' => 960.0], 'a');
        self::assertSame(Formulas::sealevelPressureMetric(960.0, 440.0, 10.0), $result['barometer']);
        self::assertSame(Formulas::altimeterPressureMetric(960.0, 440.0), $result['altimeter']);
        self::assertSame(960.0, $result['pressure']);
    }

    public function testWindrunAndEvapotranspirationNeedARecord(): void
    {
        $window = ['t_max' => 80.0, 't_min' => 60.0, 'rad_avg' => 500.0, 'wind_avg' => 6.0, 'rh_max' => 70.0, 'rh_min' => 40.0, 'units_max' => 1, 'units_min' => 1];
        $history = new FakeHistory(null, $window);
        $derived = $this->derived(history: $history);

        $metric = $derived->applyRecord(['dateTime' => self::T0, 'usUnits' => 17, 'interval' => 5, 'windSpeed' => 2.0]);
        self::assertSame(0.6, $metric['windrun']);
        // T0 is 2026-08-26 08:50 UTC: the 237th day of the year, counted from nought, in Berlin as in UTC.
        $rate = Formulas::evapotranspirationUS(60.0, 80.0, 40.0, 70.0, 500.0, 6.0, 2.0 / Units::METER_PER_FOOT, 48.4596, 11.6539, 440.0 / Units::METER_PER_FOOT, 237, 8 + 50 / 60.0);
        self::assertNotNull($rate);
        self::assertSame(Units::convert($rate * 5 / 60.0, 'inch', 'mm'), $metric['ET']);
        self::assertSame([[self::T0 - 3600, self::T0]], $history->windows);

        $us = $derived->applyRecord(['dateTime' => self::T0, 'usUnits' => 1, 'interval' => 5, 'windSpeed' => 12.0]);
        self::assertSame(1.0, $us['windrun']);
        self::assertSame($rate * 5 / 60.0, $us['ET']);

        // An hour with a gap in it, or in two unit systems, gives no ET.
        $gap = $this->derived(history: new FakeHistory(null, ['rad_avg' => null] + $window));
        self::assertNull($gap->applyRecord(['dateTime' => self::T0, 'usUnits' => 1, 'interval' => 5])['ET']);
        $mixed = $this->derived(history: new FakeHistory(null, ['units_min' => 17] + $window));
        self::assertNull($mixed->applyRecord(['dateTime' => self::T0, 'usUnits' => 1, 'interval' => 5])['ET']);
        self::assertNull($derived->applyRecord(['dateTime' => self::T0, 'usUnits' => 1, 'interval' => 5, 'windSpeed' => null])['windrun']);
    }

    public function testTheClearSkyRadiationNeedsThePlace(): void
    {
        $noon = 1_782_900_000;
        $there = $this->derived()->applyPacket(['dateTime' => $noon, 'usUnits' => 17], 'a');
        self::assertSame(Formulas::solarRadRS(48.4596, 11.6539, 440.0, $noon), $there['maxSolarRad']);

        $nowhere = new Derived(new Site(null, null, null, new DateTimeZone('UTC')), new FakeHistory(null), Derivable::policy([]));
        $lost = $nowhere->applyPacket(['dateTime' => $noon, 'usUnits' => 17, 'outTemp' => 20.0, 'outHumidity' => 50.0, 'pressure' => 960.0], 'a');
        self::assertNull($lost['maxSolarRad']);
        self::assertNull($lost['cloudbase']);
        self::assertNull($lost['barometer']);
        self::assertSame(Formulas::dewpointC(20.0, 50.0), $lost['dewpoint']);
    }

    public function testEachUnitSystemGetsItsOwnFormula(): void
    {
        $derived = $this->derived();
        $reading = static fn(int $units, float $t, float $rh, float $v): array => ['dateTime' => self::T0, 'usUnits' => $units, 'outTemp' => $t, 'outHumidity' => $rh, 'windSpeed' => $v];

        $us = $derived->applyPacket($reading(1, 30.0, 80.0, 10.0), 'a');
        self::assertSame(Formulas::dewpointF(30.0, 80.0), $us['dewpoint']);
        self::assertSame(Formulas::windchillF(30.0, 10.0), $us['windchill']);
        self::assertSame(Formulas::apptempF(30.0, 80.0, 10.0), $us['appTemp']);
        self::assertSame(Formulas::cloudbaseUS(30.0, 80.0, 440.0 / Units::METER_PER_FOOT), $us['cloudbase']);

        $metric = $derived->applyPacket($reading(16, -1.0, 80.0, 10.0), 'a');
        self::assertSame(Formulas::windchillMetric(-1.0, 10.0), $metric['windchill']);
        self::assertSame(Formulas::apptempC(-1.0, 80.0, (float) Units::convert(10.0, 'km_per_hour', 'meter_per_second')), $metric['appTemp']);

        $metricwx = $derived->applyPacket($reading(17, -1.0, 80.0, 10.0), 'a');
        self::assertSame(Formulas::windchillMetricWX(-1.0, 10.0), $metricwx['windchill']);
        self::assertSame(Formulas::apptempC(-1.0, 80.0, 10.0), $metricwx['appTemp']);
        self::assertSame(Formulas::cloudbaseMetric(-1.0, 80.0, 440.0), $metricwx['cloudbase']);
    }

    public function testComesOutOfTheArchiveConfiguration(): void
    {
        $config = Archives::config(calculate: ['dewpoint' => How::Hardware], altitude: new Altitude(1443.57, 'foot'));
        $derived = Derived::fromConfig($config, new FakeHistory(null));

        $result = $derived->applyPacket(['dateTime' => self::T0, 'usUnits' => 1, 'outTemp' => 60.0, 'outHumidity' => 50.0, 'pressure' => 28.5], 'a');
        self::assertArrayNotHasKey('dewpoint', $result);
        // The altitude reaches the US formula as the number that was configured.
        self::assertSame(Formulas::altimeterPressureUS(28.5, 1443.57), $result['altimeter']);
    }

    /** @param array<string, How> $how */
    private function derived(array $how = [], ?History $history = null): Derived
    {
        return new Derived(
            new Site(48.4596, 11.6539, new Altitude(440.0, 'meter'), new DateTimeZone('Europe/Berlin')),
            $history ?? new FakeHistory(null),
            Derivable::policy($how),
        );
    }
}

/** An archive that answers from two arrays and remembers what it was asked. */
final class FakeHistory implements History
{
    /** @var list<array{0: int, 1: int}> */
    public array $asked = [];

    /** @var list<array{0: int, 1: int}> */
    public array $windows = [];

    /**
     * @param array<string, mixed>|null $near
     * @param array<string, mixed>|null $window
     */
    public function __construct(
        private readonly ?array $near,
        private readonly ?array $window = null,
    ) {}

    public function recordNear(int $timestamp, int $maxDelta): ?array
    {
        $this->asked[] = [$timestamp, $maxDelta];
        return $this->near;
    }

    public function etWindow(int $start, int $stop): ?array
    {
        $this->windows[] = [$start, $stop];
        return $this->window;
    }
}
