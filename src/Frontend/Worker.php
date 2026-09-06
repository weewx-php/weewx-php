<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Throwable;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Tick\Runtime;

/** One analysis worker; the archive writer is locked only for recovery and configuration reads. */
final class Worker
{
    public function __construct(private readonly Runtime $runtime) {}

    /**
     * @param callable(): void|null $checkpoint Called between durable slices.
     * @return array{completed: int, pending: int, failed: int, busy: int}
     */
    public function run(Budget $budget, bool $drain = false, ?callable $checkpoint = null): array
    {
        $answer = ['completed' => 0, 'pending' => 0, 'failed' => 0, 'busy' => 0];
        $settings = $this->runtime->config->settings;
        if (!is_file(Cache::path($settings))) {
            return $answer;
        }
        $lock = \WeewxPhp\Tick\Lock::tryAcquire($settings->dataDir . '/analytics.lock');
        if ($lock === null) {
            $answer['busy'] = 1;
            return $answer;
        }
        $profile = null;
        try {
            if ($drain) {
                $profile = new \WeewxPhp\Tick\ExecutionProfile(
                    $this->runtime->state(),
                    $this->runtime->clock,
                    $this->runtime->log,
                    'analytics-' . PHP_SAPI,
                    (int) ini_get('max_execution_time'),
                    $settings->timeBudget,
                    max(0.0, $budget->timeLeft()),
                );
                $budget = Budget::of($this->runtime->clock, $profile->seconds(), PHP_INT_MAX);
            }
            do {
                if ($budget->timeLeft() <= 0.05) {
                    break;
                }
                // Never recover a live archive mutation, or wait behind its writer.
                $writer = \WeewxPhp\Tick\Lock::tryAcquire($settings->lockPath());
                if ($writer === null) {
                    $answer['busy'] = 1;
                    break;
                }
                try {
                    $this->runtime->refresh();
                    $cache = new Cache($settings);
                    try {
                        foreach ($this->runtime->config->archives as $archive) {
                            if (is_file($archive->database)) {
                                $cache->recover($archive);
                                $cache->observe($archive);
                            }
                        }
                    } finally {
                        $cache->close();
                    }
                } finally {
                    $writer->release();
                }
                $slice = $this->pass($budget);
                $profile?->checkpoint();
                $answer['completed'] += $slice['completed'];
                $answer['failed'] += $slice['failed'];
                $answer['pending'] = $slice['pending'];
                if ($checkpoint !== null) {
                    $checkpoint();
                }
            } while ($drain && $budget->timeLeft() > 0.05
                && $slice['completed'] + $slice['pending'] > 0);
        } finally {
            try {
                $profile?->finish($budget->timeLeft() <= 0.05);
            } finally {
                $lock->release();
            }
        }
        return $answer;
    }

    /** @return array{completed: int, pending: int, failed: int} */
    private function pass(Budget $budget): array
    {
        $answer = ['completed' => 0, 'pending' => 0, 'failed' => 0];
        $config = $this->runtime->config;
        if (!is_file(Cache::path($config->settings))) {
            return $answer;
        }
        $cache = new Cache($config->settings);
        try {
            $now = $this->runtime->clock->now();
            foreach ($cache->due($now) as $job) {
                if (!$budget->allows() || $budget->timeLeft() < 0.05) {
                    break;
                }
                $candidate = $job['id'] ?? null;
                $job = is_string($candidate) ? $cache->requestForWork($candidate) : null;
                if ($job === null) {
                    ++$answer['pending'];
                    continue;
                }
                $id = $job['id'] ?? null;
                $archiveId = $job['archive'] ?? null;
                $json = $job['spec'] ?? null;
                if (!is_string($id) || !is_string($archiveId) || !is_string($json)) {
                    continue;
                }
                $reader = null;
                $work = null;
                $readBudget = null;
                $archive = $config->archive($archiveId);
                $token = $archive === null ? '' : ArchiveReader::fingerprint($archive->database);
                $revision = Cache::integer($job['source_revision'] ?? null);
                $cache->guardWrites($archiveId, $revision);
                try {
                    if ($archive === null) {
                        throw new QueryError('Archive is no longer configured');
                    }
                    $readBudget = new ReadBudget(maxRows: 8192, maxStatements: 256, milliseconds: min(400, max(1, $budget->timeLeft() * 1000 - 25)));
                    $reader = new ArchiveReader($archive, $readBudget);
                    $spec = Spec::fromJson($json);
                    $work = is_string($job['work'] ?? null)
                        ? Computation::restore($job['work'], $spec, $reader)
                        : new Computation($spec, $reader, Weather::anchor($spec, $now, $reader->last));
                    do {
                        $finished = $work->step($readBudget, $cache);
                    } while (!$finished);
                    if ($token !== ArchiveReader::fingerprint($archive->database) || $revision !== $cache->revision($archiveId) || $cache->pending($archiveId)) {
                        $cache->observe($archive);
                        ++$answer['pending'];
                        continue;
                    }
                    if ($cache->publish($id, $work, $now, Weather::nextDue($work, $now, $archive, $config->settings->archiveInterval), $revision)) {
                        ++$answer['completed'];
                    } else {
                        ++$answer['pending'];
                    }
                } catch (Deferred) {
                    if ($work !== null && $token === ArchiveReader::fingerprint($archive->database) && $revision === $cache->revision($archiveId) && !$cache->pending($archiveId)) {
                        $cache->progress($id, $work, $now, $revision);
                    }
                    ++$answer['pending'];
                } catch (Throwable $error) {
                    $cache->failed($id, $error->getMessage(), $now);
                    $this->runtime->log->error('analytics ' . $id . ': ' . $error->getMessage());
                    ++$answer['failed'];
                } finally {
                    if ($readBudget !== null) {
                        $cache->diagnose($id, $readBudget->snapshot(), $now);
                    }
                    $reader?->close();
                }
            }
        } finally {
            $cache->close();
        }
        if ($answer['completed'] + $answer['pending'] + $answer['failed'] > 0) {
            $this->runtime->log->info(sprintf('analytics: %d completed, %d pending, %d failed', $answer['completed'], $answer['pending'], $answer['failed']));
        }
        return $answer;
    }
}
