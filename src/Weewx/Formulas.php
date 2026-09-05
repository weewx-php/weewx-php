<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * The weather formulas, transcribed from WeeWX 5.5's `wxformulas.py` and
 * the parts of `uwxutils.py` they lean on.
 *
 * Expression by expression, constants included, because what comes out
 * of them goes into a database WeeWX reads back: a formula that rounds
 * differently is a wrong formula here even when it is a better one. Where
 * Python raises a ValueError or OverflowError and WeeWX answers None, PHP
 * would hand back NaN or throw, so those cases are caught up front and
 * answer null.
 */
final class Formulas
{
    /** The solar constant, W/m^2, as WeeWX has it. */
    public const SOLAR_CONSTANT = 1367.0;

    /** uwxutils: standard lapse rate used by the Vantage Pro, 2.75 F per 1000 ft. */
    private const VP_LAPSE_RATE_US = 0.00275;

    /** uwxutils: radius of the earth at latitude 45.5, km. */
    private const EARTH_RADIUS_45 = 6356.766;

    private function __construct() {}

    // -- dew point ----------------------------------------------------------

    /** Dew point in Fahrenheit, or null when it cannot be worked out. */
    public static function dewpointF(?float $t, ?float $rh): ?float
    {
        if ($t === null || $rh === null) {
            return null;
        }
        $dewpointC = self::dewpointC(Units::fToC($t), $rh);
        return $dewpointC === null ? null : Units::cToF($dewpointC);
    }

    /** Dew point in Celsius: the Magnus formula WeeWX uses. */
    public static function dewpointC(?float $t, ?float $rh): ?float
    {
        if ($t === null || $rh === null) {
            return null;
        }
        $rh = $rh / 100.0;
        // log() of zero or a negative is where Python raises ValueError.
        if ($rh <= 0.0 || 237.7 + $t === 0.0) {
            return null;
        }
        $gamma = 17.27 * $t / (237.7 + $t) + log($rh);
        if (17.27 - $gamma === 0.0) {
            return null;
        }
        return self::finite(237.7 * $gamma / (17.27 - $gamma));
    }

    // -- wind chill ---------------------------------------------------------

    /**
     * Wind chill in Fahrenheit, the NWS formula. Only valid below 50 F and
     * above 3 mph; outside that it is the temperature itself.
     */
    public static function windchillF(?float $tF, ?float $vMph): ?float
    {
        if ($tF === null || $vMph === null) {
            return null;
        }
        if ($tF >= 50.0 || $vMph <= 3.0) {
            return $tF;
        }
        return 35.74 + 0.6215 * $tF + (-35.75 + 0.4275 * $tF) * pow($vMph, 0.16);
    }

    /** Wind chill, metric version, with the wind in km/h. */
    public static function windchillMetric(?float $tC, ?float $vKph): ?float
    {
        if ($tC === null || $vKph === null) {
            return null;
        }
        $chill = self::windchillF(Units::cToF($tC), 0.621371192 * $vKph);
        return $chill === null ? null : Units::fToC($chill);
    }

    /** Wind chill, metric version, with the wind in m/s. */
    public static function windchillMetricWX(?float $tC, ?float $vMps): ?float
    {
        if ($tC === null || $vMps === null) {
            return null;
        }
        $chill = self::windchillF(Units::cToF($tC), 2.237 * $vMps);
        return $chill === null ? null : Units::fToC($chill);
    }

    // -- heat index ---------------------------------------------------------

    /**
     * Heat index in Fahrenheit, the NWS equation with its two adjustments:
     * WeeWX's 'new' algorithm. Below 40 F there is none and the
     * temperature comes back.
     */
    public static function heatindexF(?float $t, ?float $rh): ?float
    {
        if ($t === null || $rh === null) {
            return null;
        }
        if ($t <= 40.0) {
            return $t;
        }
        $hi = 0.5 * ($t + 61.0 + (($t - 68.0) * 1.2) + ($rh * 0.094));
        if (($hi + $t) / 2.0 >= 80.0) {
            $hi = -42.379
                + 2.04901523 * $t
                + 10.14333127 * $rh
                - 0.22475541 * $t * $rh
                - 6.83783e-3 * $t ** 2
                - 5.481717e-2 * $rh ** 2
                + 1.22874e-3 * $t ** 2 * $rh
                + 8.5282e-4 * $t * $rh ** 2
                - 1.99e-6 * $t ** 2 * $rh ** 2;
            if ($rh < 13 && $t > 80 && $t < 112) {
                $hi -= ((13 - $rh) / 4.0) * sqrt((17 - abs($t - 95.0)) / 17.0);
            } elseif ($rh > 85 && $t >= 80 && $t < 87) {
                $hi += (($rh - 85) / 10.0) * ((87 - $t) / 5.0);
            }
        }
        return $hi;
    }

