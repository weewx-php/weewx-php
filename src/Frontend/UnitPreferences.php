<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** Visitor display units are independent of archive storage and language. */
final class UnitPreferences
{
    public const COOKIE = 'weewx_units';
    public const PROFILES = ['metric' => 'Metric', 'us' => 'US', 'metricwx' => 'Metric (m/s)'];

    public function __construct(public readonly string $profile = 'metric', public readonly string $selection = 'station')
    {
        if (!isset(self::PROFILES[$profile]) || ($selection !== 'station' && !isset(self::PROFILES[$selection]))) {
            throw new QueryError('Unknown display unit profile');
        }
    }

    /** Invalid HTTP input falls back to the saved selection, then the station default.
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $cookies
     */
    public static function resolve(array $query, array $cookies = [], string $default = 'metric'): self
    {
        if (!isset(self::PROFILES[$default])) {
            throw new QueryError('Unknown default display unit profile');
        }
        $requested = self::choice($query['units'] ?? null);
        $selection = $requested ?? self::choice($cookies[self::COOKIE] ?? null) ?? 'station';
        return new self($selection === 'station' ? $default : $selection, $selection);
    }

    public static function choice(mixed $value): ?string
    {
        return is_string($value) && ($value === 'station' || isset(self::PROFILES[$value])) ? $value : null;
    }

    /** No Path attribute: the browser scopes the preference to the public page directory. */
    public static function cookie(string $selection, bool $secure = false): string
    {
        if (self::choice($selection) === null) {
            throw new QueryError('Unknown display unit selection');
        }
        return self::COOKIE . '=' . ($selection === 'station' ? '' : $selection)
            . '; Max-Age=' . ($selection === 'station' ? '0' : '31536000')
            . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }

    /** Explicit units and precision in the formatter override profile defaults. */
    public function output(Output $format = new Output()): Output
    {
        $system = match ($this->profile) {
            'us' => UnitSystem::US, 'metricwx' => UnitSystem::METRICWX, default => UnitSystem::METRIC,
        };
        $units = [];
        foreach (['altitude', 'degree_day', 'distance', 'length', 'pressure', 'pressurerate', 'rain', 'rainrate',
            'speed', 'speed2', 'temperature', 'volume'] as $group) {
            $units['group_' . $group] = Units::standardUnit($system, 'group_' . $group);
        }
        // Display metric rainfall in millimetres; WeeWX METRIC storage uses centimetres.
        if ($this->profile === 'metric') {
            $units['group_rain'] = 'mm';
            $units['group_rainrate'] = 'mm_per_hour';
        }
        return new Output(
            $format->language,
            $format->units + $units,
            $format->decimals + ['inHg' => 2, 'inHg_per_hour' => 3, 'inch' => 2, 'inch_per_hour' => 2],
            $format->missing,
            $format->dateFormat,
            $format->labels,
        );
    }
}
