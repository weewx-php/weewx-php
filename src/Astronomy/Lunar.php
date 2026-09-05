<?php

declare(strict_types=1);

namespace WeewxPhp\Astronomy;

/** Numerical kernel ported from our weewx-evo/moon.py. See SOURCES.md. */
final class Lunar
{
    private const EARTH_RADIUS = 6378.14;

    private const LONGITUDE_TERMS = [[0, 0, 1, 0, 6288774, -(20905355)], [2, 0, -(1), 0, 1274027, -(3699111)], [2, 0, 0, 0, 658314, -(2955968)], [0, 0, 2, 0, 213618, -(569925)], [0, 1, 0, 0, -(185116), 48888], [0, 0, 0, 2, -(114332), -(3149)], [2, 0, -(2), 0, 58793, 246158], [2, -(1), -(1), 0, 57066, -(152138)], [2, 0, 1, 0, 53322, -(170733)], [2, -(1), 0, 0, 45758, -(204586)], [0, 1, -(1), 0, -(40923), -(129620)], [1, 0, 0, 0, -(34720), 108743], [0, 1, 1, 0, -(30383), 104755], [2, 0, 0, -(2), 15327, 10321], [0, 0, 1, 2, -(12528), 0], [0, 0, 1, -(2), 10980, 79661], [4, 0, -(1), 0, 10675, -(34782)], [0, 0, 3, 0, 10034, -(23210)], [4, 0, -(2), 0, 8548, -(21636)], [2, 1, -(1), 0, -(7888), 24208], [2, 1, 0, 0, -(6766), 30824], [1, 0, -(1), 0, -(5163), -(8379)], [1, 1, 0, 0, 4987, -(16675)], [2, -(1), 1, 0, 4036, -(12831)], [2, 0, 2, 0, 3994, -(10445)], [4, 0, 0, 0, 3861, -(11650)], [2, 0, -(3), 0, 3665, 14403], [0, 1, -(2), 0, -(2689), -(7003)], [2, 0, -(1), 2, -(2602), 0], [2, -(1), -(2), 0, 2390, 10056], [1, 0, 1, 0, -(2348), 6322], [2, -(2), 0, 0, 2236, -(9884)], [0, 1, 2, 0, -(2120), 5751], [0, 2, 0, 0, -(2069), 0], [2, -(2), -(1), 0, 2048, -(4950)], [2, 0, 1, -(2), -(1773), 4130], [2, 0, 0, 2, -(1595), 0], [4, -(1), -(1), 0, 1215, -(3958)], [0, 0, 2, 2, -(1110), 0], [3, 0, -(1), 0, -(892), 3258], [2, 1, 1, 0, -(810), 2616], [4, -(1), -(2), 0, 759, -(1897)], [0, 2, -(1), 0, -(713), -(2117)], [2, 2, -(1), 0, -(700), 2354], [2, 1, -(2), 0, 691, 0], [2, -(1), 0, -(2), 596, 0], [4, 0, 1, 0, 549, -(1423)], [0, 0, 4, 0, 537, -(1117)], [4, -(1), 0, 0, 520, -(1571)], [1, 0, -(2), 0, -(487), -(1739)], [2, 1, 0, -(2), -(399), 0], [0, 0, 2, -(2), -(381), -(4421)], [1, 1, 1, 0, 351, 0], [3, 0, -(2), 0, -(340), 0], [4, 0, -(3), 0, 330, 0], [2, -(1), 2, 0, 327, 0], [0, 2, 1, 0, -(323), 1165], [1, 1, -(1), 0, 299, 0], [2, 0, 3, 0, 294, 0], [2, 0, -(1), -(2), 0, 8752]];