    public static function heatindexC(?float $tC, ?float $rh): ?float
    {
        if ($tC === null || $rh === null) {
            return null;
        }
        $hi = self::heatindexF(Units::cToF($tC), $rh);
        return $hi === null ? null : Units::fToC($hi);
    }

    // -- humidex ------------------------------------------------------------

    /** Humidex in Celsius, or null when the dew point cannot be worked out. */
    public static function humidexC(?float $tC, ?float $rh): ?float
    {
        $dewpoint = self::dewpointC($tC, $rh);
        if ($tC === null || $dewpoint === null) {
            return null;
        }
        $dewpointK = Units::cToK($dewpoint);
        if ($dewpointK === 0.0) {
            return null;
        }
        $e = 6.11 * exp(5417.7530 * (1 / 273.15 - 1 / $dewpointK));
        $h = 0.5555 * ($e - 10.0);
        if (!is_finite($h)) {
            return null;
        }
        return $h > 0 ? $tC + $h : $tC;
    }

    public static function humidexF(?float $tF, ?float $rh): ?float
    {
        if ($tF === null) {
            return null;
        }
        $humidex = self::humidexC(Units::fToC($tF), $rh);
        return $humidex === null ? null : Units::cToF($humidex);
    }

    // -- apparent temperature -------------------------------------------------

    /** Apparent temperature in Celsius, the Australian BOM formula, wind in m/s. */
    public static function apptempC(?float $tC, ?float $rh, ?float $wsMps): ?float
    {
        if ($tC === null || $rh === null || $rh < 0 || $rh > 100 || $wsMps === null || $wsMps < 0) {
            return null;
        }
        if (237.7 + $tC === 0.0) {
            return null;
        }
        $e = ($rh / 100.0) * 6.105 * exp(17.27 * $tC / (237.7 + $tC));
        return self::finite($tC + 0.33 * $e - 0.7 * $wsMps - 4.0);
    }

    /** Apparent temperature in Fahrenheit, wind in mph. */
    public static function apptempF(?float $tF, ?float $rh, ?float $wsMph): ?float
    {
        if ($tF === null || $rh === null || $rh < 0 || $rh > 100 || $wsMph === null || $wsMph < 0) {
            return null;
        }
        $apparent = self::apptempC(Units::fToC($tF), $rh, $wsMph * Units::METER_PER_MILE / 3600.0);
        return $apparent === null ? null : Units::cToF($apparent);
    }

    // -- cloud base -----------------------------------------------------------

    /** Cloud base in metres, from the spread between temperature and dew point. */
    public static function cloudbaseMetric(?float $tC, ?float $rh, ?float $altitudeM): ?float
    {
        $dewpoint = self::dewpointC($tC, $rh);
        if ($tC === null || $dewpoint === null || $altitudeM === null) {
            return null;
        }
        $cb = ($tC - $dewpoint) * 1000 / 2.5;
        return $altitudeM + $cb * Units::METER_PER_FOOT;
    }

    /** Cloud base in feet. */
    public static function cloudbaseUS(?float $tF, ?float $rh, ?float $altitudeFt): ?float
    {
        $dewpoint = self::dewpointF($tF, $rh);
        if ($tF === null || $dewpoint === null || $altitudeFt === null) {
            return null;
        }
        return $altitudeFt + ($tF - $dewpoint) * 1000.0 / 4.4;
    }

    // -- pressure -------------------------------------------------------------

