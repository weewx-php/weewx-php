<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Throwable;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Tick\Runtime;

/** Called under tick.lock. Ingest and uploads retain priority over analytics. */
final class Worker
{
    public function __construct(private readonly Runtime $runtime) {}

    /** @return array{completed: int, pending: int, failed: int} */
    public function run(Budget $budget): array
    {
        $answer = ['completed' => 0, 'pending' => 0, 'failed' => 0];
        $config = $this->runtime->config;
        if (!is_file(Cache::path($config->settings))) {
            return $answer;
        }
        $cache = new Cache($config->settings);
        try {
            foreach ($config->archives as $archive) {
                if (!$budget->allows()) {
                    return $answer;
                }
                if (is_file($archive->database)) {
                    $cache->recover($archive);
                    $cache->observe($archive);
                }
            }
            $now = $this->runtime->clock->now();
            foreach ($cache->due($now) as $job) {
                if (!$budget->allows() || $budget->timeLeft() < 0.05) {
                    break;
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
                $revision = $cache->revision($archiveId);
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
                        $cache->discardChunks($archiveId, $work->span);
                        $cache->observe($archive);
                        ++$answer['pending'];
                        continue;
                    }
                    if ($cache->publish($id, $work, $now, Weather::nextDue($work, $now, $archive, $config->settings->archiveInterval), $revision)) {
                        ++$answer['completed'];
                    } else {
                        $cache->discardChunks($archiveId, $work->span);
                        ++$answer['pending'];
                    }
                } catch (Deferred) {
                    if ($work !== null && $token === ArchiveReader::fingerprint($archive->database) && $revision === $cache->revision($archiveId) && !$cache->pending($archiveId)) {
                        $cache->progress($id, $work, $now, $revision);
                    } elseif ($work !== null) {
                        $cache->discardChunks($archiveId, $work->span);
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
        return $answer;
    }
}
