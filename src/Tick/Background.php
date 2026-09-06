<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Frontend\Worker;

/** Detached CLI workers. Each lane has exactly one owner; archive writes stay independent. */
final class Background
{
    public function __construct(private readonly Runtime $runtime) {}

    public function run(string $lane): void
    {
        Dispatcher::validate($lane);
        $dispatcher = new Dispatcher($this->runtime);
        $dir = $dispatcher->directory();
        $requested = $dir . '/' . $lane . '.requested';
        $claim = $dir . '/' . $lane . '.claimed';
        $retry = false;
        $started = $this->runtime->clock->monotonic();
        do {
            $lock = Lock::tryAcquire($dir . '/' . $lane . '.lock');
            if ($lock === null) {
                return;
            }
            try {
                clearstatcache(true, $requested);
                if (is_file($requested)) {
                    rename($requested, $claim);
                } elseif (!is_file($claim)) {
                    return;
                }
                $retryUntil = hrtime(true) + 2_000_000_000;
                do {
                    $retry = !$this->work($lane);
                    if ($retry && hrtime(true) < $retryUntil) {
                        // Only this detached owner waits. HTTP never waits for a writer,
                        // and a fast maintenance lane cannot starve archive startup.
                        usleep(50000);
                    }
                } while ($retry && hrtime(true) < $retryUntil);
                if (!$retry) {
                    unlink($claim);
                }
            } finally {
                $lock->release();
            }
            // Recheck only after release: a concurrent enqueue cannot lose its wakeup.
            clearstatcache(true, $requested);
        } while (!$retry && is_file($requested) && $this->runtime->clock->monotonic() - $started < 240);
        // Busy/unfinished jobs remain durable for the next wakeup, without a spin loop.
    }

    private function work(string $lane): bool
    {
        $clock = $this->runtime->clock;
        $budget = Budget::of($clock, ExecutionProfile::limit($this->runtime->config->settings->timeBudget, (int) ini_get('max_execution_time')), PHP_INT_MAX);
        if ($lane === 'archive') {
            return (new Tick($this->runtime))->run('background', archiveOnly: true)->status !== Outcome::BUSY;
        }
        if ($lane === 'analytics') {
            $probe = Lock::tryAcquire($this->runtime->config->settings->lockPath());
            if ($probe === null) {
                return false;
            }
            $probe->release();
            $result = (new Worker($this->runtime))->run($budget, drain: true);
            return $result['busy'] === 0 && $result['pending'] === 0;
        }
        $writer = Lock::tryAcquire($this->runtime->config->settings->lockPath());
        if ($writer === null) {
            return false;
        }
        $archivers = [];
        try {
            $this->runtime->refresh();
            if ($lane === 'maintenance') {
                // Repairs mutate archives and therefore take a short writer turn.
                (new Tick($this->runtime))->maintenance(Budget::of($clock, 2, PHP_INT_MAX));
                if ($this->runtime->configPath() !== null) {
                    (new \WeewxPhp\Backup\Backups($this->runtime->config->settings))->run($this->runtime, snapshotReady: static function () use (&$writer): void {
                        $writer?->release();
                        $writer = null;
                    });
                }
                return true;
            }
            if ($this->runtime->config->uploads !== []) {
                foreach ($this->runtime->config->archives as $id => $archive) {
                    if ($archive->enabled) {
                        $archivers[$id] = Archiver::open(
                            $archive,
                            $this->runtime->config->settings,
                            $this->runtime->live(),
                            $this->runtime->state(),
                            $this->runtime->log,
                            $clock->now(),
                            $this->runtime->archiveChanges($archive),
                        );
                    }
                }
            }
            $writer->release();
            $writer = null;
            // Network waits hold neither the archive writer nor the analysis lock.
            $this->runtime->uploads()->run($budget, $archivers, $clock->now());
            $this->runtime->extensions($budget);
            return true;
        } finally {
            foreach ($archivers as $archiver) {
                $archiver->close();
            }
            $writer?->release();
        }
    }
}