    /**
     * Altimeter setting from station pressure in inHg and the altitude in
     * feet: the aaASOS algorithm, by way of uwxutils' unit shuffling.
     */
    public static function altimeterPressureUS(?float $spInHg, ?float $zFoot): ?float
    {
        if ($spInHg === null || $zFoot === null || $spInHg <= 0.008859) {
            return null;
        }
        return self::hPaToIn(self::stationToAltimeterAsos(self::inToHPa($spInHg), self::ftToM($zFoot)));
    }

    /** Altimeter setting from station pressure in mbar and the altitude in metres. */
    public static function altimeterPressureMetric(?float $spMbar, ?float $zMeter): ?float
    {
        if ($spMbar === null || $zMeter === null || $spMbar <= 0.3) {
            return null;
        }
        return self::stationToAltimeterAsos($spMbar, $zMeter);
    }

    /** Station pressure reduced to sea level, in mbar. wview's formula, by way of WeeWX. */
    public static function sealevelPressureMetric(?float $spMbar, ?float $elevMeter, ?float $tC): ?float
    {
        if ($spMbar === null || $elevMeter === null || $tC === null) {
            return null;
        }
        $pt = self::etterm($elevMeter, $tC);
        return $pt !== 0.0 ? $spMbar / $pt : 0.0;
    }

    /** Station pressure reduced to sea level, in inHg. */
    public static function sealevelPressureUS(?float $spInHg, ?float $elevFoot, ?float $tF): ?float
    {
        if ($spInHg === null || $elevFoot === null || $tF === null) {
            return null;
        }
        $slp = self::sealevelPressureMetric($spInHg / Units::INHG_PER_MBAR, $elevFoot * Units::METER_PER_FOOT, Units::fToC($tF));
        return $slp === null ? null : $slp * Units::INHG_PER_MBAR;
    }

    /**
     * Sea-level pressure brought back down to the sensor, in inHg: the
     * Davis Vantage Pro reduction WeeWX's PressureCooker uses, with the
     * mean of the current temperature and the one twelve hours ago,
     * rounded to whole degrees the way the console rounds.
     */
    public static function sealevelToSensorPressureUS(float $pressureIn, float $elevationFt, float $currentTempF, float $temp12HrsAgoF, float $humidity): ?float
    {
        // uwxutils rounds with Python's round(), which takes a half to the
        // even neighbour; PHP's takes it away from zero.
        $meanTempF = self::roundHalfEven(
            (self::roundHalfEven($currentTempF - 0.01) + self::roundHalfEven($temp12HrsAgoF - 0.01)) / 2 - 0.01,
        );
        $ratio = self::pressureReductionRatioDavis(self::ftToM($elevationFt), Units::fToC($currentTempF), Units::fToC($meanTempF), $humidity);
        if ($ratio === null || $ratio === 0.0) {
            return null;
        }
        return self::finite($pressureIn / $ratio);
    }

    // -- radiation ------------------------------------------------------------

    /**
     * Clear-sky radiation in W/m^2, Ryan-Stolzenbach (MIT, 1972): what a
     * solar sensor would read with no cloud above it. WeeWX's
     * `solar_rad_RS`, with the sun placed by {@see Sun::position()} and
     * lifted by refraction as WeeWX's almanac reports it, because near the
     * horizon the half degree of lift is a tenth of the value.
     */
    public static function solarRadRS(float $latitude, float $longitude, float $altitudeM, int|float $when, float $atc = 0.8): float
    {
        if ($atc < 0.7 || $atc > 0.91) {
            $atc = 0.8;
        }
        [$geometric, $distance] = Sun::position($when, $latitude, $longitude);
        $elevation = Sun::apparent($geometric);
        $sinal = sin(Units::radians($elevation));
        if ($sinal < 0) {
            return 0.0;
        }
        $rm = pow((288.0 - 0.0065 * $altitudeM) / 288.0, 5.256) / ($sinal + 0.15 * pow($elevation + 3.885, -1.253));
        $toa = self::SOLAR_CONSTANT * $sinal / ($distance * $distance);
        return $toa * pow($atc, $rm);
    }

    // -- evapotranspiration -----------------------------------------------------

