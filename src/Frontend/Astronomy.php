<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Astronomy\Lunar;
use WeewxPhp\Astronomy\Sky;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Weewx\Sun;

/** Bounded astronomy evaluation using this project's numerical kernels. */
final class Astronomy
{
    public const ANGLES = ['az' => 'azimuth', 'alt' => 'altitude', 'a_ra' => 'astro_ra', 'a_dec' => 'astro_dec', 'g_ra' => 'geo_ra', 'g_dec' => 'geo_dec', 'ra' => 'topo_ra', 'dec' => 'topo_dec', 'elong' => 'elongation', 'radius' => 'radius_size', 'hlong' => 'hlongitude', 'hlat' => 'hlatitude'];
    public const FIELDS = ['rise', 'set', 'transit', 'next_rising', 'previous_rising', 'next_setting', 'previous_setting', 'next_transit', 'previous_transit', 'next_antitransit', 'previous_antitransit', 'visible', 'visible_change', 'azimuth', 'altitude', 'astro_ra', 'astro_dec', 'geo_ra', 'geo_dec', 'topo_ra', 'topo_dec', 'elongation', 'radius_size', 'hlongitude', 'hlatitude', 'phase', 'moon_fullness', 'moon_phase', 'moon_index', 'moon_age', 'sidereal_time', 'sidereal_angle', 'earth_distance', 'sun_distance', 'size', 'mag', 'circumpolar', 'neverup', 'separation'];
    private const MOON = ['new_moon' => 0.0, 'first_quarter_moon' => 0.25, 'full_moon' => 0.5, 'last_quarter_moon' => 0.75];
    private const SEASONS = ['vernal_equinox' => [0], 'summer_solstice' => [1], 'autumnal_equinox' => [2], 'winter_solstice' => [3], 'equinox' => [0, 2], 'solstice' => [1, 3]];
    /** @var array<int, array{ra: float, dec: float, distance: float, radius: float}> */
    private array $samples = [];
    private readonly ?float $latitude;
    private readonly ?float $longitude;
    private readonly float $elevation;

    public function __construct(private readonly Spec $spec, private readonly ArchiveConfig $archive)
    {
        $this->latitude = $spec->latitude ?? $archive->latitude;
        $this->longitude = $spec->longitude ?? $archive->longitude;
        $this->elevation = $spec->elevation ?? $archive->altitude?->in('meter') ?? 0;
    }

    /** @return list<string> */
    public static function fields(): array
    {
        $fields = [...self::FIELDS, ...array_keys(self::ANGLES)];
        foreach (array_keys(self::MOON + self::SEASONS) as $event) {
            $fields[] = 'next_' . $event;
            $fields[] = 'previous_' . $event;
        }
        return $fields;
    }

    public static function validate(Spec $spec): void
    {
        Sky::body($spec->body);
        if ($spec->otherBody !== null) {
            Sky::body($spec->otherBody);
        }
        if (!in_array($spec->observation, self::fields(), true) || $spec->aggregate !== 'value' || $spec->every === 'archive'
            || ($spec->start !== null && ($spec->end === null || $spec->start >= $spec->end))
            || $spec->daysAgo < 0 || $spec->daysAgo > 36600
            || ($spec->observation === 'separation' && $spec->otherBody === null)) {
            throw new QueryError('Invalid astronomy query');
        }
        /** @var list<array{float|null, float, float}> $ranges */
        $ranges = [[$spec->horizon, -90.0, 90.0], [$spec->temperature, -100.0, 100.0], [$spec->pressure, 0.0, 1200.0], [$spec->latitude, -90.0, 90.0], [$spec->longitude, -180.0, 180.0], [$spec->elevation, -500.0, 10000.0]];
        foreach ($ranges as [$value, $min, $max]) {
            if ($value !== null && (!is_finite($value) || $value < $min || $value > $max)) {
                throw new QueryError('Invalid observer settings');
            }
        }
    }

