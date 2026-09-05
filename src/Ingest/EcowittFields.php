<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

/** Customized HTTP POST fields only; LAN API battery encodings differ. */
final class EcowittFields
{
    /** @return array<string, Field> */
    public static function all(): array
    {
        $fields = [];
        foreach (['tempf' => 'outTemp', 'tempinf' => 'inTemp', 'dewptf' => 'dewpoint',
            'windchillf' => 'windchill', 'heatindexf' => 'heatindex', 'feelslikef' => 'appTemp'] as $raw => $target) {
            $fields[$raw] = new Field($target, 'degree_F');
        }
        foreach (['humidity' => 'outHumidity', 'humidityin' => 'inHumidity'] as $raw => $target) {
            $fields[$raw] = new Field($target, 'percent', 0, 100);
        }
        foreach (['winddir' => 'windDir', 'windgustdir' => 'windGustDir', 'winddir_avg10m' => 'windDir10'] as $raw => $target) {
            $fields[$raw] = new Field($target, 'degree_compass', 0, 360);
        }
        foreach (['windspeedmph' => 'windSpeed', 'windgustmph' => 'windGust', 'maxdailygust' => 'maxdailygust'] as $raw => $target) {
            $fields[$raw] = new Field($target, 'mile_per_hour', 0);
        }
        foreach (['dailyrainin' => 'dayRain', 'hourlyrainin' => 'hourRain', 'weeklyrainin' => 'weekRain',
            'monthlyrainin' => 'monthRain', 'yearlyrainin' => 'yearRain', 'totalrainin' => 'totalRain',
            'eventrainin' => 'eventRain', 'last24hrainin' => 'rain24'] as $raw => $target) {
            $fields[$raw] = new Field($target, 'inch', 0);
        }
        $fields += [
            'rainratein' => new Field('rainRate', 'inch_per_hour', 0),
            'baromabsin' => new Field('pressure', 'inHg', 0),
            'baromrelin' => new Field('barometer', 'inHg', 0),
            'solarradiation' => new Field('radiation', 'watt_per_meter_squared', 0),
            'uv' => new Field('UV', 'uv_index', 0),
            'vpd' => new Field('vpd', 'inHg', 0),
            'co2' => new Field('co2', 'ppm', 0),
            'pm25_co2' => new Field('pm2_5', 'microgram_per_meter_cubed', 0),
            'pm10_co2' => new Field('pm10_0', 'microgram_per_meter_cubed', 0),
            'lightning_num' => new Field('lightningDayCount', 'count', 0, integer: true),
            'lightning' => new Field('lightning_distance', 'km', 0, 40),
            'lightning_time' => new Field('lightning_last_time', 'unix_epoch', 0, integer: true),
            'wh57batt' => new Field('lightning_Batt', 'count', 0, 5, true),
            'wh65batt' => new Field('outTempBatteryStatus', 'boolean', 0, 1, true),
            'wh80batt' => new Field('wh80_batt', 'volt', 0, 5),
            'wh90batt' => new Field('wh90_batt', 'volt', 0, 5),
        ];
        for ($channel = 1; $channel <= 16; ++$channel) {
            if ($channel <= 8) {
                $fields['temp' . $channel . 'f'] = new Field('extraTemp' . $channel, 'degree_F');
                $fields['humidity' . $channel] = new Field('extraHumid' . $channel, 'percent', 0, 100);
            }
            // Keep percentage measurements distinct from WeeWX's centibar soilMoist.
            $fields['soilmoisture' . $channel] = new Field('soilMoistPct' . $channel, 'percent', 0, 100);
            $fields['soiltemp' . $channel . 'f'] = new Field('soilTemp' . $channel, 'degree_F');
            $fields['soil_ec_hum' . $channel] = new Field('soilMoistPct' . $channel, 'percent', 0, 100);
            $fields['soil_ec_temp' . $channel] = new Field('soilTemp' . $channel, 'degree_F');
            $fields['soil_ec' . $channel] = new Field('soilEC' . $channel, 'microsiemens_per_centimeter', 0, 10000);
            $fields['tf_ch' . $channel] = new Field('extraTemp' . ($channel + 8), 'degree_F');
            $fields['tf_batt' . $channel] = new Field('wn34_ch' . $channel . '_batt', 'volt', 0, 5);
            $fields['soilbatt' . $channel] = new Field('wh51_ch' . $channel . '_batt', 'volt', 0, 5);
            $fields['soil_ec_batt' . $channel] = new Field('wh52_ch' . $channel . '_batt', 'volt', 0, 5);
        }
        // soil_ec_hum_adN / soil_ec_adN stay in the bounded native inventory.
        return $fields;
    }
}