    /**
     * The rate of evapotranspiration over one hour, in mm/hour: the FAO
     * Penman-Monteith reference crop, WeeWX's `evapotranspiration_Metric`.
     *
     * @param int $dayOfYear 0-based, from the local date, as WeeWX takes it from `time.localtime`.
     * @param float $todUtc Time of day at the end of the hour, in UTC hours.
     */
    public static function evapotranspirationMetric(
        ?float $tminC,
        ?float $tmaxC,
        ?float $rhMin,
        ?float $rhMax,
        ?float $srMeanWpm2,
        ?float $wsMps,
        ?float $windHeightM,
        ?float $latitudeDeg,
        ?float $longitudeDeg,
        ?float $altitudeM,
        int $dayOfYear,
        float $todUtc,
        float $albedo = 0.23,
        float $cn = 37.0,
        float $cd = 0.34,
    ): ?float {
        if ($tminC === null || $tmaxC === null || $rhMin === null || $rhMax === null || $srMeanWpm2 === null
            || $wsMps === null || $latitudeDeg === null || $longitudeDeg === null) {
            return null;
        }
        $windHeightM ??= 2.0;
        $altitudeM ??= 0.0;

        $tavgC = ($tmaxC + $tminC) / 2.0;
        $rhAvg = ($rhMin + $rhMax) / 2.0;

        // Adjust the wind speed for the height it was measured at.
        $logTerm = 67.8 * $windHeightM - 5.42;
        if ($logTerm <= 0.0) {
            return null;
        }
        $u2 = 4.87 * $wsMps / log($logTerm);

        // Atmospheric pressure in kPa, and the psychrometric constant (eqn 8).
        $p = 101.3 * pow((293.0 - 0.0065 * $altitudeM) / 293.0, 5.26);
        $gamma = 0.665e-03 * $p;

        // Mean saturation vapor pressure, hPa to kPa (eqn 12).
        $etmin = self::saturationVaporPressureTeten($tminC) / 10.0;
        $etmax = self::saturationVaporPressureTeten($tmaxC) / 10.0;
        $e0T = ($etmin + $etmax) / 2.0;

        // Slope of the saturation vapor pressure curve, kPa/C (eqn 13).
        $delta = 4098.0 * (0.6108 * exp(17.27 * $tavgC / ($tavgC + 237.3)))
            / (($tavgC + 237.3) * ($tavgC + 237.3));

        // Actual vapor pressure from relative humidity (eqn 17).
        $ea = ($etmin * $rhMax + $etmax * $rhMin) / 200.0;

        // Solar radiation from W/m^2 to MJ/m^2/hr, and the net shortwave (eqn 38).
        $rs = $srMeanWpm2 * 3.6e-3;
        $rns = (1.0 - $albedo) * $rs;

        $ra = self::sunRadiation($dayOfYear, $latitudeDeg, $longitudeDeg, $todUtc, 1.0);
        $rso = (0.75 + 2e-5 * $altitudeM) * $ra;

        $rnl = self::longwaveRadiation($tminC, $tmaxC, $ea, $rs, $rso, $rhAvg) / 24.0;
        $rn = $rns - $rnl;

        // Soil heat flux: a tenth of it by day, half by night.
        $g = $rs !== 0.0 ? 0.1 * $rn : 0.5 * $rn;

        $et0 = (0.408 * $delta * ($rn - $g) + $gamma * ($cn / ($tavgC + 273)) * $u2 * ($e0T - $ea))
            / ($delta + $gamma * (1 + $cd * $u2));

        if (!is_finite($et0)) {
            return null;
        }
        return $et0 < 0 ? 0.0 : $et0;
    }

