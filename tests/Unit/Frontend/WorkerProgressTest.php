<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

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
    public function testDerivedAlltimeWorkSurvivesWritesBeyondItsCursor(): void
    {
        $dir = TempDir::create('worker-alltime-progress');
        $archive = Archives::config(database: $dir . '/weather.sdb');
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $now = Span::timestamp('2026-09-06 12:00:00', $archive->timezone);
        $db->transaction(function () use ($db, $now): void {
            for ($i = 0; $i < 1536; ++$i) {
                $db->addRecord(['dateTime' => $now - $i * 300, 'usUnits' => 17, 'interval' => 5, 'outTemp' => 20.0, 'outHumidity' => 60.0, 'windSpeed' => 2.0]);
            }
        });
        $cache = new \WeewxPhp\Frontend\Cache(Archives::settings($dir));
        $schema = new \SQLite3($archive->database);
        $schema->exec('DROP TABLE archive_day_appTemp');
        $schema->close();
        $budget = new \WeewxPhp\Frontend\ReadBudget(maxRows: 600, maxStatements: 1000, milliseconds: 5000);
        $reader = new \WeewxPhp\Frontend\ArchiveReader($archive, $budget);
        $spec = new \WeewxPhp\Frontend\Spec(period: 'alltime', observation: 'appTemp', aggregate: 'max');
        try {
            $revision = $cache->observe($archive);
            $id = $cache->register($archive->id, $spec, $now);
            $work = new \WeewxPhp\Frontend\Computation($spec, $reader, $now);
            try {
                while (!$work->step($budget, $cache)) {
                }
                self::fail('The first slice must leave resumable work');
            } catch (\WeewxPhp\Frontend\Deferred) {
                $cache->progress($id, $work, $now, $revision);
            }
            $cache->invalidate($archive->id, Span::calendar('day', $now, $archive->timezone));
            $saved = $cache->request($id)['work'] ?? null;
            self::assertIsString($saved, 'An append must preserve the unmodified prefix');
            $reader->close();
            $budget = new \WeewxPhp\Frontend\ReadBudget(maxRows: 10000, maxStatements: 1000, milliseconds: 5000);
            $reader = new \WeewxPhp\Frontend\ArchiveReader($archive, $budget);
            $work = \WeewxPhp\Frontend\Computation::restore($saved, $spec, $reader);
            while (!$work->step($budget, $cache)) {
            }
            self::assertTrue($cache->publish($id, $work, $now, $now + 300, $cache->revision($archive->id)));
            self::assertIsString($cache->request($id)['payload'] ?? null);
            $cache->progress($id, $work, $now, $cache->revision($archive->id));
            $cache->invalidate($archive->id, new Span($now - 6 * 86400, $now - 4 * 86400));
            self::assertNull($cache->request($id)['work'] ?? null, 'An edit in consumed history must restart work');
        } finally {
            $reader->close();
            $cache->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

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
