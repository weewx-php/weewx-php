<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use Closure;

/**
 * What unit a reading is in, and how to convert it.
 *
 * A transcription of `weewx.units`, expression by expression, including the
 * table's own inconsistencies: `mile_per_hour` to `km_per_hour` uses
 * 1.609344 while the way back uses 1000/1609.34, and 52 pairs do not come
 * back exactly. That is inherited on purpose. A record converted here has
 * to hold the number WeeWX would have written, and a "cleaner" factor is a
 * value that differs from it in the third decimal.
 */
final class Units
{
    public const INHG_PER_MBAR = 0.0295299875;
    public const MM_PER_INCH = 25.4;
    public const CM_PER_INCH = self::MM_PER_INCH / 10.0;
    public const METER_PER_MILE = 1609.34;
    public const METER_PER_FOOT = self::METER_PER_MILE / 5280.0;
    public const MILE_PER_KM = 1000.0 / self::METER_PER_MILE;
    public const SECS_PER_DAY = 86400;

    /** Observation type to unit group, as `obs_group_dict` in WeeWX 5.5. */
    private const OBS_GROUP = [
        'altimeter' => 'group_pressure',
        'altimeterRate' => 'group_pressurerate',
        'altitude' => 'group_altitude',
        'appTemp' => 'group_temperature',
        'appTemp1' => 'group_temperature',
        'barometer' => 'group_pressure',
        'barometerRate' => 'group_pressurerate',
        'beaufort' => 'group_count',
        'cloudbase' => 'group_altitude',
        'cloudcover' => 'group_percent',
        'co' => 'group_fraction',
        'co2' => 'group_fraction',
        'consBatteryVoltage' => 'group_volt',
        'cooldeg' => 'group_degree_day',
        'dateTime' => 'group_time',
        'dayRain' => 'group_rain',
        'daySunshineDur' => 'group_deltatime',
        'dewpoint' => 'group_temperature',
        'dewpoint1' => 'group_temperature',
        'ET' => 'group_rain',
        'extraHumid1' => 'group_percent',
        'extraHumid2' => 'group_percent',
        'extraHumid3' => 'group_percent',
        'extraHumid4' => 'group_percent',
        'extraHumid5' => 'group_percent',
        'extraHumid6' => 'group_percent',
        'extraHumid7' => 'group_percent',
        'extraHumid8' => 'group_percent',
        'extraTemp1' => 'group_temperature',
        'extraTemp2' => 'group_temperature',
        'extraTemp3' => 'group_temperature',
        'extraTemp4' => 'group_temperature',
        'extraTemp5' => 'group_temperature',
        'extraTemp6' => 'group_temperature',
        'extraTemp7' => 'group_temperature',
        'extraTemp8' => 'group_temperature',
        'growdeg' => 'group_degree_day',
        'gustdir' => 'group_direction',
        'hail' => 'group_rain',
        'hailRate' => 'group_rainrate',
        'heatdeg' => 'group_degree_day',
        'heatindex' => 'group_temperature',
        'heatindex1' => 'group_temperature',
        'heatingTemp' => 'group_temperature',
        'heatingVoltage' => 'group_volt',
        'highOutTemp' => 'group_temperature',
        'hourRain' => 'group_rain',
        'humidex' => 'group_temperature',
        'humidex1' => 'group_temperature',
        'illuminance' => 'group_illuminance',
        'inDewpoint' => 'group_temperature',
        'inHumidity' => 'group_percent',
        'inTemp' => 'group_temperature',
        'interval' => 'group_interval',
        'leafTemp1' => 'group_temperature',
        'leafTemp2' => 'group_temperature',
        'leafTemp3' => 'group_temperature',
        'leafTemp4' => 'group_temperature',
        'leafWet1' => 'group_count',
        'leafWet2' => 'group_count',
        'lightning_distance' => 'group_distance',
        'lightning_disturber_count' => 'group_count',
        'lightning_noise_count' => 'group_count',
        'lightning_strike_count' => 'group_count',
        'lowOutTemp' => 'group_temperature',
        'maxSolarRad' => 'group_radiation',
        'monthRain' => 'group_rain',
        'nh3' => 'group_fraction',
        'no2' => 'group_concentration',
        'noise' => 'group_db',
        'o3' => 'group_fraction',
        'outHumidity' => 'group_percent',
        'outTemp' => 'group_temperature',
        'outWetbulb' => 'group_temperature',
        'pb' => 'group_fraction',
        'pm1_0' => 'group_concentration',
        'pm2_5' => 'group_concentration',
        'pm10_0' => 'group_concentration',
        'pop' => 'group_percent',
        'pressure' => 'group_pressure',
        'pressureRate' => 'group_pressurerate',
        'radiation' => 'group_radiation',
        'rain' => 'group_rain',
        'rain24' => 'group_rain',
        'rainDur' => 'group_deltatime',
        'rainRate' => 'group_rainrate',
        'referenceVoltage' => 'group_volt',
        'rms' => 'group_speed2',
        'rxCheckPercent' => 'group_percent',
        'snow' => 'group_rain',
        'snowDepth' => 'group_rain',
        'snowMoisture' => 'group_percent',
        'snowRate' => 'group_rainrate',
        'so2' => 'group_fraction',
        'soilMoist1' => 'group_moisture',
        'soilMoist2' => 'group_moisture',
        'soilMoist3' => 'group_moisture',
        'soilMoist4' => 'group_moisture',
        'soilTemp1' => 'group_temperature',
        'soilTemp2' => 'group_temperature',
        'soilTemp3' => 'group_temperature',
        'soilTemp4' => 'group_temperature',
        'stormRain' => 'group_rain',
        'stormStart' => 'group_time',
        'sunshineDur' => 'group_deltatime',
        'supplyVoltage' => 'group_volt',
        'THSW' => 'group_temperature',
        'totalRain' => 'group_rain',
        'UV' => 'group_uv',
        'vecavg' => 'group_speed2',
        'vecdir' => 'group_direction',
        'wind' => 'group_speed',
        'windchill' => 'group_temperature',
        'windDir' => 'group_direction',
        'windDir10' => 'group_direction',
        'windGust' => 'group_speed',
        'windGustDir' => 'group_direction',
        'windgustvec' => 'group_speed',
        'windrun' => 'group_distance',
        'windSpeed' => 'group_speed',
        'windSpeed10' => 'group_speed',
        'windvec' => 'group_speed',
        'yearRain' => 'group_rain',
    ];

