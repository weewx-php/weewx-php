<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * Where the sun is. The clear-sky radiation a station would see needs its
 * elevation and the Earth's distance, and the daily summaries need nothing
 * more of it than that.
 *
 * NOAA's algorithm, transcribed from weewx-evo's `sun.py`, which measured
 * it against pyephem: the declination agrees to 0.002 degrees. That is the
 * arrangement WeeWX has as well; it takes pyephem when installed and its
 * own arithmetic when not.
 */
final class Sun
{
    private function __construct() {}

    /**
     * The sun's declination, the equation of time, and Earth's distance.
     *
     * @return array{0: float, 1: float, 2: float} A 3-way tuple (declination in degrees,
     *     equation of time in minutes, distance in astronomical units).
     */
    public static function solar(int|float $when): array
    {
        // Julian centuries since J2000.0.
        $jd = $when / 86400.0 + 2440587.5;
        $t = ($jd - 2451545.0) / 36525.0;

        $meanLong = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360.0);
        if ($meanLong < 0.0) {
            $meanLong += 360.0;
        }
        $meanAnomaly = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $eccentricity = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);

        $m = deg2rad($meanAnomaly);
        $centre = sin($m) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
            + sin(2 * $m) * (0.019993 - 0.000101 * $t)
            + sin(3 * $m) * 0.000289;
        $trueLong = $meanLong + $centre;
        $trueAnomaly = $meanAnomaly + $centre;

        // Earth's distance in AU. Over a year this is +-1.7 %, and it enters
        // the radiation squared, which is the 3.4 % that leaving it out costs.
        $distance = (1.000001018 * (1 - $eccentricity ** 2))
            / (1 + $eccentricity * cos(deg2rad($trueAnomaly)));

        $omega = 125.04 - 1934.136 * $t;
        $apparent = $trueLong - 0.00569 - 0.00478 * sin(deg2rad($omega));

        $obliquity = 23.0 + (26.0 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60.0) / 60.0;
        $obliquity += 0.00256 * cos(deg2rad($omega));

        $declination = rad2deg(asin(sin(deg2rad($obliquity)) * sin(deg2rad($apparent))));

        // The equation of time, in minutes: how far the sun runs ahead of or
        // behind a clock, up to a quarter of an hour either way.
        $y = tan(deg2rad($obliquity / 2.0)) ** 2;
        $l0 = deg2rad($meanLong);
        $eot = 4.0 * rad2deg(
            $y * sin(2 * $l0)
            - 2 * $eccentricity * sin($m)
            + 4 * $eccentricity * $y * sin($m) * cos(2 * $l0)
            - 0.5 * $y * $y * sin(4 * $l0)
            - 1.25 * $eccentricity ** 2 * sin(2 * $m),
        );

        return [$declination, $eot, $distance];
    }

    /**
     * The sun's elevation above the horizon and Earth's distance.
     *
     * @param float $latitude Decimal degrees, negative south.
     * @param float $longitude Decimal degrees, negative west.
     *
     * @return array{0: float, 1: float} A 2-way tuple (elevation in degrees, distance in AU).
     */
    public static function position(int|float $when, float $latitude, float $longitude): array
    {
        [$declination, $eot, $distance] = self::solar($when);
        $minutes = fmod((float) $when, 86400.0) / 60.0;
        if ($minutes < 0.0) {
            $minutes += 1440.0;
        }
        $trueSolar = fmod($minutes + $eot + 4.0 * $longitude, 1440.0);
        if ($trueSolar < 0.0) {
            $trueSolar += 1440.0;
        }
        $hourAngle = $trueSolar / 4.0 - 180.0;

        $lat = deg2rad($latitude);
        $dec = deg2rad($declination);
        $cosZenith = sin($lat) * sin($dec) + cos($lat) * cos($dec) * cos(deg2rad($hourAngle));
        $cosZenith = max(-1.0, min(1.0, $cosZenith));
        return [90.0 - rad2deg(acos($cosZenith)), $distance];
    }

    /**
     * The elevation an observer sees: refraction lifts the image, by half a
     * degree at the horizon and by a minute of arc at 45 degrees. libastro's
     * model, transcribed from its `refract()`, because that is what pyephem
     * and so WeeWX's almanac report as `alt`, at the pressure and
     * temperature the almanac assumes unless told otherwise.
     *
     * @param float $elevation Geometric elevation in degrees, from {@see position()}.
     * @param float $pressure Millibars; zero switches refraction off, as it does in pyephem.
     * @param float $temperature Degrees Celsius.
     */
    public static function apparent(float $elevation, float $pressure = 1010.0, float $temperature = 15.0): float
    {
        if (is_nan($elevation)) {
            return $elevation;
        }
        // The model runs the other way, from apparent to true, so the
        // apparent elevation is the one that unrefracts to this true one:
        // found by the secant method to a tenth of an arc second, the way
        // libastro finds it. Its first guess leans on delta-apparent always
        // being smaller than delta-true.
        $true = $elevation;
        $t = self::unrefract($true, $pressure, $temperature);
        $d = 0.8 * ($true - $t);
        $t0 = $t;
        $a = $true;
        for ($i = 0; $i < self::REFRACTION_STEPS; ++$i) {
            $a += $d;
            $t = self::unrefract($a, $pressure, $temperature);
            if (abs($true - $t) <= self::REFRACTION_ACCURACY || $t0 === $t) {
                break;
            }
            $d *= -($true - $t) / ($t0 - $t);
            $t0 = $t;
        }
        return $a;
    }

    /** A tenth of an arc second, in degrees: libastro's MAXRERR. */
    private const REFRACTION_ACCURACY = 0.1 / 3600.0;

    /** libastro measured at most 7 secant steps; more means the model is not converging. */
    private const REFRACTION_STEPS = 50;

    /** libastro's `unrefract()`: the true elevation behind an apparent one, in degrees. */
    private static function unrefract(float $apparent, float $pressure, float $temperature): float
    {
        if ($apparent < 14.5) {
            return self::unrefractBelow15($apparent, $pressure, $temperature);
        }
        if ($apparent >= 15.5) {
            return self::unrefractFrom15($apparent, $pressure, $temperature);
        }
        // Between the two models a smooth blend, which the inverse needs.
        $low = self::unrefractBelow15($apparent, $pressure, $temperature);
        $high = self::unrefractFrom15($apparent, $pressure, $temperature);
        return $low + ($high - $low) * ($apparent - 14.5);
    }

    private static function unrefractFrom15(float $apparent, float $pressure, float $temperature): float
    {
        $lift = rad2deg(7.888888e-5 * $pressure / ((273 + $temperature) * tan(deg2rad($apparent))));
        return $apparent - $lift;
    }

    private static function unrefractBelow15(float $apparent, float $pressure, float $temperature): float
    {
        $a = ((2e-5 * $apparent + 1.96e-2) * $apparent + 1.594e-1) * $pressure;
        $b = (273 + $temperature) * ((8.45e-2 * $apparent + 5.05e-1) * $apparent + 1);
        $lift = $a / $b;
        // The polynomial turns negative some eight degrees below the
        // horizon, and there the image is left where it is.
        return $apparent < 0 && $lift < 0 ? $apparent : $apparent - $lift;
    }
}
