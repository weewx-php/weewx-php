<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Log;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Log\MemoryLogger;

final class MemoryLoggerTest extends TestCase
{
    public function testKeepsEveryLineAndFiltersOnRequest(): void
    {
        $log = new MemoryLogger();
        $log->debug('one');
        $log->info('two');
        $log->warning('three');
        $log->error('four');

        self::assertCount(4, $log->lines());
        self::assertSame(['three', 'four'], $log->messages(LogLevel::Warning));
        self::assertSame(['one', 'two', 'three', 'four'], $log->messages());
    }

    public function testLevelNamesAreCaseInsensitiveAndChecked(): void
    {
        self::assertSame(LogLevel::Warning, LogLevel::fromName('WARNING'));
        self::assertSame(LogLevel::Debug, LogLevel::fromName('debug'));

        $this->expectException(\InvalidArgumentException::class);
        LogLevel::fromName('loud');
    }
}
