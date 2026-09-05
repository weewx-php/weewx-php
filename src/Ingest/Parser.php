<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use DateTimeImmutable;
use DateTimeZone;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** The form protocols, based on the captured uploads and the WU specification in tests/uploads. */
final class Parser
{
    public const MAX_BYTES = 65536;
    private const MAX_FIELDS = 768;

    private const COMMON = [
        'tempf' => 'outTemp', 'humidity' => 'outHumidity',
        'winddir' => 'windDir', 'windspeedmph' => 'windSpeed', 'windgustmph' => 'windGust',
        'windgustdir' => 'windGustDir', 'dewptf' => 'dewpoint', 'windchillf' => 'windchill',
        'heatindexf' => 'heatindex', 'feelslikef' => 'appTemp',
        'solarradiation' => 'radiation', 'dailyrainin' => 'dayRain',
        'hourlyrainin' => 'hourRain', 'weeklyrainin' => 'weekRain', 'monthlyrainin' => 'monthRain',
        'yearlyrainin' => 'yearRain', 'totalrainin' => 'totalRain', 'eventrainin' => 'eventRain',
        'rainratein' => 'rainRate',
    ];

    private const WU = [
        'indoortempf' => 'inTemp', 'tempinf' => 'inTemp',
        'indoorhumidity' => 'inHumidity', 'humidityin' => 'inHumidity',
        'baromin' => 'barometer', 'absbaromin' => 'pressure', 'UV' => 'UV',
        'rainin' => 'hourRain', 'lowbatt' => 'outTempBatteryStatus',
        'soiltempf' => 'soilTemp1', 'soilmoisture' => 'soilMoist1',
        'leafwetness' => 'leafWet1', 'leafwetness2' => 'leafWet2',
        'AqPM2.5' => 'pm2_5', 'AqPM10' => 'pm10_0',
    ];

    private const METRIC = [
        'intemp' => 'inTemp', 'outtemp' => 'outTemp', 'inhumi' => 'inHumidity', 'outhumi' => 'outHumidity',
        'dewpoint' => 'dewpoint', 'windchill' => 'windchill',
        'windspeed' => 'windSpeed', 'windgust' => 'windGust', 'winddir' => 'windDir',
        'absbaro' => 'pressure', 'relbaro' => 'barometer',
        'rainrate' => 'rainRate', 'dailyrain' => 'dayRain', 'weeklyrain' => 'weekRain',
        'monthlyrain' => 'monthRain', 'yearlyrain' => 'yearRain',
        'light' => 'illuminance', 'UV' => 'uvradiation', 'lowbatt' => 'outTempBatteryStatus',
    ];

