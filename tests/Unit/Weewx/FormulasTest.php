<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\Formulas;
use WeewxPhp\Weewx\Sun;

/**
 * The formulas' edges. Their values are checked against WeeWX itself by
 * the conformance run; what is checked here is where each one stops
 * answering and what it does on its thresholds.
 */
final class FormulasTest extends TestCase
{
    public function testAMissingInputMeansNoAnswer(): void
    {
        self::assertNull(Formulas::dewpointC(null, 50.0));
        self::assertNull(Formulas::dewpointC(20.0, null));
        self::assertNull(Formulas::windchillF(null, 10.0));
        self::assertNull(Formulas::heatindexF(90.0, null));
        self::assertNull(Formulas::humidexC(null, 50.0));
        self::assertNull(Formulas::apptempC(20.0, 50.0, null));
        self::assertNull(Formulas::cloudbaseMetric(20.0, 50.0, null));
        self::assertNull(Formulas::altimeterPressureMetric(null, 440.0));
        self::assertNull(Formulas::sealevelPressureMetric(950.0, 440.0, null));
    }

    public function testWherePythonWouldRaiseTheAnswerIsNull(): void
    {
        // log(0): a dry reading has no dew point.
        self::assertNull(Formulas::dewpointC(20.0, 0.0));
        self::assertNull(Formulas::dewpointC(20.0, -5.0));
        self::assertNull(Formulas::humidexC(30.0, 0.0));
        // A humidity outside its range is refused, not clamped.
        self::assertNull(Formulas::apptempC(20.0, 101.0, 1.0));
        self::assertNull(Formulas::apptempC(20.0, 50.0, -1.0));
        // Pressures below what WeeWX accepts.
        self::assertNull(Formulas::altimeterPressureUS(0.008859, 100.0));
        self::assertNull(Formulas::altimeterPressureMetric(0.3, 100.0));
    }

    public function testWindChillAndHeatIndexHandBackTheTemperatureOutsideTheirRange(): void
    {
        self::assertSame(50.0, Formulas::windchillF(50.0, 20.0));
        self::assertSame(30.0, Formulas::windchillF(30.0, 3.0));
        self::assertLessThan(30.0, Formulas::windchillF(30.0, 3.1));

        self::assertSame(40.0, Formulas::heatindexF(40.0, 90.0));
        // Just above 40 F the simple equation applies, and it may well say
        // less than the temperature: WeeWX does not clamp it, so neither do we.
        self::assertEqualsWithDelta(38.04, Formulas::heatindexF(40.1, 90.0), 0.005);
        // The full equation only from 80 F on; below it the simple one.
        self::assertEqualsWithDelta(69.05, Formulas::heatindexF(70.0, 50.0), 0.005);
        self::assertEqualsWithDelta(105.9, Formulas::heatindexF(90.0, 70.0), 0.5);
    }

    public function testHumidexIsTheTemperatureWhenTheAirIsDry(): void
    {
        self::assertSame(30.0, Formulas::humidexC(30.0, 20.0));
        self::assertEqualsWithDelta(43.66, Formulas::humidexC(30.0, 80.0), 0.005);
    }

    public function testACounterDeltaFollowsWeewxEvo(): void
    {
        self::assertSame(2.0, Formulas::delta(5.0, 3.0));
        self::assertSame(0.0, Formulas::delta(3.0, 3.0));
        // The counter went down: it was reset, and the new value is what fell since.
        self::assertSame(2.0, Formulas::delta(2.0, 3.0));
        self::assertNull(Formulas::delta(null, 3.0));
        self::assertNull(Formulas::delta(3.0, null));
    }

    public function testRoundsAHalfToTheEvenNeighbourLikePython(): void
    {
        self::assertSame(0.0, Formulas::roundHalfEven(0.5));
        self::assertSame(2.0, Formulas::roundHalfEven(1.5));
        self::assertSame(2.0, Formulas::roundHalfEven(2.5));
        self::assertSame(-2.0, Formulas::roundHalfEven(-1.5));
        self::assertSame(-2.0, Formulas::roundHalfEven(-2.5));
        self::assertSame(3.0, Formulas::roundHalfEven(2.51));
        self::assertSame(-3.0, Formulas::roundHalfEven(-2.51));
        // Just under a half stays under: PHP's round() before 8.4 would say 3.
        self::assertSame(2.0, Formulas::roundHalfEven(2.4999999999999996));
        self::assertSame(1.0e300, Formulas::roundHalfEven(1.0e300));
    }

