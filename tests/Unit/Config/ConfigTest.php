<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\How;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\LatePackets;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\UnitSystem;

final class ConfigTest extends TestCase
{
    private const BASE = '/srv/weewx-php';

    private static function example(): string
    {
        return implode("\n", [
            'data_dir = data',
            'timezone = Europe/Berlin',
            'archive_interval = 5m',
            'archive_delay = 20',
            'late_packets = rebuild',
            'live_retention = 3d',
            'tick_token = "secret"',
            'log_level = debug',
            'colour = blue',
            '',
            '[Stations]',
            '    [[ecowitt]]',
            '        name = "HP2561AE Pro"',
            '        expected_interval = 16',
            '        down_after = 40',
            '    [[dwd]]',
            '',
            '[Archives]',
            '    [[kirchdorf]]',
            '        name = "Kirchdorf an der Amper"',
            '        latitude = 48.4596',
            '        longitude = 11.6539',
            '        altitude = 1443.57, foot',
            '        unit_system = METRICWX',
            '        primary = ecowitt',
            '        senders = ecowitt, dwd',
            '        auto_mapping = true',
            '        [[[members]]]',
            '            [[[[dwd]]]]',
            '                indoor = false',
            '        [[[columns]]]',
            '            extraTemp9 = real',
            '            lightning_num = INTEGER',
            '        [[[fields]]]',
            '            [[[[ecowitt]]]]',
            '                inTemp = -',
            '            [[[[dwd]]]]',
            '                outTemp = extraTemp1',
            '        [[[extractors]]]',
            '            lightning_num = last',
            '        [[[calculate]]]',
            '            dewpoint = software',
            '            unicorns = prefer_hardware',
            '        [[[qc]]]',
            '            unit_system = METRIC',
            '            outTemp = -40, 60',
            '            outHumidity = 0, 100, percent',
            '        [[[calibrate]]]',
            '            [[[[ecowitt]]]]',
            '                outTemp = -0.4',
            '                outHumidity = 2, 1.01',
            '    [[shed]]',
            '        database = /var/lib/weewx/shed.sdb',
            '        senders = *',
        ]) . "\n";
    }

    public function testReadsTheWholeFileIntoTypedValues(): void
    {
        $config = ConfigReader::read(ConfFile::parse(self::example()), self::BASE);
        $settings = $config->settings;

        self::assertSame(self::BASE . '/data', $settings->dataDir);
        self::assertSame(self::BASE . '/data/live.sdb', $settings->liveDbPath());
        self::assertSame('Europe/Berlin', $settings->timezone->getName());
        self::assertSame(300, $settings->archiveInterval);
        self::assertSame(20, $settings->archiveDelay);
        self::assertTrue($settings->loopHilo);
        self::assertSame(LatePackets::Rebuild, $settings->latePackets);
        self::assertSame(3 * 86400, $settings->liveRetention);
        self::assertSame(3600, $settings->rawRetention);
        self::assertSame(0, $settings->timeBudget);
        self::assertSame(100, $settings->maxIntervalsPerRun);
        self::assertSame(JournalMode::Wal, $settings->journalMode);
        self::assertSame('secret', $settings->tickToken);
        self::assertSame(LogLevel::Debug, $settings->logLevel);

        $ecowitt = $config->stations['ecowitt'];
        self::assertSame('HP2561AE Pro', $ecowitt->name);
        self::assertSame(16, $ecowitt->expectedInterval);
        self::assertSame(3, $ecowitt->staleAfter);
        self::assertSame(40, $ecowitt->downAfter);
        self::assertSame('dwd', $config->stations['dwd']->name);
        self::assertNull($config->stations['dwd']->expectedInterval);

        $archive = $config->archives['kirchdorf'];
        self::assertSame('Kirchdorf an der Amper', $archive->name);
        self::assertSame('Kirchdorf an der Amper', $archive->location);
        self::assertSame(48.4596, $archive->latitude);
        self::assertSame(11.6539, $archive->longitude);
        self::assertNotNull($archive->altitude);
        self::assertSame(1443.57, $archive->altitude->value);
        self::assertSame('foot', $archive->altitude->unit);
        self::assertSame(1443.57, $archive->altitude->in('foot'));
        self::assertEqualsWithDelta(440.0, $archive->altitude->in('meter'), 0.01);
        self::assertSame(self::BASE . '/data/archives/kirchdorf.sdb', $archive->database);
        self::assertSame(UnitSystem::METRICWX, $archive->unitSystem);
        self::assertSame('Europe/Berlin', $archive->timezone->getName());
        self::assertSame('ecowitt', $archive->primary);
        self::assertSame(['ecowitt', 'dwd'], $archive->senders);
        self::assertTrue($archive->autoMapping);
        self::assertFalse($archive->takesIndoor('dwd'));
        self::assertTrue($archive->takesIndoor('ecowitt'));
        self::assertSame(['extraTemp9' => ColumnType::Real, 'lightning_num' => ColumnType::Integer], $archive->columns);
        self::assertSame(['ecowitt' => ['inTemp' => '-'], 'dwd' => ['outTemp' => 'extraTemp1']], $archive->fields);
        self::assertSame(['lightning_num' => Extractor::Last], $archive->extractors);
        self::assertSame(['dewpoint' => How::Software], $archive->calculate);
        self::assertSame(UnitSystem::METRIC, $archive->qcUnitSystem);
        self::assertSame(-40.0, $archive->qc['outTemp']->minimum);
        self::assertSame(60.0, $archive->qc['outTemp']->maximum);
        self::assertNull($archive->qc['outTemp']->unit);
        self::assertSame('percent', $archive->qc['outHumidity']->unit);
        self::assertSame(-0.4, $archive->calibrate['ecowitt']['outTemp']->offset);
        self::assertSame(1.0, $archive->calibrate['ecowitt']['outTemp']->scale);
        self::assertSame(1.01, $archive->calibrate['ecowitt']['outHumidity']->scale);

        $shed = $config->archives['shed'];
        self::assertSame('/var/lib/weewx/shed.sdb', $shed->database);
        self::assertNull($shed->senders);
        self::assertTrue($shed->selects('anybody'));
        self::assertNull($shed->primary);
        self::assertSame(UnitSystem::US, $shed->unitSystem);
        self::assertNull($shed->altitude);

        self::assertSame([
            '[Archives][[kirchdorf]][[[calculate]]] unicorns: nothing here can derive unicorns; ignored',
            'colour: unknown setting, ignored',
        ], $config->warnings);
    }