    /** Unit group to unit in the US customary system: `USUnits`. */
    private const US_UNITS = [
        'group_altitude' => 'foot',
        'group_amp' => 'amp',
        'group_angle' => 'degree_angle',
        'group_boolean' => 'boolean',
        'group_concentration' => 'microgram_per_meter_cubed',
        'group_count' => 'count',
        'group_data' => 'byte',
        'group_db' => 'dB',
        'group_degree_day' => 'degree_F_day',
        'group_deltatime' => 'second',
        'group_direction' => 'degree_compass',
        'group_distance' => 'mile',
        'group_elapsed' => 'second',
        'group_energy' => 'watt_hour',
        'group_energy2' => 'watt_second',
        'group_fraction' => 'ppm',
        'group_frequency' => 'hertz',
        'group_illuminance' => 'lux',
        'group_interval' => 'minute',
        'group_length' => 'inch',
        'group_localtime' => 'local_djd',
        'group_moisture' => 'centibar',
        'group_percent' => 'percent',
        'group_power' => 'watt',
        'group_pressure' => 'inHg',
        'group_pressurerate' => 'inHg_per_hour',
        'group_radiation' => 'watt_per_meter_squared',
        'group_rain' => 'inch',
        'group_rainrate' => 'inch_per_hour',
        'group_speed' => 'mile_per_hour',
        'group_speed2' => 'mile_per_hour2',
        'group_temperature' => 'degree_F',
        'group_time' => 'unix_epoch',
        'group_uv' => 'uv_index',
        'group_volt' => 'volt',
        'group_volume' => 'gallon',
        'group_conductivity' => 'microsiemens_per_centimeter',
        'group_pressure_deficit' => 'kPa',
    ];

