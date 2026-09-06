<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

final class WorkerProgressTest extends TestCase
{
    public function testWeeklySeriesFinishesWhileNewStationRecordsKeepArriving(): void
    {
        $dir = TempDir::create('worker-weekly-progress');
        $archive = Archives::config(database: $dir . '/weather.sdb', unitSystem: UnitSystem::US);
        $config = new Config(Archives::settings($dir), [], [$archive->id => $archive], []);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $now = Span::timestamp('2026-09-06 09:05:00', $archive->timezone);
        $clock = new FixedClock($now);
        $db->transaction(function () use ($db, $now): void {
            for ($time = $now - 7 * 86400 + 600; $time <= $now; $time += 600) {
                $db->addRecord(['dateTime' => $time, 'usUnits' => 1, 'interval' => 10, 'outTemp' => 68.0]);
            }
        });
        $wx = new Weather($config, clock: $clock);
        $weekly = $wx->reference('archive')->last('7d')->series('outTemp', 'hour');
        $weekly->register();
        $runtime = \WeewxPhp\Tick\Runtime::of($config, $clock, new \WeewxPhp\Log\MemoryLogger());
        $cache = new \WeewxPhp\Frontend\Cache($config->settings);
        $changes = new \WeewxPhp\Frontend\ArchiveChanges($cache, $archive, new \WeewxPhp\Log\MemoryLogger());
        try {
            $worker = new \WeewxPhp\Frontend\Worker($runtime);
            $first = $worker->run(\WeewxPhp\Archive\Budget::of($clock, 3, 100));
            self::assertSame(1, $first['pending']);
            self::assertSame(0, $first['failed']);
            $changes->before($now, $now + 60);
            $db->addRecord(['dateTime' => $now + 60, 'usUnits' => 1, 'interval' => 1, 'outTemp' => 70.0]);
            $changes->after($now, $now + 60);
            $clock->advance(60);
            $second = $worker->run(\WeewxPhp\Archive\Budget::of($clock, 3, 100));
            self::assertSame(1, $second['completed'], 'A new measurement must not restart all seven days of work');
            $id = \WeewxPhp\Frontend\Cache::key($archive->id, $weekly->definition()['spec']);
            $payload = $cache->request($id)['payload'] ?? null;
            self::assertIsString($payload);
            $result = \WeewxPhp\Frontend\ResultCodec::decode($payload, $archive->timezone->getName());
            self::assertInstanceOf(\WeewxPhp\Frontend\Series::class, $result);
            self::assertGreaterThanOrEqual(168, count($result->points));
        } finally {
            $wx->close();
            $cache->close();
            $runtime->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testOneWorkerDrainsTheWeeklyQueryWhileLeavingArchiveWritesAvailable(): void
    {
        $dir = TempDir::create('worker-weekly-progress');
        $archive = Archives::config(database: $dir . '/weather.sdb', unitSystem: UnitSystem::US);
        $config = new Config(Archives::settings($dir), [], [$archive->id => $archive], []);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $now = Span::timestamp('2026-09-06 09:05:00', $archive->timezone);
        $clock = new FixedClock($now);
        $db->transaction(function () use ($db, $now): void {
            for ($time = $now - 7 * 86400 + 600; $time <= $now; $time += 600) {
                $db->addRecord(['dateTime' => $time, 'usUnits' => 1, 'interval' => 10, 'outTemp' => 68.0]);
            }
        });
        $wx = new Weather($config, clock: $clock);
        $weekly = $wx->reference('archive')->last('7d')->series('outTemp', 'hour');
        $weekly->register();
        $runtime = \WeewxPhp\Tick\Runtime::of($config, $clock, new \WeewxPhp\Log\MemoryLogger());
        $cache = new \WeewxPhp\Frontend\Cache($config->settings);
        $changes = new \WeewxPhp\Frontend\ArchiveChanges($cache, $archive, new \WeewxPhp\Log\MemoryLogger());
        try {
            $worker = new \WeewxPhp\Frontend\Worker($runtime);
            $slices = 0;
            $answer = $worker->run(
                \WeewxPhp\Archive\Budget::of($clock, 10, 100),
                drain: true,
                checkpoint: function () use (&$slices, $config, $worker, $clock): void {
                    ++$slices;
                    $writer = \WeewxPhp\Tick\Lock::tryAcquire($config->settings->lockPath());
                    self::assertNotNull($writer, 'Analysis must leave the archive writer lock available');
                    $writer->release();
                    self::assertSame(1, $worker->run(\WeewxPhp\Archive\Budget::of($clock, 1, 100))['busy'], 'A second analysis must not duplicate the active calculation');
                },
            );
            self::assertSame(1, $answer['completed']);
            self::assertSame(0, $answer['pending']);
            self::assertGreaterThanOrEqual(2, $slices, 'Pending work continues within the same worker run');
            $id = \WeewxPhp\Frontend\Cache::key($archive->id, $weekly->definition()['spec']);
            $payload = $cache->request($id)['payload'] ?? null;
            self::assertIsString($payload);
            $result = \WeewxPhp\Frontend\ResultCodec::decode($payload, $archive->timezone->getName());
            self::assertInstanceOf(\WeewxPhp\Frontend\Series::class, $result);
            self::assertGreaterThanOrEqual(168, count($result->points));
        } finally {
            $wx->close();
            $cache->close();
            $runtime->close();
            $db->close();
            TempDir::remove($dir);
        }
    }
}
