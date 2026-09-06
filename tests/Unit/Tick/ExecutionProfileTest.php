<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Tick;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\State\StateDb;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\ExecutionProfile;
use WeewxPhp\Time\FixedClock;

final class ExecutionProfileTest extends TestCase
{
    public function testKnownLimitsReserveHeadroomAndRespectManualBudgets(): void
    {
        self::assertSame(27.0, ExecutionProfile::limit(0, 30));
        self::assertSame(22.0, ExecutionProfile::limit(0, 30, 5));
        self::assertSame(10.0, ExecutionProfile::limit(10, 30));
        self::assertSame(0.0, ExecutionProfile::limit(0, 1));
    }

    public function testUnknownHostGrowsOnUsefulWorkAndRemembersInterruptedRuns(): void
    {
        $dir = TempDir::create('execution-profile');
        $db = StateDb::open($dir . '/state.sdb', JournalMode::Wal);
        $clock = new FixedClock(1000);
        $log = new MemoryLogger();
        try {
            $first = new ExecutionProfile($db, $clock, $log, 'web', 0, 0, 300);
            self::assertSame(20.0, $first->seconds());
            $clock->advance(20);
            $first->finish(true);
            $second = new ExecutionProfile($db, $clock, $log, 'web', 0, 0, 300);
            self::assertSame(25.0, $second->seconds());
            $clock->advance(12);
            $second->checkpoint();
            // A killed process cannot mark completion. The next lock owner sees its marker.
            $third = new ExecutionProfile($db, $clock, $log, 'web', 0, 0, 300);
            self::assertEqualsWithDelta(9.6, $third->seconds(), 0.001);
            $third->finish(false);
            $fourth = new ExecutionProfile($db, $clock, $log, 'web', 0, 0, 300);
            self::assertEqualsWithDelta(9.6, $fourth->seconds(), 0.001);
            $fourth->finish(false);
            // CLI observations do not change the web worker's profile.
            $cli = new ExecutionProfile($db, $clock, $log, 'cli', 0, 0, 300);
            self::assertSame(20.0, $cli->seconds());
            $cli->finish(false);
            $changed = new ExecutionProfile($db, $clock, $log, 'web', 60, 0, 300);
            self::assertSame(54.0, $changed->seconds());
            $changed->finish(false);
        } finally {
            $db->close();
            TempDir::remove($dir);
        }
    }
}
