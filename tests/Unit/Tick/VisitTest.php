<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Tick;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Dispatcher;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Tick\Visit;
use WeewxPhp\Time\FixedClock;

final class VisitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('visit');
        file_put_contents($this->dir . '/weather.conf', "data_dir = data\nbackup_enabled = false\nvisit_tick_enabled = true\n");
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testVisitorsRunWithoutStationsButOnlyOncePerMinuteAndRespectOtherTicks(): void
    {
        $clock = new FixedClock(1788681600);
        $runtime = Runtime::boot($this->dir . '/weather.conf', $clock, new MemoryLogger());
        try {
            $first = (new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true)))->run();
            self::assertIsArray($first);
            self::assertSame('queued', $first['status']);
            self::assertSame([], $runtime->state()->runs());
            self::assertNull((new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true)))->run());
            $clock->advance(60);
            $later = (new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true)))->run();
            self::assertIsArray($later);
            self::assertSame('queued', $later['status']);
            $clock->advance(60);
            (new Tick($runtime))->run('cron');
            $afterCron = new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true));
            self::assertIsArray($afterCron->run());
            self::assertCount(1, $runtime->state()->runs());
        } finally {
            $runtime->close();
        }
    }

    public function testDisabledVisitNeverCreatesRuntimeFiles(): void
    {
        file_put_contents($this->dir . '/weather.conf', "data_dir = data\nvisit_tick_enabled = false\n");
        $runtime = Runtime::boot($this->dir . '/weather.conf', new FixedClock(1788681600), new MemoryLogger());
        self::assertNull((new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true)))->run());
        self::assertDirectoryDoesNotExist($this->dir . '/data');
        $runtime->close();
    }

    public function testVisitorDoesNotBypassWriterLock(): void
    {
        $runtime = Runtime::boot($this->dir . '/weather.conf', new FixedClock(1788681600), new MemoryLogger());
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($runtime->config->settings->lockPath());
        self::assertNotNull($lock);
        try {
            self::assertSame('queued', (new Visit($runtime, new Dispatcher($runtime, static fn(string $lane): bool => true)))->run()['status'] ?? null);
            self::assertSame([], $runtime->state()->runs());
        } finally {
            $lock->release();
            $runtime->close();
        }
    }
}
