<?php

declare(strict_types=1);

namespace WeewxPhp\Astronomy;

use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Weewx\Sun;

/** Shared geometry around the existing solar, lunar and planetary kernels. Angles are degrees. */
final class Sky
{
    private const PLANETS = ['mercury' => 'merkur', 'venus' => 'venus', 'mars' => 'mars', 'jupiter' => 'jupiter', 'saturn' => 'saturn', 'uranus' => 'uranus', 'neptune' => 'neptun', 'pluto' => 'pluto'];
    /** @var array<string, array{float, float, float, float, float}>|null */
    private static ?array $stars = null;

    public static function mod(float $angle): float
    {
        return $angle - 360 * floor($angle / 360);
    }

    /** @return list<string> */
    public static function bodies(): array
    {
        return ['sun', 'moon', ...array_keys(self::PLANETS), ...array_keys(self::stars())];
    }

    public static function body(string $name): string
    {
        $name = strtolower(str_replace(' ', '_', trim($name)));
        if (!in_array($name, self::bodies(), true)) {
            throw new QueryError('Unknown celestial body: ' . $name);
        }
        return $name;
    }

    /** @return array<string, array{float, float, float, float, float}> */
    private static function stars(): array
    {
        if (self::$stars !== null) {
            return self::$stars;
        }
        $text = file_get_contents(__DIR__ . '/stars.txt');
        if ($text === false) {
            throw new QueryError('Missing star catalogue');
        }
        $stars = [];
        foreach (explode("\n", $text) as $line) {
            $fields = explode(',', trim($line));
            if (count($fields) < 5) {
                continue;
            }
            $ra = explode('|', $fields[2]);
            $dec = explode('|', $fields[3]);
            $stars[strtolower(str_replace(' ', '_', $fields[0]))] = [(float) $ra[0] * 15, (float) $dec[0], (float) ($ra[1] ?? 0), (float) ($dec[1] ?? 0), (float) $fields[4]];
        }
        return self::$stars = $stars;
    }

    /** @return array{float, float} RA and declination; Meeus precession between two epochs. */
    public static function precess(float $ra, float $dec, float $from, float $to): array
    {
        $t0 = ($from - 946728000) / 86400 / 36525;
        $t = ($to - $from) / 86400 / 36525;
        $base = 2306.2181 + 1.39656 * $t0 - 0.000139 * $t0 ** 2;
        $zeta = deg2rad(($base * $t + (0.30188 - 0.000344 * $t0) * $t ** 2 + 0.017998 * $t ** 3) / 3600);
        $z = deg2rad(($base * $t + (1.09468 + 0.000066 * $t0) * $t ** 2 + 0.018203 * $t ** 3) / 3600);
        $theta = deg2rad(((2004.3109 - 0.85330 * $t0 - 0.000217 * $t0 ** 2) * $t - (0.42665 + 0.000217 * $t0) * $t ** 2 - 0.041833 * $t ** 3) / 3600);
        $a = deg2rad($ra) + $zeta;
        $d = deg2rad($dec);
        return [self::mod(rad2deg(atan2(cos($d) * sin($a), cos($theta) * cos($d) * cos($a) - sin($theta) * sin($d)) + $z)), rad2deg(asin(sin($theta) * cos($d) * cos($a) + cos($theta) * sin($d)))];
    }