    /** What the metric system changes against US: `MetricUnits`. */
    private const METRIC_OVERRIDES = [
        'group_altitude' => 'meter',
        'group_degree_day' => 'degree_C_day',
        'group_distance' => 'km',
        'group_length' => 'cm',
        'group_pressure' => 'mbar',
        'group_pressurerate' => 'mbar_per_hour',
        'group_rain' => 'cm',
        'group_rainrate' => 'cm_per_hour',
        'group_speed' => 'km_per_hour',
        'group_speed2' => 'km_per_hour2',
        'group_temperature' => 'degree_C',
        'group_volume' => 'liter',
    ];

    /** What MetricWX changes against metric: `MetricWXUnits`. */
    private const METRICWX_OVERRIDES = [
        'group_rain' => 'mm',
        'group_rainrate' => 'mm_per_hour',
        'group_speed' => 'meter_per_second',
        'group_speed2' => 'meter_per_second2',
    ];

    /** @var array<string, array<string, Closure(float): float>>|null */
    private static ?array $conversions = null;

    /** The unit group of an observation type, or null for one WeeWX does not know. */
    /** @param array<string, string> $custom */
    public static function groupOf(string $obsType, array $custom = []): ?string
    {
        if (isset(self::OBS_GROUP[$obsType])) {
            return self::OBS_GROUP[$obsType];
        }
        $kind = \WeewxPhp\Measurement\Catalog::extensionKind($obsType);
        if ($kind !== null) {
            return \WeewxPhp\Measurement\Catalog::KINDS[$kind];
        }
        // Additional channels exposed by the HTTP push consoles. The standard
        // WeeWX table ends at eight temperature and four soil channels.
        if (preg_match('/^(extraTemp|soilTemp|soilMoist)([1-9]|1[0-9]|2[0-4])$/D', $obsType, $match) === 1) {
            return $match[1] === 'soilMoist' ? 'group_moisture' : 'group_temperature';
        }
        return match ($obsType) {
            'weekRain', 'eventRain' => 'group_rain',
            'uvradiation' => 'group_radiation',
            'lightning_last_time' => 'group_time',
            default => $custom[$obsType] ?? null,
        };
    }

    /**
     * The unit a group is recorded in under a unit system.
     *
     * @throws UnitError For a group none of the systems knows.
     */
    public static function standardUnit(UnitSystem $system, string $group): string
    {
        $table = match ($system) {
            UnitSystem::US => self::US_UNITS,
            UnitSystem::METRIC => self::METRIC_OVERRIDES + self::US_UNITS,
            UnitSystem::METRICWX => self::METRICWX_OVERRIDES + self::METRIC_OVERRIDES + self::US_UNITS,
        };
        return $table[$group] ?? throw new UnitError(sprintf('Unknown unit group %s', $group));
    }

    /**
     * The unit and group an observation is recorded in under a unit system:
     * WeeWX's `getStandardUnitType`.
     *
     * @return array{0: string|null, 1: string|null} A 2-way tuple (unit, group); both null for
     *     an observation type WeeWX does not know.
     * @param array<string, string> $custom
     */
    public static function unitOf(UnitSystem $system, string $obsType, array $custom = []): array
    {
        $group = self::groupOf($obsType, $custom);
        if ($group === null) {
            return [null, null];
        }
        return [self::standardUnit($system, $group), $group];
    }

    public static function canConvert(string $from, string $to): bool
    {
        return $from === $to || isset(self::conversions()[$from][$to]);
    }

    /**
     * Convert one value between units: WeeWX's `convert`.
     *
     * The same unit on both sides returns the value as it is, an integer
     * included; null stays null.
     *
     * @throws UnitError For a pair the table does not hold.
     */
    public static function convert(int|float|null $value, string $from, string $to): int|float|null
    {
        if ($from === $to) {
            return $value;
        }
        $function = self::conversions()[$from][$to]
            ?? throw new UnitError(sprintf('Cannot convert from %s to %s', $from, $to));
        return $value === null ? null : $function((float) $value);
    }