    public function testTheMeanTemperatureRoundsLikeTheConsole(): void
    {
        // 60.51 - 0.01 is exactly 60.5 in binary; Python rounds it to 60, PHP to 61.
        $even = Formulas::sealevelToSensorPressureUS(29.921, 1000.0, 60.51, 59.0, 40.5);
        $odd = Formulas::sealevelToSensorPressureUS(29.921, 1000.0, 61.0, 59.0, 40.5);
        self::assertNotNull($even);
        self::assertNotNull($odd);
        self::assertNotEqualsWithDelta($odd, $even, 1e-6);
        $mean60 = Formulas::sealevelToSensorPressureUS(29.921, 1000.0, 60.0, 59.0, 40.5);
        self::assertNotNull($mean60);
        // 60.51 and 60.0 share the mean of 60 (rounded from 59.5 - 0.01), but not the current temperature.
        self::assertNotEqualsWithDelta($mean60, $even, 1e-9);
    }

    public function testRefractionLiftsTheSunMostAtTheHorizonAndNotAtAllWellBelowIt(): void
    {
        // The lift shrinks with the elevation: about 28 minutes of arc at
        // the horizon, one minute at 45 degrees, nothing at the zenith.
        self::assertEqualsWithDelta(0.471, Sun::apparent(0.0), 0.002);
        self::assertEqualsWithDelta(45.0159, Sun::apparent(45.0), 0.0002);
        self::assertEqualsWithDelta(90.0, Sun::apparent(90.0), 1e-9);
        $lift = static fn(float $elevation): float => Sun::apparent($elevation) - $elevation;
        self::assertGreaterThan($lift(10.0), $lift(5.0));
        self::assertGreaterThan($lift(14.0), $lift(10.0));
        self::assertGreaterThan($lift(16.0), $lift(14.0));
        self::assertGreaterThan($lift(20.0), $lift(16.0));
        // Still lifted a little below the horizon, as pyephem has it, and
        // left alone once the polynomial turns negative.
        self::assertGreaterThan(-5.0, Sun::apparent(-5.0));
        self::assertSame(-10.0, Sun::apparent(-10.0));
        // No air, no lift.
        self::assertSame(10.0, Sun::apparent(10.0, pressure: 0.0));
        self::assertNan(Sun::apparent(NAN));
    }

    public function testThereIsNoRadiationAtNight(): void
    {
        // Midnight in Kirchdorf, 2026-06-30.
        self::assertSame(0.0, Formulas::solarRadRS(48.4596, 11.6539, 440.0, 1782857400));
        // Noon: about a kilowatt, atc 0.8.
        self::assertEqualsWithDelta(900.0, Formulas::solarRadRS(48.4596, 11.6539, 440.0, 1782900000), 100.0);
        // An atc outside its range falls back to 0.8.
        self::assertSame(
            Formulas::solarRadRS(48.4596, 11.6539, 440.0, 1782900000, 0.8),
            Formulas::solarRadRS(48.4596, 11.6539, 440.0, 1782900000, 0.95),
        );
    }

    public function testEvapotranspirationIsNeverNegativeAndNeedsItsInputs(): void
    {
        self::assertNull(Formulas::evapotranspirationMetric(null, 20.0, 40.0, 90.0, 300.0, 2.0, 2.0, 48.0, 11.0, 440.0, 200, 12.0));
        $night = Formulas::evapotranspirationMetric(-5.0, -2.0, 90.0, 99.0, 0.0, 0.0, 2.0, 48.0, 11.0, 440.0, 10, 2.0);
        self::assertNotNull($night);
        self::assertGreaterThanOrEqual(0.0, $night);
        $day = Formulas::evapotranspirationMetric(20.0, 30.0, 30.0, 60.0, 700.0, 3.0, 2.0, 48.0, 11.0, 440.0, 200, 12.0);
        self::assertNotNull($day);
        self::assertGreaterThan(0.3, $day);
        self::assertLessThan(1.5, $day);
    }
}