    /** The same, in inches/hour with US inputs: WeeWX's `evapotranspiration_US`. */
    public static function evapotranspirationUS(
        ?float $tminF,
        ?float $tmaxF,
        ?float $rhMin,
        ?float $rhMax,
        ?float $srMeanWpm2,
        ?float $wsMph,
        ?float $windHeightFt,
        ?float $latitudeDeg,
        ?float $longitudeDeg,
        ?float $altitudeFt,
        int $dayOfYear,
        float $todUtc,
        float $albedo = 0.23,
        float $cn = 37.0,
        float $cd = 0.34,
    ): ?float {
        if ($tminF === null || $tmaxF === null || $wsMph === null || $windHeightFt === null || $altitudeFt === null) {
            return null;
        }
        $et = self::evapotranspirationMetric(
            Units::fToC($tminF),
            Units::fToC($tmaxF),
            $rhMin,
            $rhMax,
            $srMeanWpm2,
            $wsMph * Units::METER_PER_MILE / 3600.0,
            $windHeightFt * Units::METER_PER_FOOT,
            $latitudeDeg,
            $longitudeDeg,
            $altitudeFt * Units::METER_PER_FOOT,
            $dayOfYear,
            $todUtc,
            $albedo,
            $cn,
            $cd,
        );
        return $et === null ? null : $et / Units::MM_PER_INCH;
    }

    /** Extraterrestrial radiation over an interval, MJ/m^2/hr: WeeWX's `sun_radiation`. */
    public static function sunRadiation(int $dayOfYear, float $latitudeDeg, float $longitudeDeg, float $todUtc, float $interval): float
    {
        $gsc = 4.92;
        $declination = self::solarDeclination($dayOfYear);
        $earthDistance = 1.0 + 0.033 * cos(2.0 * M_PI * $dayOfYear / 365.0);

        $startOmega = self::hourAngle($todUtc - $interval, $longitudeDeg, $dayOfYear);
        $stopOmega = self::hourAngle($todUtc, $longitudeDeg, $dayOfYear);
        $latitude = Units::radians($latitudeDeg);

        $part1 = ($stopOmega - $startOmega) * sin($latitude) * sin($declination);
        $part2 = cos($latitude) * cos($declination) * (sin($stopOmega) - sin($startOmega));

        $ra = (12.0 / M_PI) * $gsc * $earthDistance * ($part1 + $part2);
        return $ra < 0 ? 0.0 : $ra;
    }

    /** Net long-wave radiation, MJ/m^2/day: WeeWX's `longwave_radiation`, made-up night formula included. */
    public static function longwaveRadiation(float $tminC, float $tmaxC, float $ea, float $rs, float $rso, float $rh): float
    {
        $tminK = $tminC + 273.16;
        $tmaxK = $tmaxC + 273.16;
        $sigma = 4.903e-09;

        if ($rso !== 0.0) {
            $cloudFactor = $rs / $rso;
        } elseif ($rh > 80) {
            $cloudFactor = 0.3;
        } elseif ($rh > 40) {
            $cloudFactor = 0.5;
        } else {
            $cloudFactor = 0.8;
        }

        $part1 = $sigma * ($tminK ** 4 + $tmaxK ** 4) / 2.0;
        $part2 = 0.34 - 0.14 * sqrt($ea);
        $part3 = 1.35 * $cloudFactor - 0.35;
        return $part1 * $part2 * $part3;
    }

    /** Equation of time in hours: WeeWX's `equation_of_time`. */
    public static function equationOfTime(int $dayOfYear): float
    {
        $b = 2 * M_PI * ($dayOfYear - 81) / 364.0;
        return 0.1645 * sin(2 * $b) - 0.1255 * cos($b) - 0.025 * sin($b);
    }

    /** Solar hour angle in radians, 0 <= omega < 2 pi: WeeWX's `hour_angle`. */
    public static function hourAngle(float $tUtc, float $longitude, int $dayOfYear): float
    {
        $omega = (M_PI / 12.0) * ($tUtc + $longitude / 15.0 + self::equationOfTime($dayOfYear) - 12);
        if ($omega < 0) {
            $omega += 2.0 * M_PI;
        }
        return $omega;
    }

    /** Solar declination in radians: WeeWX's `solar_declination`. */
    public static function solarDeclination(int $dayOfYear): float
    {
        return 0.409 * sin(2.0 * M_PI * $dayOfYear / 365 - 1.39);
    }

    // -- rain -----------------------------------------------------------------