    /** @return array{ra: float, dec: float, astro_ra: float, astro_dec: float, distance: float, longitude: float, latitude: float, radius: float, phase: float, sun_distance: float, magnitude: float|null} */
    public static function position(string $body, int $at): array
    {
        $body = self::body($body);
        $earth = Planeten::heliozentrisch($at, 'erde');
        $sunLon = self::mod($earth['laenge'] + 180);
        $eps = deg2rad(Lunar::_obliquity(Lunar::julian_centuries($at)));
        $magnitude = null;
        $astroRa = null;
        $astroDec = null;
        if ($body === 'sun') {
            // Solar coordinates share the existing NOAA declination and distance.
            [$dec, $eot, $distance] = Sun::solar($at);
            $sidereal = Lunar::_gmst($at);
            $minutes = ($at - 86400 * floor($at / 86400)) / 60;
            $ra = self::mod($sidereal - ($minutes + $eot) / 4 + 180);
            $lon = $sunLon;
            $lat = 0.0;
            $radius = 0.266563 / $distance;
            $phase = 100.0;
            $sunDistance = 0.0;
            $magnitude = -26.74 + 5 * log10($distance);
        } elseif ($body === 'moon') {
            [$lon, $lat, $km] = Lunar::position($at);
            [$ra, $dec] = Lunar::equatorial($at);
            $distance = $km / 149597870.7;
            $radius = rad2deg(asin(1737.4 / $km));
            $separation = acos(max(-1.0, min(1.0, cos(deg2rad($lat)) * cos(deg2rad($lon - $sunLon)))));
            $angle = atan2($earth['radius'] * sin($separation), $distance - $earth['radius'] * cos($separation));
            $phase = 50 * (1 + cos($angle));
            $sunDistance = sqrt($earth['radius'] ** 2 + $distance ** 2 - 2 * $earth['radius'] * $distance * cos($separation));
        } elseif (isset(self::PLANETS[$body])) {
            $name = self::PLANETS[$body];
            $p = Planeten::ekliptik($at, $name);
            $lon = $p['lambda'];
            $lat = $p['beta'];
            $distance = $p['distanz'];
            $l = deg2rad($lon);
            $b = deg2rad($lat);
            $ra = self::mod(rad2deg(atan2(sin($l) * cos($eps) - tan($b) * sin($eps), cos($l))));
            $dec = rad2deg(asin(sin($b) * cos($eps) + cos($b) * sin($eps) * sin($l)));
            $sunDistance = Planeten::heliozentrisch($at, $name)['radius'];
            $phase = 50 * (1 + max(-1.0, min(1.0, ($sunDistance ** 2 + $distance ** 2 - $earth['radius'] ** 2) / (2 * $sunDistance * $distance))));
            $km = ['mercury' => 2439.7, 'venus' => 6051.8, 'mars' => 3396.2, 'jupiter' => 71492.0, 'saturn' => 60268.0, 'uranus' => 25559.0, 'neptune' => 24764.0, 'pluto' => 1188.3][$body];
            $radius = rad2deg(asin($km / ($distance * 149597870.7)));
        } else {
            [$ra, $dec, $pmra, $pmdec, $magnitude] = self::stars()[$body];
            $years = ($at - 946728000) / 31557600;
            $ra += $pmra * $years / (3600000 * cos(deg2rad($dec)));
            $dec += $pmdec * $years / 3600000;
            $astroRa = $ra;
            $astroDec = $dec;
            [$ra, $dec] = self::precess($ra, $dec, 946728000, $at);
            [$ra, $dec] = Planeten::scheinbarerStern($at, $ra, $dec);
            $distance = 1.0e15;
            $sunDistance = $distance;
            $radius = 0.0;
            $phase = 100.0;
            $a = deg2rad($ra);
            $d = deg2rad($dec);
            $lon = self::mod(rad2deg(atan2(sin($a) * cos($eps) + tan($d) * sin($eps), cos($a))));
            $lat = rad2deg(asin(sin($d) * cos($eps) - cos($d) * sin($eps) * sin($a)));
        }
        if ($astroRa === null || $astroDec === null) {
            [$astroRa, $astroDec] = self::precess($ra, $dec, $at, 946728000);
        }
        return ['ra' => $ra, 'dec' => $dec, 'astro_ra' => $astroRa, 'astro_dec' => $astroDec, 'distance' => $distance, 'longitude' => $lon, 'latitude' => $lat, 'radius' => $radius, 'phase' => $phase, 'sun_distance' => $sunDistance, 'magnitude' => $magnitude];
    }

    /** @return array{ra: float, dec: float, altitude: float, azimuth: float, hour_angle: float} */
    public static function topocentric(float $ra, float $dec, float $distance, int $at, float $latitude, float $longitude, float $elevation): array
    {
        $phi = deg2rad($latitude);
        $d = deg2rad($dec);
        $h = deg2rad(self::mod(Lunar::_gmst($at) + $longitude - $ra));
        $u = atan(0.99664719 * tan($phi));
        $rhoSin = 0.99664719 * sin($u) + $elevation / 6378140 * sin($phi);
        $rhoCos = cos($u) + $elevation / 6378140 * cos($phi);
        $sinParallax = 6378.14 / ($distance * 149597870.7);
        $delta = atan2(-$rhoCos * $sinParallax * sin($h), cos($d) - $rhoCos * $sinParallax * cos($h));
        $topDec = atan2((sin($d) - $rhoSin * $sinParallax) * cos($delta), cos($d) - $rhoCos * $sinParallax * cos($h));
        $topH = $h - $delta;
        $alt = rad2deg(asin(max(-1.0, min(1.0, sin($phi) * sin($topDec) + cos($phi) * cos($topDec) * cos($topH)))));
        $az = self::mod(rad2deg(atan2(sin($topH), cos($topH) * sin($phi) - tan($topDec) * cos($phi))) + 180);
        return ['ra' => self::mod($ra + rad2deg($delta)), 'dec' => rad2deg($topDec), 'altitude' => $alt, 'azimuth' => $az, 'hour_angle' => self::mod(rad2deg($topH) + 180) - 180];
    }

    public static function season(int $at, int $quarter, bool $next): int
    {
        $year = (int) gmdate('Y', $at);
        $event = (int) Seasons::_season_moment($year, $quarter);
        if (($next && $event <= $at) || (!$next && $event >= $at)) {
            $event = (int) Seasons::_season_moment($year + ($next ? 1 : -1), $quarter);
        }
        return $event;
    }

    /** @return array{float, float, float} */
    public static function heliocentric(string $body, int $at): array
    {
        if ($body === 'sun') {
            $body = 'erde';
        } elseif (isset(self::PLANETS[$body])) {
            $body = self::PLANETS[$body];
        } else {
            throw new QueryError('Heliocentric coordinates require a planet or the Sun');
        }
        $p = Planeten::heliozentrisch($at, $body);
        return [$p['laenge'], $p['breite'], $p['radius']];
    }
}
