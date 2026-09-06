<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Tick;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Background;
use WeewxPhp\Tick\Dispatcher;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Tick\Runtime;

final class DispatcherTest extends TestCase
{
    public function testDetachedOwnerRetriesATemporaryWriterConflict(): void
    {
        $dir = TempDir::create('dispatch-retry');
        file_put_contents($dir . '/weather.conf', "data_dir = data\nbackup_enabled = false\n");
        $runtime = Runtime::boot($dir . '/weather.conf', log: new MemoryLogger());
        $dispatcher = new Dispatcher($runtime, static fn(string $lane): bool => true);
        $dispatcher->request(['archive']);
        file_put_contents($dir . '/holder.php', '<?php $h=fopen($argv[1], "c"); flock($h, LOCK_EX); echo "ready\n"; flush(); usleep(300000); fclose($h);');
        $process = proc_open([PHP_BINARY, $dir . '/holder.php', $runtime->config->settings->lockPath()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            (new Background($runtime))->run('archive');
            self::assertFileExists($runtime->config->settings->liveDbPath());
            self::assertFileDoesNotExist($dispatcher->directory() . '/archive.claimed');
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(0, proc_close($process));
            $runtime->close();
            TempDir::remove($dir);
        }
    }

    public function testEnqueueNeverWaitsForWorkersOrExecutesInlineAndCoalescesWakeups(): void
    {
        $dir = TempDir::create('dispatch');
        file_put_contents($dir . '/weather.conf', "data_dir = data\nbackup_enabled = false\n");
        $runtime = Runtime::boot($dir . '/weather.conf', log: new MemoryLogger());
        $launches = [];
        $dispatcher = new Dispatcher($runtime, static function (string $lane) use (&$launches): bool {
            $launches[] = $lane;
            return true;
        });
        try {
            self::assertSame('queued', $dispatcher->request()['status']);
            self::assertSame(Dispatcher::LANES, $launches);
            self::assertFileDoesNotExist($runtime->config->settings->liveDbPath());
            $lock = Lock::tryAcquire($dispatcher->directory() . '/analytics.lock');
            self::assertNotNull($lock);
            try {
                for ($i = 0; $i < 10; ++$i) {
                    $dispatcher->request(['analytics']);
                }
                self::assertCount(4, $launches);
                self::assertFileExists($dispatcher->directory() . '/analytics.requested');
            } finally {
                $lock->release();
            }
            (new Background($runtime))->run('archive');
            self::assertFileExists($runtime->config->settings->liveDbPath());
            self::assertFileDoesNotExist($dispatcher->directory() . '/archive.requested');
            self::assertFileDoesNotExist($dispatcher->directory() . '/archive.claimed');
        } finally {
            $runtime->close();
            TempDir::remove($dir);
        }
    }
}
