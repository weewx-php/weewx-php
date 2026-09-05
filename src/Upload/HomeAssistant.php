<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/**
 * Making the station appear in Home Assistant by itself. Home Assistant
 * picks up MQTT topics on its own, but only when told what they are: one
 * retained JSON document per reading, on a topic under `homeassistant/`,
 * saying what the value means and where to find it. Published once, the
 * station shows up as a device with its sensors named, graphed and
 * unit-aware; no YAML, no restart.
 *
 * Retained, always: a definition nobody kept is seen only by a Home
 * Assistant that happened to be running at that second. The layout is
 * weewx-evo's, so a station switching over keeps its entities.
 */
final class HomeAssistant
{
    /**
     * What Home Assistant calls the kind of thing a reading is; it picks an
     * icon, a graph and the conversions it offers from this, so a wrong one
     * is not cosmetic. Matched longest first: `windGust` before `wind`.
     */
    private const DEVICE_CLASS = [
        ['barometer', 'atmospheric_pressure'],
        ['pressure', 'atmospheric_pressure'],
        ['altimeter', 'atmospheric_pressure'],
        ['outTemp', 'temperature'],
        ['inTemp', 'temperature'],
        ['dewpoint', 'temperature'],
        ['inDewpoint', 'temperature'],
        ['windchill', 'temperature'],
        ['heatindex', 'temperature'],
        ['appTemp', 'temperature'],
        ['humidex', 'temperature'],
        ['extraTemp', 'temperature'],
        ['soilTemp', 'temperature'],
        ['leafTemp', 'temperature'],
        ['outHumidity', 'humidity'],
        ['inHumidity', 'humidity'],
        ['extraHumid', 'humidity'],
        ['windSpeed', 'wind_speed'],
        ['windGust', 'wind_speed'],
        ['rainRate', 'precipitation_intensity'],
        ['rain', 'precipitation'],
        ['radiation', 'irradiance'],
        ['illuminance', 'illuminance'],
        ['pm2_5', 'pm25'],
        ['pm10_0', 'pm10'],
        ['co2', 'carbon_dioxide'],
        ['consBatteryVoltage', 'voltage'],
        ['supplyVoltage', 'voltage'],
        ['rxCheckPercent', 'signal_strength'],
    ];

    /** Our unit names as Home Assistant writes them; one it does not know makes the sensor a plain string with no graph. */
    private const HA_UNIT = [
        'degree_C' => '°C',
        'degree_F' => '°F',
        'degree_K' => 'K',
        'percent' => '%',
        'mbar' => 'hPa',
        'hPa' => 'hPa',
        'inHg' => 'inHg',
        'mmHg' => 'mmHg',
        'kPa' => 'kPa',
        'meter_per_second' => 'm/s',
        'km_per_hour' => 'km/h',
        'mile_per_hour' => 'mph',
        'knot' => 'kn',
        'mm' => 'mm',
        'cm' => 'cm',
        'inch' => 'in',
        'mm_per_hour' => 'mm/h',
        'cm_per_hour' => 'cm/h',
        'inch_per_hour' => 'in/h',
        'watt_per_meter_squared' => 'W/m²',
        'lux' => 'lx',
        'microgram_per_meter_cubed' => 'µg/m³',
        'volt' => 'V',
        'degree_compass' => '°',
        'uv_index' => 'UV index',
    ];

    /** Readings that are a running total rather than a measurement; graphed and summed differently. */
    private const TOTAL = ['dayRain', 'rain24', 'hourRain', 'rainTotal', 'dayET', 'ET'];

    private function __construct() {}

    /**
     * One sensor definition as (topic, payload). `field` is the name in the
     * JSON document, `outTemp_C`; `obs` is the plain reading, which decides
     * everything else.
     *
     * @return array{0: string, 1: string}
     */
    public static function discovery(string $obs, ?string $unit, string $topic, string $field, string $station, string $prefix = 'homeassistant'): array
    {
        $ident = 'weewx_php_' . self::slug($station);
        $unique = $ident . '_' . $obs;
        $payload = [
            'name' => self::readable($obs),
            'state_topic' => $topic . '/loop',
            // From the JSON document rather than the individual topic: one
            // subscription instead of forty, whether or not the individual
            // topics are switched on.
            'value_template' => '{{ value_json.' . $field . " | default('', true) }}",
            'unique_id' => $unique,
            'object_id' => $unique,
            'device' => [
                'identifiers' => [$ident],
                'name' => $station === '' ? 'weewx-php' : $station,
                'manufacturer' => 'weewx-php',
                'model' => 'Weather station',
            ],
            // Without this a sensor that stops reporting keeps its last value forever, which reads as a station that is fine.
            'expire_after' => 3600,
        ];
        $class = self::deviceClass($obs);
        if ($class !== null) {
            $payload['device_class'] = $class;
        }
        $haUnit = self::HA_UNIT[$unit ?? ''] ?? null;
        if ($haUnit !== null) {
            $payload['unit_of_measurement'] = $haUnit;
        }
        $total = in_array($obs, self::TOTAL, true);
        $payload['state_class'] = $total ? 'total_increasing' : 'measurement';
        if ($total) {
            // A daily total resets at midnight, and total_increasing expects exactly that.
            unset($payload['expire_after']);
        }
        return [
            sprintf('%s/sensor/%s/%s/config', $prefix, $ident, $obs),
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** `outTemp` as `Out temp`, which is what a person sees in a list. */
    public static function readable(string $obs): string
    {
        $split = preg_split('/(?<=[a-z0-9])(?=[A-Z])|_/', $obs);
        $words = array_values(array_filter($split === false ? [] : $split, static fn(string $word): bool => $word !== ''));
        if ($words === []) {
            return $obs;
        }
        return implode(' ', [ucfirst(strtolower($words[0])), ...array_map('strtolower', array_slice($words, 1))]);
    }

    public static function deviceClass(string $obs): ?string
    {
        foreach (self::DEVICE_CLASS as [$prefix, $class]) {
            if (str_starts_with($obs, $prefix)) {
                return $class;
            }
        }
        return null;
    }

    /** A station name as something safe in a topic and an entity id. */
    public static function slug(string $text): string
    {
        $cleaned = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');
        $cleaned = substr($cleaned, 0, 40);
        return $cleaned === '' ? 'station' : $cleaned;
    }
}
