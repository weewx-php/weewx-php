<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * The schema WeeWX seeds a new database with: `weewx/schemas/wview_extended.py`
 * of WeeWX 5.5.0, 112 observation columns after the three every record
 * carries.
 *
 * Used only to create a database that does not exist yet. Once a file is
 * there, its schema comes from the file; see {@see Schema}.
 */
final class Wview
{
    /**
     * Column and SQL type, in the order the table is created with. The
     * order is part of what WeeWX creates, so it is kept.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ARCHIVE_TABLE = [
        ['dateTime', 'INTEGER NOT NULL PRIMARY KEY'],
        ['usUnits', 'INTEGER NOT NULL'],
        ['interval', 'INTEGER NOT NULL'],
        ['altimeter', 'REAL'],
        ['appTemp', 'REAL'],
        ['appTemp1', 'REAL'],
        ['barometer', 'REAL'],
        ['batteryStatus1', 'REAL'],
        ['batteryStatus2', 'REAL'],
        ['batteryStatus3', 'REAL'],
        ['batteryStatus4', 'REAL'],
        ['batteryStatus5', 'REAL'],
        ['batteryStatus6', 'REAL'],
        ['batteryStatus7', 'REAL'],
        ['batteryStatus8', 'REAL'],
        ['cloudbase', 'REAL'],
        ['co', 'REAL'],
        ['co2', 'REAL'],
        ['consBatteryVoltage', 'REAL'],
        ['dewpoint', 'REAL'],
        ['dewpoint1', 'REAL'],
        ['ET', 'REAL'],
        ['extraHumid1', 'REAL'],
        ['extraHumid2', 'REAL'],
        ['extraHumid3', 'REAL'],
        ['extraHumid4', 'REAL'],
        ['extraHumid5', 'REAL'],
        ['extraHumid6', 'REAL'],
        ['extraHumid7', 'REAL'],
        ['extraHumid8', 'REAL'],
        ['extraTemp1', 'REAL'],
        ['extraTemp2', 'REAL'],
        ['extraTemp3', 'REAL'],
        ['extraTemp4', 'REAL'],
        ['extraTemp5', 'REAL'],
        ['extraTemp6', 'REAL'],
        ['extraTemp7', 'REAL'],
        ['extraTemp8', 'REAL'],
        ['forecast', 'REAL'],
        ['hail', 'REAL'],
        ['hailBatteryStatus', 'REAL'],
        ['hailRate', 'REAL'],
        ['heatindex', 'REAL'],
        ['heatindex1', 'REAL'],
        ['heatingTemp', 'REAL'],
        ['heatingVoltage', 'REAL'],
        ['humidex', 'REAL'],
        ['humidex1', 'REAL'],
        ['illuminance', 'REAL'],
        ['inDewpoint', 'REAL'],
        ['inHumidity', 'REAL'],
        ['inTemp', 'REAL'],
        ['inTempBatteryStatus', 'REAL'],
        ['leafTemp1', 'REAL'],
        ['leafTemp2', 'REAL'],
        ['leafWet1', 'REAL'],
        ['leafWet2', 'REAL'],
        ['lightning_distance', 'REAL'],
        ['lightning_disturber_count', 'REAL'],
        ['lightning_energy', 'REAL'],
        ['lightning_noise_count', 'REAL'],
        ['lightning_strike_count', 'REAL'],
        ['luminosity', 'REAL'],
        ['maxSolarRad', 'REAL'],
        ['nh3', 'REAL'],
        ['no2', 'REAL'],
        ['noise', 'REAL'],
        ['o3', 'REAL'],
        ['outHumidity', 'REAL'],
        ['outTemp', 'REAL'],
        ['outTempBatteryStatus', 'REAL'],
        ['pb', 'REAL'],
        ['pm10_0', 'REAL'],
        ['pm1_0', 'REAL'],
        ['pm2_5', 'REAL'],
        ['pressure', 'REAL'],
        ['radiation', 'REAL'],
        ['rain', 'REAL'],
        ['rainBatteryStatus', 'REAL'],
        ['rainRate', 'REAL'],
        ['referenceVoltage', 'REAL'],
        ['rxCheckPercent', 'REAL'],
        ['signal1', 'REAL'],
        ['signal2', 'REAL'],
        ['signal3', 'REAL'],
        ['signal4', 'REAL'],
        ['signal5', 'REAL'],
        ['signal6', 'REAL'],
        ['signal7', 'REAL'],
        ['signal8', 'REAL'],
        ['snow', 'REAL'],
        ['snowBatteryStatus', 'REAL'],
        ['snowDepth', 'REAL'],
        ['snowMoisture', 'REAL'],
        ['snowRate', 'REAL'],
        ['so2', 'REAL'],
        ['soilMoist1', 'REAL'],
        ['soilMoist2', 'REAL'],
        ['soilMoist3', 'REAL'],
        ['soilMoist4', 'REAL'],
        ['soilTemp1', 'REAL'],
        ['soilTemp2', 'REAL'],
        ['soilTemp3', 'REAL'],
        ['soilTemp4', 'REAL'],
        ['supplyVoltage', 'REAL'],
        ['txBatteryStatus', 'REAL'],
        ['UV', 'REAL'],
        ['uvBatteryStatus', 'REAL'],
        ['windBatteryStatus', 'REAL'],
        ['windchill', 'REAL'],
        ['windDir', 'REAL'],
        ['windGust', 'REAL'],
        ['windGustDir', 'REAL'],
        ['windrun', 'REAL'],
        ['windSpeed', 'REAL'],
    ];

    /** The three columns every record carries and no daily summary is kept for. */
    public const NOT_OBSERVATIONS = ['dateTime', 'usUnits', 'interval'];

    private function __construct() {}

    /**
     * The daily summaries WeeWX creates for this schema: one scalar table
     * per observation column, then the wind vector.
     *
     * @return array<string, StatsKind>
     */
    public static function daySummaries(): array
    {
        $summaries = [];
        foreach (self::ARCHIVE_TABLE as [$name]) {
            if (!in_array($name, self::NOT_OBSERVATIONS, true)) {
                $summaries[$name] = StatsKind::Scalar;
            }
        }
        $summaries['wind'] = StatsKind::Vector;
        return $summaries;
    }
}