    private const LATITUDE_TERMS = [[0, 0, 0, 1, 5128122], [0, 0, 1, 1, 280602], [0, 0, 1, -(1), 277693], [2, 0, 0, -(1), 173237], [2, 0, -(1), 1, 55413], [2, 0, -(1), -(1), 46271], [2, 0, 0, 1, 32573], [0, 0, 2, 1, 17198], [2, 0, 1, -(1), 9266], [0, 0, 2, -(1), 8822], [2, -(1), 0, -(1), 8216], [2, 0, -(2), -(1), 4324], [2, 0, 1, 1, 4200], [2, 1, 0, -(1), -(3359)], [2, -(1), -(1), 1, 2463], [2, -(1), 0, 1, 2211], [2, -(1), -(1), -(1), 2065], [0, 1, -(1), -(1), -(1870)], [4, 0, -(1), -(1), 1828], [0, 1, 0, 1, -(1794)], [0, 0, 0, 3, -(1749)], [0, 1, -(1), 1, -(1565)], [1, 0, 0, 1, -(1491)], [0, 1, 1, 1, -(1475)], [0, 1, 1, -(1), -(1410)], [0, 1, 0, -(1), -(1344)], [1, 0, 0, -(1), -(1335)], [0, 0, 3, 1, 1107], [4, 0, 0, -(1), 1021], [4, 0, -(1), 1, 833], [0, 0, 1, -(3), 777], [4, 0, -(2), 1, 671], [2, 0, 0, -(3), 607], [2, 0, 2, -(1), 596], [2, -(1), 1, -(1), 491], [2, 0, -(2), 1, -(451)], [0, 0, 3, -(1), 439], [2, 0, 2, 1, 422], [2, 0, -(3), -(1), 421], [2, 1, -(1), 1, -(366)], [2, 1, 0, 1, -(351)], [4, 0, 0, 1, 331], [2, -(1), 1, 1, 315], [2, -(2), 0, -(1), 302], [0, 0, 1, 3, -(283)], [2, 1, 1, -(1), -(229)], [1, 1, 0, -(1), 223], [1, 1, 0, 1, 223], [0, 1, -(2), -(1), -(220)], [2, 1, -(1), -(1), -(220)], [1, 0, 1, 1, -(185)], [2, -(1), -(2), -(1), 181], [0, 1, 2, 1, -(177)], [4, 0, -(2), -(1), 176], [4, -(1), -(1), -(1), 166], [1, 0, 1, -(1), -(164)], [4, 0, 1, -(1), 132], [1, 0, -(1), -(1), -(119)], [4, -(1), 0, -(1), 115], [2, -(2), 0, 1, 107]];

    public static function julian_centuries(float $when): float
    {
        return (((($when / 86400.0) + 2440587.5) - 2451545.0) / 36525.0);
    }

    /** @return array{float, float, float} */
    public static function position(float $when): array
    {
        $t = self::julian_centuries($when);
        $mean_long = ((((218.3164477 + (481267.88123421 * $t)) - (0.0015786 * ($t ** 2))) + (($t ** 3) / 538841.0)) - (($t ** 4) / 65194000.0));
        $elongation = ((((297.8501921 + (445267.1114034 * $t)) - (0.0018819 * ($t ** 2))) + (($t ** 3) / 545868.0)) - (($t ** 4) / 113065000.0));
        $sun_anomaly = (((357.5291092 + (35999.0502909 * $t)) - (0.0001536 * ($t ** 2))) + (($t ** 3) / 24490000.0));
        $moon_anomaly = ((((134.9633964 + (477198.8675055 * $t)) + (0.0087414 * ($t ** 2))) + (($t ** 3) / 69699.0)) - (($t ** 4) / 14712000.0));
        $argument = ((((93.272095 + (483202.0175233 * $t)) - (0.0036539 * ($t ** 2))) - (($t ** 3) / 3526000.0)) + (($t ** 4) / 863310000.0));
        $a1 = (119.75 + (131.849 * $t));
        $a2 = (53.09 + (479264.29 * $t));
        $a3 = (313.45 + (481266.484 * $t));
        $e = ((1.0 - (0.002516 * $t)) - (7.4e-06 * ($t ** 2)));
        $d = deg2rad($elongation);
        $m = deg2rad($sun_anomaly);
        $mp = deg2rad($moon_anomaly);
        $f = deg2rad($argument);
        $sum_l = 0.0;
        $sum_r = 0.0;
        foreach (self::LONGITUDE_TERMS as [$cd, $cm, $cmp, $cf, $coeff_l, $coeff_r]) {
            $angle = (((($cd * $d) + ($cm * $m)) + ($cmp * $mp)) + ($cf * $f));
            $scale = ($e ** abs($cm));
            $sum_l += (($coeff_l * $scale) * sin($angle));
            $sum_r += (($coeff_r * $scale) * cos($angle));
        }
        $sum_b = 0.0;
        foreach (self::LATITUDE_TERMS as [$cd, $cm, $cmp, $cf, $coeff_b]) {
            $angle = (((($cd * $d) + ($cm * $m)) + ($cmp * $mp)) + ($cf * $f));
            $sum_b += (($coeff_b * ($e ** abs($cm))) * sin($angle));
        }
        $sum_l += (((3958.0 * sin(deg2rad($a1))) + (1962.0 * sin((deg2rad($mean_long) - $f)))) + (318.0 * sin(deg2rad($a2))));
        $sum_b += ((((((-(2235.0) * sin(deg2rad($mean_long))) + (382.0 * sin(deg2rad($a3)))) + (175.0 * sin((deg2rad($a1) - $f)))) + (175.0 * sin((deg2rad($a1) + $f)))) + (127.0 * sin((deg2rad($mean_long) - $mp)))) - (115.0 * sin((deg2rad($mean_long) + $mp))));
        $longitude = self::mod(($mean_long + ($sum_l / 1000000.0)), 360.0);
        $latitude = ($sum_b / 1000000.0);
        $distance = (385000.56 + ($sum_r / 1000.0));
        return [$longitude, $latitude, $distance];
    }