    /** @return array{string|null, string|null} */
    public static function units(string $field): array
    {
        if (in_array($field, ['rise', 'set', 'transit'], true) || str_starts_with($field, 'next_') || str_starts_with($field, 'previous_')) {
            return ['unix_epoch', 'group_time'];
        }
        if (in_array($field, ['visible', 'visible_change', 'moon_age'], true)) {
            return ['second', 'group_deltatime'];
        }
        if (in_array($field, ['moon_fullness', 'phase'], true)) {
            return ['percent', 'group_percent'];
        }
        if ($field === 'moon_phase') {
            return [null, null];
        }
        if ($field === 'moon_index') {
            return ['count', 'group_count'];
        }
        if (in_array($field, ['neverup', 'circumpolar'], true)) {
            return ['boolean', 'group_boolean'];
        }
        if (in_array($field, ['earth_distance', 'sun_distance'], true)) {
            return ['astronomical_unit', 'group_distance'];
        }
        if ($field === 'mag') {
            return [null, null];
        }
        if ($field === 'size') {
            return ['arcsecond', 'group_angle'];
        }
        return [in_array($field, ['azimuth', 'topo_ra', 'geo_ra', 'astro_ra', 'hlongitude', 'sidereal_angle'], true) ? 'degree_compass' : 'degree_angle', 'group_angle'];
    }