    /**
     * A record converted into a standard unit system: WeeWX's `to_std_system`.
     *
     * Every observation WeeWX knows a group for is converted from the unit
     * the record's `usUnits` implies; anything else, a name WeeWX has no
     * group for or a value that is not a number, passes through as it is.
     * The result carries the target in `usUnits`.
     *
     * @param array<string, mixed> $record A record or packet with `usUnits`.
     * @param array<string, string> $custom
     *
     * @return array<string, mixed>
     *
     * @throws UnitError If `usUnits` is missing or not one of the three systems.
     */
    public static function toSystem(array $record, UnitSystem $target, array $custom = []): array
    {
        $source = $record['usUnits'] ?? null;
        if (!is_int($source) || UnitSystem::tryFrom($source) === null) {
            throw new UnitError('Record has no usable usUnits');
        }
        $sourceSystem = UnitSystem::from($source);
        if ($sourceSystem === $target) {
            return $record;
        }
        $converted = [];
        foreach ($record as $obsType => $value) {
            if ($obsType === 'usUnits') {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                [$fromUnit, $group] = self::unitOf($sourceSystem, $obsType, $custom);
                if ($fromUnit !== null && $group !== null) {
                    $value = self::convert($value, $fromUnit, self::standardUnit($target, $group));
                }
            }
            $converted[$obsType] = $value;
        }
        $converted['usUnits'] = $target->value;
        return $converted;
    }

    /**
     * Degrees to radians the way CPython's `math.radians` does it: a
     * multiplication by a precomputed constant. PHP's deg2rad divides first
     * and multiplies after, which lands one bit away often enough to show
     * up in a wind vector sum compared against WeeWX.
     */
    public static function radians(float $degrees): float
    {
        return $degrees * (M_PI / 180.0);
    }

    /** Radians to degrees, as CPython's `math.degrees`. */
    public static function degrees(float $radians): float
    {
        return $radians * (180.0 / M_PI);
    }

    public static function cToF(float $x): float
    {
        return $x * 1.8 + 32.0;
    }

    public static function fToC(float $x): float
    {
        return ($x - 32.0) / 1.8;
    }

    public static function cToK(float $x): float
    {
        return $x + 273.15;
    }

    public static function kToC(float $x): float
    {
        return $x - 273.15;
    }

    public static function kToF(float $x): float
    {
        return self::cToF(self::kToC($x));
    }

    public static function fToK(float $x): float
    {
        return self::cToK(self::fToC($x));
    }

    public static function mpsToMph(float $x): float
    {
        return $x * 3600.0 / self::METER_PER_MILE;
    }

    public static function kphToMph(float $x): float
    {
        return $x * 1000.0 / self::METER_PER_MILE;
    }

    public static function mphToKnot(float $x): float
    {
        return $x * 0.868976242;
    }

    public static function kphToKnot(float $x): float
    {
        return $x * 0.539956803;
    }

    public static function mpsToKnot(float $x): float
    {
        return $x * 1.94384449;
    }

    public static function dublinToEpoch(float $x): float
    {
        return ($x - 25567.5) * self::SECS_PER_DAY;
    }

    public static function epochToDublin(float $x): float
    {
        return $x / self::SECS_PER_DAY + 25567.5;
    }