    /** @return array{float, float, float} */
    public static function equatorial(float $when): array
    {
        [$longitude, $latitude, $distance] = self::position($when);
        $obliquity = self::_obliquity(self::julian_centuries($when));
        $lam = deg2rad($longitude);
        $beta = deg2rad($latitude);
        $eps = deg2rad($obliquity);
        $right_ascension = self::mod(rad2deg(atan2(((sin($lam) * cos($eps)) - (tan($beta) * sin($eps))), cos($lam))), 360.0);
        $declination = rad2deg(asin(((sin($beta) * cos($eps)) + ((cos($beta) * sin($eps)) * sin($lam)))));
        $parallax = rad2deg(asin((self::EARTH_RADIUS / $distance)));
        return [$right_ascension, $declination, $parallax];
    }

    public static function phase_event(float $when, float $phase, bool $forwards = true): float
    {
        $year = (2000.0 + (((($when / 86400.0) + 2440587.5) - 2451545.0) / 365.25));
        $k = (floor((($year - 2000.0) * 12.3685)) + $phase);
        for ($i = 0; $i < 4; ++$i) {
            $found = self::_phase_moment($k);
            if (($forwards && ($found > $when))) {
                return $found;
            }
            if ((!($forwards) && ($found < $when))) {
                return $found;
            }
            $k += ($forwards ? 1.0 : -(1.0));
        }
        return self::_phase_moment($k);
    }

    public static function _phase_moment(float $k): float
    {
        $t = ($k / 1236.85);
        $jde = ((((2451550.09766 + (29.530588861 * $k)) + (0.00015437 * ($t ** 2))) - (1.5e-07 * ($t ** 3))) + (7.3e-10 * ($t ** 4)));
        $e = ((1.0 - (0.002516 * $t)) - (7.4e-06 * ($t ** 2)));
        $sun_anomaly = deg2rad((((2.5534 + (29.1053567 * $k)) - (1.4e-06 * ($t ** 2))) - (1.1e-07 * ($t ** 3))));
        $moon_anomaly = deg2rad(((((201.5643 + (385.81693528 * $k)) + (0.0107582 * ($t ** 2))) + (1.238e-05 * ($t ** 3))) - (5.8e-08 * ($t ** 4))));
        $argument = deg2rad(((((160.7108 + (390.67050284 * $k)) - (0.0016118 * ($t ** 2))) - (2.27e-06 * ($t ** 3))) + (1.1e-08 * ($t ** 4))));
        $node = deg2rad((((124.7746 - (1.56375588 * $k)) + (0.0020672 * ($t ** 2))) + (2.15e-06 * ($t ** 3))));
        $quarter = self::mod(round((self::mod($k, 1.0) * 4.0)), 4);
        if (($quarter === 0.0)) {
            $correction = ((((((((((((((((-(0.4072) * sin($moon_anomaly)) + ((0.17241 * $e) * sin($sun_anomaly))) + (0.01608 * sin((2 * $moon_anomaly)))) + (0.01039 * sin((2 * $argument)))) + ((0.00739 * $e) * sin(($moon_anomaly - $sun_anomaly)))) - ((0.00514 * $e) * sin(($moon_anomaly + $sun_anomaly)))) + (((0.00208 * $e) * $e) * sin((2 * $sun_anomaly)))) - (0.00111 * sin(($moon_anomaly - (2 * $argument))))) - (0.00057 * sin(($moon_anomaly + (2 * $argument))))) + ((0.00056 * $e) * sin(((2 * $moon_anomaly) + $sun_anomaly)))) - (0.00042 * sin((3 * $moon_anomaly)))) + ((0.00042 * $e) * sin(($sun_anomaly + (2 * $argument))))) + ((0.00038 * $e) * sin(($sun_anomaly - (2 * $argument))))) - ((0.00024 * $e) * sin(((2 * $moon_anomaly) - $sun_anomaly)))) - (0.00017 * sin($node))) - (7e-05 * sin(($moon_anomaly + (2 * $sun_anomaly)))));
        } else {
            if (($quarter === 2.0)) {
                $correction = ((((((((((((((((-(0.40614) * sin($moon_anomaly)) + ((0.17302 * $e) * sin($sun_anomaly))) + (0.01614 * sin((2 * $moon_anomaly)))) + (0.01043 * sin((2 * $argument)))) + ((0.00734 * $e) * sin(($moon_anomaly - $sun_anomaly)))) - ((0.00515 * $e) * sin(($moon_anomaly + $sun_anomaly)))) + (((0.00209 * $e) * $e) * sin((2 * $sun_anomaly)))) - (0.00111 * sin(($moon_anomaly - (2 * $argument))))) - (0.00057 * sin(($moon_anomaly + (2 * $argument))))) + ((0.00056 * $e) * sin(((2 * $moon_anomaly) + $sun_anomaly)))) - (0.00042 * sin((3 * $moon_anomaly)))) + ((0.00042 * $e) * sin(($sun_anomaly + (2 * $argument))))) + ((0.00038 * $e) * sin(($sun_anomaly - (2 * $argument))))) - ((0.00024 * $e) * sin(((2 * $moon_anomaly) - $sun_anomaly)))) - (0.00017 * sin($node))) - (7e-05 * sin(($moon_anomaly + (2 * $sun_anomaly)))));
            } else {
                $correction = ((((((((((((((((-(0.62801) * sin($moon_anomaly)) + ((0.17172 * $e) * sin($sun_anomaly))) - ((0.01183 * $e) * sin(($moon_anomaly + $sun_anomaly)))) + (0.00862 * sin((2 * $moon_anomaly)))) + (0.00804 * sin((2 * $argument)))) + ((0.00454 * $e) * sin(($moon_anomaly - $sun_anomaly)))) + (((0.00204 * $e) * $e) * sin((2 * $sun_anomaly)))) - (0.0018 * sin(($moon_anomaly - (2 * $argument))))) - (0.0007 * sin(($moon_anomaly + (2 * $argument))))) - (0.0004 * sin((3 * $moon_anomaly)))) - ((0.00034 * $e) * sin(((2 * $moon_anomaly) - $sun_anomaly)))) + ((0.00032 * $e) * sin(($sun_anomaly + (2 * $argument))))) + ((0.00032 * $e) * sin(($sun_anomaly - (2 * $argument))))) - (((0.00028 * $e) * $e) * sin(($moon_anomaly + (2 * $sun_anomaly))))) + ((0.00027 * $e) * sin(((2 * $moon_anomaly) + $sun_anomaly)))) - (0.00017 * sin($node)));
                $w = (((((0.00306 - ((0.00038 * $e) * cos($sun_anomaly))) + (0.00026 * cos($moon_anomaly))) - (2e-05 * cos(($moon_anomaly - $sun_anomaly)))) + (2e-05 * cos(($moon_anomaly + $sun_anomaly)))) + (2e-05 * cos((2 * $argument))));
                $correction += (($quarter === 1.0) ? $w : -($w));
            }
        }
        $jde += ($correction + self::_additional($k, $t));
        return ((($jde - 2440587.5) * 86400.0) - self::_delta_t($k));
    }