    public function value(int $at): int|float|bool|string|null
    {
        $field = self::ANGLES[$this->spec->observation] ?? $this->spec->observation;
        $next = str_starts_with($field, 'next_');
        $event = preg_replace('/^(next|previous)_/', '', $field) ?? $field;
        if (isset(self::MOON[$event])) {
            return (int) round(Lunar::phase_event($at, self::MOON[$event], $next));
        }
        if (isset(self::SEASONS[$event])) {
            $times = array_map(static fn(int $quarter): int => Sky::season($at, $quarter, $next), self::SEASONS[$event]);
            return $next ? min($times) : max($times);
        }
        if (in_array($field, ['moon_phase', 'moon_index', 'moon_age'], true)) {
            $previous = Lunar::phase_event($at, 0, false);
            if ($field === 'moon_age') {
                return $at - $previous;
            }
            $nextMoon = Lunar::phase_event($at, 0, true);
            $index = ((int) (($at - $previous) / ($nextMoon - $previous) * 8 + 0.5)) % 8;
            return $field === 'moon_index' ? $index : ['New', 'Waxing crescent', 'First quarter', 'Waxing gibbous', 'Full', 'Waning gibbous', 'Last quarter', 'Waning crescent'][$index];
        }
        if ($field === 'sidereal_time' || $field === 'sidereal_angle') {
            return $this->longitude === null ? null : Sky::mod(Lunar::_gmst($at) + $this->longitude);
        }
        $body = $field === 'moon_fullness' ? 'moon' : $this->spec->body;
        if (in_array($field, ['hlongitude', 'hlatitude'], true)) {
            return Sky::heliocentric($body, $at)[$field === 'hlongitude' ? 0 : 1];
        }
        $p = Sky::position($body, $at);
        if ($field === 'earth_distance') {
            return $p['distance'] >= 1e14 ? null : $p['distance'];
        }
        if ($field === 'sun_distance') {
            return $p['sun_distance'] >= 1e14 ? null : $p['sun_distance'];
        }
        if ($field === 'phase' || $field === 'moon_fullness') {
            return $p['phase'];
        }
        if ($field === 'radius_size') {
            return $p['radius'];
        }
        if ($field === 'size') {
            return $p['radius'] * 7200;
        }
        if ($field === 'mag') {
            return $p['magnitude'];
        }
        if ($field === 'geo_ra') {
            return $p['ra'];
        }
        if ($field === 'geo_dec') {
            return $p['dec'];
        }
        if ($field === 'astro_ra' || $field === 'astro_dec') {
            return $p[$field];
        }
        if ($field === 'elongation' || $field === 'separation') {
            $second = Sky::position($field === 'elongation' ? 'sun' : ($this->spec->otherBody ?? 'sun'), $at);
            $angle = rad2deg(acos(max(-1.0, min(1.0, sin(deg2rad($p['dec'])) * sin(deg2rad($second['dec'])) + cos(deg2rad($p['dec'])) * cos(deg2rad($second['dec'])) * cos(deg2rad($p['ra'] - $second['ra']))))));
            return $field === 'elongation' && Sky::mod($p['longitude'] - $second['longitude']) > 180 ? -$angle : $angle;
        }
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }
        $top = Sky::topocentric($p['ra'], $p['dec'], $p['distance'], $at, $this->latitude, $this->longitude, $this->elevation);
        if ($field === 'altitude') {
            return Sun::apparent($top['altitude'], $this->spec->pressure, $this->spec->temperature);
        }
        if ($field === 'azimuth') {
            return $top['azimuth'];
        }
        if ($field === 'topo_ra') {
            return $top['ra'];
        }
        if ($field === 'topo_dec') {
            return $top['dec'];
        }
        if ($field === 'circumpolar') {
            return abs($p['dec'] - $this->latitude) < 90 && abs($p['dec'] + $this->latitude) > 90;
        }
        if ($field === 'neverup') {
            return abs($p['dec'] - $this->latitude) > 90;
        }
        $day = Span::calendar('day', $at + 1, $this->archive->timezone);
        if ($field === 'visible' || $field === 'visible_change') {
            $visible = $this->visible($day);
            if ($field === 'visible') {
                return $visible;
            }
            $then = Span::date($at, $this->archive->timezone)->modify('-' . $this->spec->daysAgo . ' days')->getTimestamp();
            return $visible - $this->visible(Span::calendar('day', $then + 1, $this->archive->timezone));
        }
        $kind = match ($event) {
            'rising', 'rise' => 'rise', 'setting', 'set' => 'set', 'transit' => 'transit', 'antitransit' => 'antitransit', default => throw new QueryError('Unknown astronomical event')
        };
        return $this->event(in_array($field, ['rise', 'set', 'transit'], true) ? $day->start : $at, $kind, !str_starts_with($field, 'previous_'));
    }

    /** Smooth positions on a two-hour grid; Earth rotation is evaluated at the exact instant. */
    private function signal(int $at, string $kind): float
    {
        $base = (int) (floor($at / 7200) * 7200);
        foreach ([$base - 7200, $base, $base + 7200] as $time) {
            if (!isset($this->samples[$time])) {
                $p = Sky::position($this->spec->body, $time);
                $this->samples[$time] = ['ra' => $p['ra'], 'dec' => $p['dec'], 'distance' => $p['distance'], 'radius' => $p['radius']];
            }
        }
        $n = ($at - $base) / 7200;
        $sample = [];
        foreach (['ra', 'dec', 'distance', 'radius'] as $field) {
            $b = $this->samples[$base][$field];
            $a = $this->samples[$base - 7200][$field];
            $c = $this->samples[$base + 7200][$field];
            if ($field === 'ra') {
                $a += 360 * round(($b - $a) / 360);
                $c += 360 * round(($b - $c) / 360);
            }
            $sample[$field] = $b + $n * ($c - $a) / 2 + $n * $n * ($c - 2 * $b + $a) / 2;
        }
        $top = Sky::topocentric($sample['ra'], $sample['dec'], $sample['distance'], $at, $this->latitude ?? 0, $this->longitude ?? 0, $this->elevation);
        if ($kind === 'transit') {
            return $top['hour_angle'];
        }
        if ($kind === 'antitransit') {
            return Sky::mod($top['hour_angle']) - 180;
        }
        return Sun::apparent($top['altitude'], $this->spec->pressure, $this->spec->temperature) + ($this->spec->useCenter ? 0 : $sample['radius']) - $this->spec->horizon;
    }

    private function event(int $from, string $kind, bool $next, int $span = 172800): ?int
    {
        $direction = $next ? 1 : -1;
        $previousAt = $from + $direction;
        $previous = $this->signal($previousAt, $kind);
        for ($i = 1; $i <= (int) ceil($span / 600); ++$i) {
            $at = $from + $direction * min($span, $i * 600);
            $value = $this->signal($at, $kind);
            $left = $next ? $previous : $value;
            $right = $next ? $value : $previous;
            $crossed = $kind === 'set' ? ($left >= 0 && $right < 0) : ($left <= 0 && $right > 0);
            if ($crossed && (!in_array($kind, ['transit', 'antitransit'], true) || abs($right - $left) < 180)) {
                $lo = min($at, $previousAt);
                $hi = max($at, $previousAt);
                while ($hi - $lo > 1) {
                    $mid = intdiv($lo + $hi, 2);
                    $above = $this->signal($mid, $kind) > 0;
                    if ($above === ($kind !== 'set')) {
                        $hi = $mid;
                    } else {
                        $lo = $mid;
                    }
                }
                return $hi;
            }
            $previous = $value;
            $previousAt = $at;
        }
        return null;
    }

    private function visible(Span $day): int
    {
        $at = $day->start;
        $seconds = 0;
        $up = $this->signal($at, 'rise') >= 0;
        // At most two rises/sets in one civil day, including lunar double-event days.
        for ($i = 0; $i < 6 && $at < $day->end; ++$i) {
            $event = $this->event($at, $up ? 'set' : 'rise', true, $day->end - $at) ?? $day->end;
            if ($up) {
                $seconds += $event - $at;
            }
            $at = $event;
            $up = !$up;
        }
        return $seconds;
    }
}