    public function testDefaultsWithoutAnySetting(): void
    {
        $config = ConfigReader::read(ConfFile::parse(''), self::BASE);

        self::assertSame(self::BASE . '/data', $config->settings->dataDir);
        self::assertSame('UTC', $config->settings->timezone->getName());
        self::assertSame(300, $config->settings->archiveInterval);
        self::assertSame(15, $config->settings->archiveDelay);
        self::assertSame(LatePackets::Ignore, $config->settings->latePackets);
        self::assertSame(7 * 86400, $config->settings->liveRetention);
        self::assertNull($config->settings->tickToken);
        self::assertSame(LogLevel::Info, $config->settings->logLevel);
        self::assertSame([], $config->stations);
        self::assertSame([], $config->archives);
        self::assertSame([], $config->warnings);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'interval not whole minutes' => ['archive_interval = 90', 'archive_interval: must be a whole number'];
        yield 'unknown time zone' => ['timezone = Mars/Olympus', "timezone: unknown time zone 'Mars/Olympus'"];
        yield 'unknown sender' => ["[Stations]\n[[a]]\n[Archives]\n[[x]]\nprimary = b", '[Archives][[x]] primary: b is not a station'];
        yield 'primary not selected' => ["[Stations]\n[[a]]\n[[b]]\n[Archives]\n[[x]]\nprimary = b\nsenders = a,", '[Archives][[x]] primary: b is not among the senders'];
        yield 'bad column type' => ["[Archives]\n[[x]]\n[[[columns]]]\nfoo = BLOB", '[Archives][[x]][[[columns]]] foo: Unknown column type'];
        yield 'reserved column' => ["[Archives]\n[[x]]\n[[[columns]]]\ndateTime = REAL", 'dateTime is not a column a reading can go to'];
        yield 'bad altitude unit' => ["[Archives]\n[[x]]\naltitude = 440, cubit", 'the unit must be meter or foot'];
        yield 'qc limits reversed' => ["[Archives]\n[[x]]\n[[[qc]]]\noutTemp = 60, -40", 'the minimum is above the maximum'];
        yield 'bad extractor' => ["[Archives]\n[[x]]\n[[[extractors]]]\nrain = median", 'expected one of avg, sum, first, last, min, max, count, wind, noop'];
        yield 'bad id' => ["[Archives]\n[[my archive]]", 'the name may hold letters'];
        yield 'placement outside a sender' => ["[Stations]\n[[a]]\n[Archives]\n[[x]]\n[[[fields]]]\n[[[[a]]]]\ntempf = out Temp", "'out Temp' is not a usable column name"];
    }

    /**
     * @dataProvider brokenFiles
     */
    public function testRefusesWhatDoesNotHoldTogether(string $text, string $message): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage($message);
        ConfigReader::read(ConfFile::parse($text), self::BASE);
    }
}
