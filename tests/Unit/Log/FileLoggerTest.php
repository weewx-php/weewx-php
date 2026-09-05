<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Log;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Log\FileLogger;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;

final class FileLoggerTest extends TestCase
{
    /** 2026-09-04 23:30:00 in Berlin, so the next line an hour later is a new day. */
    private const LATE_EVENING = 1788557400;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('log');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testWritesLocalTimeAndLabelAndCreatesTheDirectory(): void
    {
        $clock = new FixedClock(self::LATE_EVENING);
        $log = $this->logger($clock, LogLevel::Info);

        $log->debug('not written');
        $log->warning('written');

        self::assertSame(
            "2026-09-04 23:30:00 WARN  written\n",
            file_get_contents($this->dir . '/log/weewx-php.log'),
        );
    }

    public function testRotatesOnTheFirstLineOfANewDayAndPrunesOldFiles(): void
    {
        $clock = new FixedClock(self::LATE_EVENING);
        $log = $this->logger($clock, LogLevel::Debug, keepDays: 7);
        $log->info('yesterday');

        // Rotated files an installation has accumulated: one within the
        // retention, one beyond it.
        touch($this->dir . '/log/weewx-php.2026-09-01.log');
        touch($this->dir . '/log/weewx-php.2026-08-01.log');

        $clock->advance(3600);
        $log->info('today');

        self::assertSame(
            "2026-09-05 00:30:00 INFO  today\n",
            file_get_contents($this->dir . '/log/weewx-php.log'),
        );
        self::assertSame(
            "2026-09-04 23:30:00 INFO  yesterday\n",
            file_get_contents($this->dir . '/log/weewx-php.2026-09-04.log'),
        );
        self::assertFileExists($this->dir . '/log/weewx-php.2026-09-01.log');
        self::assertFileDoesNotExist($this->dir . '/log/weewx-php.2026-08-01.log');
    }

    private function logger(FixedClock $clock, LogLevel $threshold, int $keepDays = 14): FileLogger
    {
        return new FileLogger(
            $this->dir . '/log/weewx-php.log',
            $threshold,
            new DateTimeZone('Europe/Berlin'),
            $clock,
            $keepDays,
        );
    }
}
