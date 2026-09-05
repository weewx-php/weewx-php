<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use DateTimeZone;
use WeewxPhp\Archive\How;
use WeewxPhp\Config\Altitude;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Calibration;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\LatePackets;
use WeewxPhp\Config\QcRule;
use WeewxPhp\Config\Settings;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\UnitSystem;

/** An archive's configuration for a test: Kirchdorf, with whatever a case needs changed. */
final class Archives
{
    private function __construct() {}

    /** The installation-wide settings for a test, as the defaults have them. */
    public static function settings(
        string $dataDir,
        int $archiveInterval = 300,
        int $archiveDelay = 15,
        bool $loopHilo = true,
        LatePackets $latePackets = LatePackets::Ignore,
        int $liveRetention = 7 * 86400,
        int $maxIntervalsPerRun = 100,
    ): Settings {
        return new Settings(
            dataDir: $dataDir,
            timezone: new DateTimeZone('Europe/Berlin'),
            archiveInterval: $archiveInterval,
            archiveDelay: $archiveDelay,
            loopHilo: $loopHilo,
            latePackets: $latePackets,
            liveRetention: $liveRetention,
            rawRetention: 3600,
            timeBudget: 20,
            maxIntervalsPerRun: $maxIntervalsPerRun,
            journalMode: JournalMode::Wal,
            tickToken: null,
            logLevel: LogLevel::Debug,
        );
    }

    /**
     * @param list<string>|null $senders
     * @param array<string, bool> $indoor
     * @param array<string, ColumnType> $columns
     * @param array<string, array<string, string>> $fields
     * @param array<string, Extractor> $extractors
     * @param array<string, How> $calculate
     * @param array<string, QcRule> $qc
     * @param array<string, array<string, Calibration>> $calibrate
     */
    public static function config(
        ?string $primary = 'ecowitt',
        ?array $senders = ['ecowitt', 'dwd'],
        bool $autoMapping = false,
        array $indoor = [],
        array $columns = [],
        array $fields = [],
        array $extractors = [],
        array $calculate = [],
        ?UnitSystem $qcUnitSystem = null,
        array $qc = [],
        array $calibrate = [],
        ?float $latitude = 48.4596,
        ?float $longitude = 11.6539,
        ?Altitude $altitude = new Altitude(440.0, 'meter'),
        UnitSystem $unitSystem = UnitSystem::METRICWX,
        string $timezone = 'Europe/Berlin',
        string $database = '/nowhere/kirchdorf.sdb',
        string $id = 'kirchdorf',
    ): ArchiveConfig {
        return new ArchiveConfig(
            id: $id,
            name: 'Kirchdorf an der Amper',
            location: 'Kirchdorf an der Amper',
            latitude: $latitude,
            longitude: $longitude,
            altitude: $altitude,
            database: $database,
            unitSystem: $unitSystem,
            timezone: new DateTimeZone($timezone),
            primary: $primary,
            senders: $senders,
            autoMapping: $autoMapping,
            indoor: $indoor,
            columns: $columns,
            fields: $fields,
            extractors: $extractors,
            calculate: $calculate,
            qcUnitSystem: $qcUnitSystem,
            qc: $qc,
            calibrate: $calibrate,
        );
    }
}