    /**
     * `conversionDict`, built once. Closures rather than factors, because a
     * temperature is not a factor, and because the order of a multiplication
     * and a division decides the last bit of the result: `x * 3600.0 /
     * METER_PER_MILE` is not `x * (3600.0 / METER_PER_MILE)`.
     *
     * @return array<string, array<string, Closure(float): float>>
     */
    private static function conversions(): array
    {
        if (self::$conversions !== null) {
            return self::$conversions;
        }
        $fToE = static fn(float $x): float => (7.0 * $x - 80.0) / 9.0;
        $eToF = static fn(float $x): float => (9.0 * $x + 80.0) / 7.0;
        $cToE = static fn(float $x): float => (7.0 / 5.0) * $x + 16.0;
        $eToC = static fn(float $x): float => ($x - 16.0) * 5.0 / 7.0;

        self::$conversions = [
            'astronomical_unit' => [
                'meter' => static fn(float $x): float => $x * 149597870700,
                'km' => static fn(float $x): float => $x * 149597870.7,
                'mile' => static fn(float $x): float => $x * 92955807.23752087,
            ],
            'bit' => ['byte' => static fn(float $x): float => $x / 8],
            'byte' => ['bit' => static fn(float $x): float => $x * 8],
            'cm' => [
                'inch' => static fn(float $x): float => $x / self::CM_PER_INCH,
                'mm' => static fn(float $x): float => $x * 10.0,
            ],
            'cm_per_hour' => [
                'inch_per_hour' => static fn(float $x): float => $x * 0.393700787,
                'mm_per_hour' => static fn(float $x): float => $x * 10.0,
            ],
            'cubic_foot' => [
                'gallon' => static fn(float $x): float => $x * 7.48052,
                'litre' => static fn(float $x): float => $x * 28.3168,
                'liter' => static fn(float $x): float => $x * 28.3168,
            ],
            'day' => [
                'second' => static fn(float $x): float => $x * self::SECS_PER_DAY,
                'minute' => static fn(float $x): float => $x * 1440.0,
                'hour' => static fn(float $x): float => $x * 24.0,
            ],
            'degree_angle' => ['radian' => self::radians(...)],
            'degree_C' => [
                'degree_F' => self::cToF(...),
                'degree_E' => $cToE,
                'degree_K' => self::cToK(...),
            ],
            'degree_C_day' => ['degree_F_day' => static fn(float $x): float => $x * (9.0 / 5.0)],
            'degree_E' => [
                'degree_C' => $eToC,
                'degree_F' => $eToF,
            ],
            'degree_F' => [
                'degree_C' => self::fToC(...),
                'degree_E' => $fToE,
                'degree_K' => self::fToK(...),
            ],
            'degree_F_day' => ['degree_C_day' => static fn(float $x): float => $x * (5.0 / 9.0)],
            'degree_K' => [
                'degree_C' => self::kToC(...),
                'degree_F' => self::kToF(...),
            ],
            'dublin_jd' => [
                'unix_epoch' => self::dublinToEpoch(...),
                'unix_epoch_ms' => static fn(float $x): float => self::dublinToEpoch($x) * 1000,
                'unix_epoch_ns' => static fn(float $x): float => self::dublinToEpoch($x) * 1e06,
            ],
            'foot' => ['meter' => static fn(float $x): float => $x * self::METER_PER_FOOT],
            'gallon' => [
                'liter' => static fn(float $x): float => $x * 3.78541,
                'litre' => static fn(float $x): float => $x * 3.78541,
                'cubic_foot' => static fn(float $x): float => $x * 0.133681,
            ],
            'hour' => [
                'second' => static fn(float $x): float => $x * 3600.0,
                'minute' => static fn(float $x): float => $x * 60.0,
                'day' => static fn(float $x): float => $x / 24.0,
            ],
            'hPa' => [
                'inHg' => static fn(float $x): float => $x * self::INHG_PER_MBAR,
                'mmHg' => static fn(float $x): float => $x * 0.75006168,
                'mbar' => static fn(float $x): float => $x,
                'kPa' => static fn(float $x): float => $x / 10.0,
            ],
            'hPa_per_hour' => [
                'inHg_per_hour' => static fn(float $x): float => $x * self::INHG_PER_MBAR,
                'mmHg_per_hour' => static fn(float $x): float => $x * 0.75006168,
                'mbar_per_hour' => static fn(float $x): float => $x,
                'kPa_per_hour' => static fn(float $x): float => $x / 10.0,
            ],
            'inch' => [
                'cm' => static fn(float $x): float => $x * self::CM_PER_INCH,
                'mm' => static fn(float $x): float => $x * self::MM_PER_INCH,
            ],
            'inch_per_hour' => [
                'cm_per_hour' => static fn(float $x): float => $x * 2.54,
                'mm_per_hour' => static fn(float $x): float => $x * 25.4,
            ],
            'inHg' => [
                'mbar' => static fn(float $x): float => $x / self::INHG_PER_MBAR,
                'hPa' => static fn(float $x): float => $x / self::INHG_PER_MBAR,
                'kPa' => static fn(float $x): float => $x / self::INHG_PER_MBAR / 10.0,
                'mmHg' => static fn(float $x): float => $x * 25.4,
            ],
            'inHg_per_hour' => [
                'mbar_per_hour' => static fn(float $x): float => $x / self::INHG_PER_MBAR,
                'hPa_per_hour' => static fn(float $x): float => $x / self::INHG_PER_MBAR,
                'kPa_per_hour' => static fn(float $x): float => $x / self::INHG_PER_MBAR / 10.0,
                'mmHg_per_hour' => static fn(float $x): float => $x * 25.4,
            ],
            'kilowatt' => ['watt' => static fn(float $x): float => $x * 1000.0],
            'kilowatt_hour' => [
                'mega_joule' => static fn(float $x): float => $x * 3.6,
                'watt_second' => static fn(float $x): float => $x * 3.6e6,
                'watt_hour' => static fn(float $x): float => $x * 1000.0,
            ],
            'km' => [
                'meter' => static fn(float $x): float => $x * 1000.0,
                'mile' => static fn(float $x): float => $x * 0.621371192,
                'astronomical_unit' => static fn(float $x): float => $x / 149597870.7,
            ],
            'km_per_hour' => [
                'mile_per_hour' => self::kphToMph(...),
                'knot' => self::kphToKnot(...),
                'meter_per_second' => static fn(float $x): float => $x * 0.277777778,
            ],
            'knot' => [
                'mile_per_hour' => static fn(float $x): float => $x * 1.15077945,
                'km_per_hour' => static fn(float $x): float => $x * 1.85200,
                'meter_per_second' => static fn(float $x): float => $x * 0.514444444,
            ],
            'knot2' => [
                'mile_per_hour2' => static fn(float $x): float => $x * 1.15077945,
                'km_per_hour2' => static fn(float $x): float => $x * 1.85200,
                'meter_per_second2' => static fn(float $x): float => $x * 0.514444444,
            ],
            'kPa' => [
                'inHg' => static fn(float $x): float => $x * self::INHG_PER_MBAR * 10.0,
                'mmHg' => static fn(float $x): float => $x * 7.5006168,
                'mbar' => static fn(float $x): float => $x * 10.0,
                'hPa' => static fn(float $x): float => $x * 10.0,
            ],
            'kPa_per_hour' => [
                'inHg_per_hour' => static fn(float $x): float => $x * self::INHG_PER_MBAR * 10.0,
                'mmHg_per_hour' => static fn(float $x): float => $x * 7.5006168,
                'mbar_per_hour' => static fn(float $x): float => $x * 10.0,
                'hPa_per_hour' => static fn(float $x): float => $x * 10.0,
            ],
            'liter' => [
                'gallon' => static fn(float $x): float => $x * 0.264172,
                'cubic_foot' => static fn(float $x): float => $x * 0.0353147,
            ],
            'mbar' => [
                'inHg' => static fn(float $x): float => $x * self::INHG_PER_MBAR,
                'mmHg' => static fn(float $x): float => $x * 0.75006168,
                'hPa' => static fn(float $x): float => $x,
                'kPa' => static fn(float $x): float => $x / 10.0,
            ],
            'mbar_per_hour' => [
                'inHg_per_hour' => static fn(float $x): float => $x * self::INHG_PER_MBAR,
                'mmHg_per_hour' => static fn(float $x): float => $x * 0.75006168,
                'hPa_per_hour' => static fn(float $x): float => $x,
                'kPa_per_hour' => static fn(float $x): float => $x / 10.0,
            ],
            'mega_joule' => [
                'kilowatt_hour' => static fn(float $x): float => $x / 3.6,
                'watt_hour' => static fn(float $x): float => $x * 1000000 / 3600,
                'watt_second' => static fn(float $x): float => $x * 1000000,
            ],
            'meter' => [
                'foot' => static fn(float $x): float => $x / self::METER_PER_FOOT,
                'km' => static fn(float $x): float => $x / 1000.0,
                'astronomical_unit' => static fn(float $x): float => $x / 149597870700,
            ],
            'meter_per_second' => [
                'mile_per_hour' => self::mpsToMph(...),
                'knot' => self::mpsToKnot(...),
                'km_per_hour' => static fn(float $x): float => $x * 3.6,
            ],
            'meter_per_second2' => [
                'mile_per_hour2' => static fn(float $x): float => $x * 2.23693629,
                'knot2' => static fn(float $x): float => $x * 1.94384449,
                'km_per_hour2' => static fn(float $x): float => $x * 3.6,
            ],
            'mile' => [
                'km' => static fn(float $x): float => $x * 1.609344,
                'astronomical_unit' => static fn(float $x): float => $x / 92955807.23752087,
            ],
            'mile_per_hour' => [
                'km_per_hour' => static fn(float $x): float => $x * 1.609344,
                'knot' => self::mphToKnot(...),
                'meter_per_second' => static fn(float $x): float => $x * 0.44704,
            ],
            'mile_per_hour2' => [
                'km_per_hour2' => static fn(float $x): float => $x * 1.609344,
                'knot2' => static fn(float $x): float => $x * 0.868976242,
                'meter_per_second2' => static fn(float $x): float => $x * 0.44704,
            ],
            'minute' => [
                'second' => static fn(float $x): float => $x * 60.0,
                'hour' => static fn(float $x): float => $x / 60.0,
                'day' => static fn(float $x): float => $x / 1440.0,
            ],
            'mm' => [
                'inch' => static fn(float $x): float => $x / self::MM_PER_INCH,
                'cm' => static fn(float $x): float => $x * 0.10,
            ],
            'mm_per_hour' => [
                'inch_per_hour' => static fn(float $x): float => $x * .0393700787,
                'cm_per_hour' => static fn(float $x): float => $x * 0.10,
            ],
            'mmHg' => [
                'inHg' => static fn(float $x): float => $x / self::MM_PER_INCH,
                'mbar' => static fn(float $x): float => $x / 0.75006168,
                'hPa' => static fn(float $x): float => $x / 0.75006168,
                'kPa' => static fn(float $x): float => $x / 7.5006168,
            ],
            'mmHg_per_hour' => [
                'inHg_per_hour' => static fn(float $x): float => $x / self::MM_PER_INCH,
                'mbar_per_hour' => static fn(float $x): float => $x / 0.75006168,
                'hPa_per_hour' => static fn(float $x): float => $x / 0.75006168,
                'kPa_per_hour' => static fn(float $x): float => $x / 7.5006168,
            ],
            'radian' => ['degree_angle' => self::degrees(...)],
            'second' => [
                'hour' => static fn(float $x): float => $x / 3600.0,
                'minute' => static fn(float $x): float => $x / 60.0,
                'day' => static fn(float $x): float => $x / self::SECS_PER_DAY,
            ],
            'unix_epoch' => [
                'dublin_jd' => self::epochToDublin(...),
                'unix_epoch_ms' => static fn(float $x): float => $x * 1000,
                'unix_epoch_ns' => static fn(float $x): float => $x * 1000000,
            ],
            'unix_epoch_ms' => [
                'dublin_jd' => static fn(float $x): float => self::epochToDublin($x / 1000.0),
                'unix_epoch' => static fn(float $x): float => $x / 1000,
                'unix_epoch_ns' => static fn(float $x): float => $x * 1000,
            ],
            'unix_epoch_ns' => [
                'dublin_jd' => static fn(float $x): float => self::epochToDublin($x / 1e6),
                'unix_epoch' => static fn(float $x): float => $x / 1e06,
                'unix_epoch_ms' => static fn(float $x): float => $x / 1000,
            ],
            'watt' => ['kilowatt' => static fn(float $x): float => $x / 1000.0],
            'watt_hour' => [
                'kilowatt_hour' => static fn(float $x): float => $x / 1000.0,
                'mega_joule' => static fn(float $x): float => $x * 0.0036,
                'watt_second' => static fn(float $x): float => $x * 3600.0,
            ],
            'watt_second' => [
                'kilowatt_hour' => static fn(float $x): float => $x / 3.6e6,
                'mega_joule' => static fn(float $x): float => $x / 1000000,
                'watt_hour' => static fn(float $x): float => $x / 3600.0,
            ],
        ];
        return self::$conversions;
    }
}