    public static function _additional(float $k, float $t): float
    {
        $a = [[((299.77 + (0.107408 * $k)) - (0.009173 * ($t ** 2))), 0.000325], [(251.88 + (0.016321 * $k)), 0.000165], [(251.83 + (26.651886 * $k)), 0.000164], [(349.42 + (36.412478 * $k)), 0.000126], [(84.66 + (18.206239 * $k)), 0.00011], [(141.74 + (53.303771 * $k)), 6.2e-05], [(207.14 + (2.453732 * $k)), 6e-05], [(154.84 + (7.30686 * $k)), 5.6e-05], [(34.52 + (27.261239 * $k)), 4.7e-05], [(207.19 + (0.121824 * $k)), 4.2e-05], [(291.34 + (1.844379 * $k)), 4e-05], [(161.72 + (24.198154 * $k)), 3.7e-05], [(239.56 + (25.513099 * $k)), 3.5e-05], [(331.55 + (3.592518 * $k)), 2.3e-05]];
        $total = 0.0;
        foreach ($a as [$angle, $size]) {
            $total += ($size * sin(deg2rad($angle)));
        }
        return $total;
    }

    public static function _delta_t(float $k): float
    {
        return Seasons::delta_t((2000.0 + ($k / 12.3685)));
    }

    public static function _obliquity(float $t): float
    {
        $mean = (23.0 + ((26.0 + ((21.448 - ($t * (46.815 + ($t * (0.00059 - ($t * 0.001813)))))) / 60.0)) / 60.0));
        $omega = deg2rad((125.04452 - (1934.136261 * $t)));
        return ($mean + (0.00256 * cos($omega)));
    }

    public static function _gmst(float $when): float
    {
        $jd = (($when / 86400.0) + 2440587.5);
        $t = (($jd - 2451545.0) / 36525.0);
        return self::mod((((280.46061837 + (360.98564736629 * ($jd - 2451545.0))) + (0.000387933 * ($t ** 2))) - (($t ** 3) / 38710000.0)), 360.0);
    }

    private static function mod(float $a, float $b): float
    {
        return $a - $b * floor($a / $b);
    }
}
