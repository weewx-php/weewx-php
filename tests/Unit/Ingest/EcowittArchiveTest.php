<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\Receiver;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;

final class EcowittArchiveTest extends TestCase
{
    public function testPendingInventoryThenAdoptedPacketsReachTypedArchiveColumns(): void
    {
        $dir = TempDir::create('ecowitt-fields');
        $config = ConfigReader::read(ConfFile::parse(<<<'CONF'
            data_dir = data
            [Ingest]
                enabled = true
            [Archives]
                [[garden]]
                    unit_system = METRICWX
                    [[[columns]]]
                        lightning_Batt = REAL
                        lightningBatteryStatus = REAL
                        lightningDayCount = REAL
                        windDir10 = REAL
                        maxdailygust = REAL
                        rain24 = REAL
                        soilMoistPct1 = REAL
                        soilEC1 = REAL
                        vpd = REAL
            CONF), $dir);
        $clock = new FixedClock(1788609610);
        $log = new MemoryLogger();
        $runtime = Runtime::of($config, $clock, $log);
        try {
            $receiver = new Receiver($runtime);
            $path = '/' . $runtime->ingest()->credentials()['ecowitt'] . '/ecowitt/';
            $send = static fn(string $body) => $receiver->handle('POST', $path, '', static fn(): string => $body, '192.0.2.1', true);
            $base = 'PASSKEY=' . str_repeat('A', 32) . '&tempf=68&dailyrainin=0&soil_ec_hum1=30&soil_ec1=60&vpd=0.047&soil_ec_ad1=410';
            $first = $base . '&wh57batt=1&winddir_avg10m=359&last24hrainin=0.8&maxdailygust=20&lightning_num=6';
            self::assertSame(200, $send($first)->status);
            self::assertFileDoesNotExist($config->settings->liveDbPath());
            [$sender] = $runtime->ingest()->senders();
            $runtime->ingest()->adopt($sender->id, null, $clock->now());
            self::assertSame(200, $send($first)->status);
            $clock->advance(100);
            self::assertSame(200, $send($base . '&wh57batt=5&winddir_avg10m=1&last24hrainin=0.1&maxdailygust=2&lightning_num=6')->status);
            $packets = iterator_to_array($runtime->live()->packets(1788609600, 1788609900));
            self::assertCount(2, $packets);
            self::assertArrayNotHasKey('soil_ec_ad1', $packets[1]->data);
            $clock->advance(300);
            self::assertSame('ok', (new Tick($runtime))->run('test')->status);
            $archive = $config->archive('garden');
            self::assertNotNull($archive);
            $db = Sqlite::readOnly($archive->database);
            try {
                $row = $db->one('SELECT * FROM archive WHERE dateTime = ?', [1788609900]);
                self::assertNotNull($row);
                self::assertSame(5.0, $row['lightning_Batt']);
                self::assertSame(0.0, $row['lightningBatteryStatus']);
                self::assertSame(6.0, $row['lightningDayCount']);
                self::assertSame(1.0, $row['windDir10']);
                self::assertEqualsWithDelta(0.89408, $row['maxdailygust'], 0.000001);
                self::assertEqualsWithDelta(2.54, $row['rain24'], 0.000001);
                self::assertSame(30.0, $row['soilMoistPct1']);
                self::assertSame(60.0, $row['soilEC1']);
                self::assertEqualsWithDelta(0.1591602, $row['vpd'], 0.000001);
                self::assertSame(0.0, $row['rain']);
            } finally {
                $db->close();
            }
        } finally {
            $runtime->close();
            TempDir::remove($dir);
        }
    }
}
