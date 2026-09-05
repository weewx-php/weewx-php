<?php

declare(strict_types=1);

namespace WeewxPhp\Measurement;

use InvalidArgumentException;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** Measurement semantics shared by ingest, mappings and display. */
final class Catalog
{
    public const KINDS = [
        'temperature' => 'group_temperature', 'humidity' => 'group_percent',
        'station_pressure' => 'group_pressure', 'sea_level_pressure' => 'group_pressure',
        'rain' => 'group_rain', 'rain_counter' => 'group_rain', 'rain_rate' => 'group_rainrate',
        'speed' => 'group_speed', 'direction' => 'group_direction',
        'radiation' => 'group_radiation', 'uv' => 'group_uv', 'soil_moisture' => 'group_moisture',
        'count' => 'group_count', 'voltage' => 'group_volt', 'concentration' => 'group_concentration',
        'fraction' => 'group_fraction', 'distance' => 'group_distance', 'duration' => 'group_deltatime',
        'illuminance' => 'group_illuminance', 'power' => 'group_power', 'energy' => 'group_energy',
        'soil_moisture_percent' => 'group_percent', 'conductivity' => 'group_conductivity',
        'pressure_deficit' => 'group_pressure_deficit', 'battery_level' => 'group_count',
        'battery_status' => 'group_boolean', 'rain_window' => 'group_rain',
        'speed_max' => 'group_speed', 'direction_window' => 'group_direction', 'count_counter' => 'group_count',
    ];

    /** Additional observations and semantics; standard WeeWX unit groups stay intact. */
    public static function extensionKind(string $name): ?string
    {
        if (preg_match('/^soilMoistPct([1-9]|1[0-6])$/D', $name) === 1) {
            return 'soil_moisture_percent';
        }
        if (preg_match('/^soilEC([1-9]|1[0-6])$/D', $name) === 1) {
            return 'conductivity';
        }
        if (preg_match('/^(wn34|wh51|wh52)_ch([1-9]|1[0-6])_batt$/D', $name) === 1) {
            return 'voltage';
        }
        return match ($name) {
            'vpd' => 'pressure_deficit',
            'lightning_Batt' => 'battery_level',
            'lightningBatteryStatus', 'outTempBatteryStatus' => 'battery_status',
            'wh80_batt', 'wh90_batt' => 'voltage',
            'rain24', 'hourRain' => 'rain_window',
            'maxdailygust' => 'speed_max',
            'windDir10' => 'direction_window',
            'lightningDayCount' => 'count_counter',
            default => null,
        };
    }

    /** State readings and console totals represent the latest snapshot. */
    public static function lastKind(?string $kind): bool
    {
        return in_array($kind, ['rain_counter', 'rain_window', 'speed_max', 'direction_window',
            'count_counter', 'battery_level', 'battery_status', 'voltage'], true);
    }

    public static function identifier(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) !== 1
            || in_array(strtolower($name), ['datetime', 'usunits', 'interval'], true)) {
            throw new InvalidArgumentException('Invalid observation name');
        }
    }

    /** @param array<string, string> $custom */
    public static function kind(string $name, array $custom = []): ?string
    {
        if (isset($custom[$name])) {
            return $custom[$name];
        }
        $extension = self::extensionKind($name);
        if ($extension !== null) {
            return $extension;
        }
        if ($name === 'pressure') {
            return 'station_pressure';
        }
        if (in_array($name, ['barometer', 'altimeter'], true)) {
            return 'sea_level_pressure';
        }
        if (in_array($name, ['dayRain', 'hourRain', 'weekRain', 'monthRain', 'yearRain', 'totalRain', 'eventRain'], true)) {
            return 'rain_counter';
        }
        $group = Units::groupOf($name);
        $kind = array_search($group, self::KINDS, true);
        return $kind === false ? null : $kind;
    }

    /** @param array<string, string> $kinds
     * @return array<string, string> */
    public static function groups(array $kinds): array
    {
        $groups = [];
        foreach ($kinds as $name => $kind) {
            $groups[$name] = self::KINDS[$kind] ?? throw new InvalidArgumentException('Unknown measurement type');
        }
        return $groups;
    }

    public static function validate(string $name, string $kind): void
    {
        self::identifier($name);
        $group = self::KINDS[$kind] ?? throw new InvalidArgumentException('Unknown measurement type');
        $existing = Units::groupOf($name);
        if ($existing !== null && (self::kind($name) !== $kind || $existing !== $group)) {
            throw new InvalidArgumentException('Built-in measurement type cannot be changed');
        }
    }

    public static function validateUnit(string $kind, string $unit): void
    {
        $group = self::KINDS[$kind] ?? throw new InvalidArgumentException('Unknown measurement type');
        foreach (UnitSystem::cases() as $system) {
            if (!Units::canConvert($unit, Units::standardUnit($system, $group))) {
                throw new InvalidArgumentException('Unit does not match the measurement type');
            }
        }
    }

    /** @param array<string, string> $custom */
    public static function compatible(string $source, string $target, array $custom = []): bool
    {
        $from = self::kind($source, $custom);
        $to = self::kind($target, $custom);
        return $source === $target || ($from !== null && ($from === $to || ($from === 'rain_counter' && $target === 'rain')));
    }
}