    /**
     * How much a running total went up by. The case that is a measurement:
     * consoles send totals since midnight and expect the receiver to take
     * the difference.
     *
     * Three cases that are not errors, as weewx-evo decided them: no
     * previous value means no delta and nothing booked; a total that went
     * down is a reset, and the new value is the amount; an unchanged total
     * is zero, which is a measurement and not a missing value.
     */
    public static function delta(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null) {
            return null;
        }
        return $now < $before ? $now : $now - $before;
    }

    // -- uwxutils -------------------------------------------------------------

    /**
     * uwxutils' `TWxUtils.StationToAltimeter` with the 'aaASOS' algorithm:
     * hPa in, hPa out, elevation in metres.
     */
    private static function stationToAltimeterAsos(float $pressureHPa, float $elevationM): float
    {
        return self::inToHPa(pow(pow(self::hPaToIn($pressureHPa), 0.1903) + (1.313E-5 * self::mToFt($elevationM)), 5.255));
    }

    /**
     * uwxutils' `PressureReductionRatio` with the 'paDavisVp' algorithm,
     * humidity correction included when the humidity is above zero.
     */
    private static function pressureReductionRatioDavis(float $elevationM, float $currentTempC, float $meanTempC, float $humidity): ?float
    {
        $hCorr = $humidity > 0
            ? (9.0 / 5.0) * self::humidityCorrectionDavis($currentTempC, $elevationM, $humidity)
            : 0;
        $denominator = 122.8943111 * (self::uwxCToF($meanTempC) + 460 + (self::mToFt($elevationM) * self::VP_LAPSE_RATE_US / 2) + $hCorr);
        if ($denominator === 0.0) {
            return null;
        }
        return self::finite(pow(10, self::mToFt($elevationM) / $denominator));
    }

    /** uwxutils' `HumidityCorrection` with the 'vaDavisVp' vapour pressure. */
    private static function humidityCorrectionDavis(float $tempC, float $elevationM, float $humidity): float
    {
        $vapPress = ($humidity * (6.112 * exp((17.62 * $tempC) / (243.12 + $tempC)))) / 100.0;
        return $vapPress * ((2.8322E-9 * ($elevationM ** 2)) + (2.225E-5 * $elevationM) + 0.10743);
    }

    /** uwxutils' `SaturationVaporPressure` with the 'vaTeten' algorithm, hPa. */
    public static function saturationVaporPressureTeten(float $tempC): float
    {
        return 6.1078 * pow(10, (7.5 * $tempC / ($tempC + 237.3)));
    }

    private static function etterm(float $elevMeter, float $tC): float
    {
        return exp(-$elevMeter / (Units::cToK($tC) * 29.263));
    }

    /** uwxutils' own Celsius to Fahrenheit, which differs from units.py's in the order of operations. */
    private static function uwxCToF(float $value): float
    {
        return (9.0 / 5.0) * $value + 32.0;
    }

    private static function inToHPa(float $value): float
    {
        return $value / 0.02953;
    }

    private static function hPaToIn(float $value): float
    {
        return $value * 0.02953;
    }

    private static function ftToM(float $value): float
    {
        return $value * 0.3048;
    }

    private static function mToFt(float $value): float
    {
        return $value / 0.3048;
    }

    /** A number, or null where Python would have raised on the way to it. */
    private static function finite(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }

    /**
     * Python's `round()` of a float to a whole number: a half goes to the
     * even neighbour. Not PHP's `round()`, which takes it away from zero
     * and, before PHP 8.4, first rounds the value to fifteen digits.
     */
    public static function roundHalfEven(float $value): float
    {
        if (!is_finite($value)) {
            return $value;
        }
        $floor = floor($value);
        // The fraction is exact: a float and its floor are within a factor
        // of two of each other whenever the difference matters.
        $fraction = $value - $floor;
        if ($fraction > 0.5) {
            return $floor + 1.0;
        }
        if ($fraction < 0.5) {
            return $floor;
        }
        return fmod($floor, 2.0) === 0.0 ? $floor : $floor + 1.0;
    }

    /** Geopotential altitude, kept for the algorithms that need it; unused by aaASOS. */
    public static function geopotentialAltitude(float $geometricAltitudeM): float
    {
        return (self::EARTH_RADIUS_45 * 1000 * $geometricAltitudeM) / ((self::EARTH_RADIUS_45 * 1000) + $geometricAltitudeM);
    }
}