    /** Preserves spelling (AqPM2.5), rejects arrays and duplicate keys instead of PHP's parse_str rewriting.
     * @return array<string, string>
     */
    public static function form(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new Rejected('payload too large', 413);
        }
        if ($text === '') {
            return [];
        }
        $parts = explode('&', $text);
        if (count($parts) > self::MAX_FIELDS) {
            throw new Rejected('too many fields', 413);
        }
        $fields = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!str_contains($part, '=') || preg_match('/%(?![0-9a-fA-F]{2})/', $part) === 1) {
                throw new Rejected('invalid form');
            }
            [$name, $value] = explode('=', $part, 2);
            $name = urldecode($name);
            $value = urldecode($value);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $name) !== 1
                || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
                || preg_match('//u', $value) !== 1 || array_key_exists($name, $fields)) {
                throw new Rejected('invalid field');
            }
            $fields[$name] = $value;
        }
        return $fields;
    }

    /** @param array<string, string> $fields */
    public static function observation(Protocol $protocol, array $fields, int $now, string $metricWind = 'kph'): Observation
    {
        $ecowitt = $protocol === Protocol::Ecowitt;
        $identity = $fields[$ecowitt ? 'PASSKEY' : 'ID'] ?? '';
        $model = $fields['model'] ?? $fields['stationtype'] ?? $fields['softwaretype'] ?? '';
        if ($ecowitt) {
            if (preg_match('/^[a-fA-F0-9]{32}$/D', $identity) !== 1 || str_starts_with($fields['stationtype'] ?? '', 'AMBWeather')) {
                throw new Rejected('invalid ecowitt identity');
            }
            $identity = strtoupper($identity);
        } elseif ($identity === '' || strlen($identity) > 128 || ($fields['action'] ?? 'updateraw') !== 'updateraw') {
            throw new Rejected('invalid wunderground identity');
        }
        $metric = !$ecowitt && array_intersect(['intemp', 'outtemp', 'absbaro', 'relbaro', 'inhumi', 'outhumi'], array_keys($fields)) !== [];
        $definitions = $ecowitt ? EcowittFields::all() : [];
        $map = $ecowitt ? array_map(static fn(Field $field): string => $field->observation, $definitions)
            : ($metric ? self::METRIC : self::WU + self::COMMON + self::channels());
        if (!$ecowitt && in_array($fields['softwaretype'] ?? '', ['WH2600GEN_V2.2.5', 'WH2650A_V1.2.1'], true)) {
            $map['baromin'] = 'pressure';
        }
        $units = $metric ? ($metricWind === 'mps' ? UnitSystem::METRICWX : UnitSystem::METRIC) : UnitSystem::US;
        $data = [];
        $values = [];
        $numeric = 0;
        foreach ($map as $raw => $target) {
            if (!array_key_exists($raw, $fields)) {
                continue;
            }
            $text = $fields[$raw];
            if (in_array($text, ['', '--', '--.-', '-', 'None', 'null', '-9999', '-9999.0'], true)) {
                $data[$target] ??= null;
                continue;
            }
            if (!is_numeric($text) || !is_finite((float) $text)) {
                continue;
            }
            $value = (float) $text;
            if ($metric && in_array($raw, ['rainrate', 'dailyrain', 'weeklyrain', 'monthlyrain', 'yearlyrain'], true) && $units === UnitSystem::METRIC) {
                $value *= 0.1;
            }
            if ($metric && $raw === 'UV') {
                $value *= 0.01; // Irradiance, not UV index: uW/cm2 -> W/m2.
            }
            if (isset($definitions[$raw])) {
                $value = $definitions[$raw]->value($value, $units);
            }
            // Aliases must not silently overwrite a more specific field.
            $data[$target] ??= $value;
            $values[$raw] = $value;
            if ($value !== null) {
                ++$numeric;
            }
        }
        if ($ecowitt && array_key_exists('lightning_Batt', $data)) {
            $data['lightningBatteryStatus'] = $data['lightning_Batt'] === null ? null : (float) ($data['lightning_Batt'] <= 1);
        }
        [$timestamp, $deviceTime, $reportedTimestamp, $timeReason] = self::timestamp($fields['dateutc'] ?? '', $now);
        $inventory = [];
        foreach ($fields as $native => $text) {
            if (!self::measurementKey($native)) {
                continue;
            }
            $target = $map[$native] ?? null;
            $value = is_numeric($text) && is_finite((float) $text) && !in_array($text, ['-9999', '-9999.0'], true) ? (float) $text : null;
            if ($target === null && $value === null) {
                continue;
            }
            $inventory[$native] = ['observation' => $target, 'value' => $target === null ? $value : ($values[$native] ?? null),
                'unit' => $target === null ? null : Units::unitOf($units, $target)[0]];
            if ($target === null && $value !== null) {
                ++$numeric;
            }
        }
        if ($numeric === 0) {
            throw new Rejected('no measurements');
        }
        return new Observation($protocol, $identity, substr($model, 0, 160), $timestamp, $units, $data, self::sample($fields), $deviceTime, $inventory, $reportedTimestamp, $timeReason);
    }

    public static function measurementKey(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $name) === 1
            && preg_match('/pass|token|key|secret|credential|auth|mac|^id$/i', $name) !== 1
            && !in_array(strtolower($name), ['dateutc', 'stationtype', 'softwaretype', 'model', 'action', 'realtime',
                'rtfreq', 'freq', 'interval', 'runtime', 'uptime', 'heap', 'date', 'time', 'usunits'], true);
    }

    /** @return array<string, string> */
    private static function channels(): array
    {
        $map = [];
        for ($channel = 1; $channel <= 16; ++$channel) {
            $map['soilmoisture' . $channel] = 'soilMoist' . $channel;
            $map['soiltemp' . $channel . 'f'] = 'soilTemp' . $channel;
            if ($channel <= 8) {
                $map['temp' . $channel . 'f'] = 'extraTemp' . $channel;
                $map['humidity' . $channel] = 'extraHumid' . $channel;
            }
        }
        return $map;
    }

    /** @param array<string, string> $fields */
    private static function sample(array $fields): string
    {
        foreach ($fields as $name => $value) {
            if (preg_match('/pass|token|key|secret|credential|auth|mac|^id$/i', $name) === 1) {
                $fields[$name] = '[redacted]';
            }
        }
        return substr(http_build_query($fields, '', '&', PHP_QUERY_RFC3986), 0, 8192);
    }

    /** @return array{int, bool, ?int, string} */
    private static function timestamp(string $stamp, int $now): array
    {
        if ($stamp === '' || strtolower($stamp) === 'now') {
            return [$now, false, null, 'server'];
        }
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2} \d{1,2}:\d{2}:\d{2}$/D', $stamp) !== 1) {
            return [$now, false, null, 'invalid'];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return [$now, false, null, 'invalid'];
        }
        $time = $date->getTimestamp();
        return $time <= $now + 60 && $time >= $now - 14400
            ? [$time, true, $time, 'device'] : [$now, false, $time, 'out_of_range'];
    }
}
