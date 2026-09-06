<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use Throwable;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\LatePackets;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\State\StateDb;

/**
 * One turn of the crank: everything the application does between two
 * calls, whether an ingest, a cron job or a browser made the call.
 *
 * Under the lock: mark which archives exist for the ingest, build what is
 * due for each archive within the budget, walk the journal in full when
 * that has not been done for an hour, judge the stations' silence, and
 * every ten minutes let old packets go. Each archive's failure is its own:
 * logged, noted, and the next archive is still done.
 */
final class Tick
{
    /** Seconds between two prunings of the journal. */
    public const PRUNE_EVERY = 600;

    /** Seconds after which the journal is walked in full again, for intervals whose marks were lost. */
    public const CATCH_UP_EVERY = 3600;

    private const PRUNED_AT = 'pruned_at';

    public function __construct(private readonly Runtime $runtime) {}

    /** @param string $trigger Who called: `cron`, `http`, `ingest`, `cli`. */
    public function run(string $trigger, bool $archiveOnly = false): Outcome
    {
        $clock = $this->runtime->clock;
        $config = $this->runtime->config;
        $started = $clock->now();
        $startedAt = $clock->monotonic();
        $elapsed = static fn(): int => (int) round(($clock->monotonic() - $startedAt) * 1000);

        $this->runtime->ensureDataDir();
        $lock = Lock::tryAcquire($config->settings->lockPath());
        if ($lock === null) {
            $this->runtime->log->debug(sprintf('tick (%s): another tick is running', $trigger));
            return Outcome::busy($elapsed());
        }
        $execution = null;
        try {
            $this->runtime->refresh();
            $config = $this->runtime->config;
            foreach ($config->warnings as $warning) {
                $this->runtime->log->warning('configuration: ' . $warning);
            }
            $live = $this->runtime->live();
            $state = $this->runtime->state();
            $live->setMeta(LiveDb::ARCHIVES_KEY, implode(',', array_keys($config->intervals())));
            $live->setMeta('archive_intervals', json_encode($config->intervals(), JSON_THROW_ON_ERROR));

            $overallBudget = Budget::of($clock, $this->timeBudget(), PHP_INT_MAX);
            $execution = new ExecutionProfile(
                $state,
                $clock,
                $this->runtime->log,
                'archive-' . PHP_SAPI,
                (int) ini_get('max_execution_time'),
                $config->settings->timeBudget,
                max(0.0, $overallBudget->timeLeft()),
            );
            $budget = Budget::of($clock, $execution->seconds(), PHP_INT_MAX);
            $archives = [];
            $archivers = [];
            foreach ($config->archives as $id => $archive) {
                if (!$archive->enabled) {
                    continue;
                }
                [$archives[$id], $archiver] = $this->runArchive($archive, $budget->share($config->settings->maxIntervalsPerRun), $live, $state, $started);
                $execution->checkpoint();
                if ($archiver !== null) {
                    $archivers[$id] = $archiver;
                }
            }
            // The uploads read the archives the archivers just wrote, so they
            // go before the archivers close; what the budget has left is theirs.
            $uploads = [];
            try {
                if (!$archiveOnly && $config->uploads !== []) {
                    $uploads = $this->runtime->uploads()->run($budget->share(PHP_INT_MAX), $archivers, $started);
                }
            } finally {
                foreach ($archivers as $archiver) {
                    $archiver->close();
                }
            }
            $stations = $this->watchStations($live, $state, $started);
            $maintenanceOk = true;
            $backup = [];
            if (!$archiveOnly) {
                $this->prune($live, $started);
                if (is_file($config->settings->ingestDbPath())) {
                    $this->runtime->ingest()->prune($started);
                }
                $maintenanceOk = \WeewxPhp\Admin\Jobs::run($this->runtime, $budget);
                $backup = $this->runtime->configPath() === null ? [] : (new \WeewxPhp\Backup\Backups($config->settings))->run($this->runtime);
            }
            $execution->finish($budget->timeLeft() <= 0.05);
            $execution = null;
            // Journal ingestion and archive intervals can proceed during analysis reads.
            $lock->release();
            $lock = null;
            $extensions = $archiveOnly ? [] : $this->runtime->extensions($overallBudget);
            $analytics = ['completed' => 0, 'pending' => 0, 'failed' => 0, 'busy' => 0];
            try {
                if (!$archiveOnly) {
                    $analytics = (new Worker($this->runtime))->run($overallBudget, drain: true);
                }
            } catch (Throwable $error) {
                $analytics['failed'] = 1;
                $this->runtime->log->error('analytics: ' . $error->getMessage());
            }

            $failed = array_filter($archives, static fn(array $one): bool => isset($one['error']));
            $outcome = new Outcome($failed === [] && $analytics['failed'] === 0 && $maintenanceOk && ($backup['status'] ?? '') !== 'failed' ? Outcome::OK : Outcome::ERROR, $archives, $stations, $elapsed(), $uploads, $backup, $maintenanceOk, $analytics, $extensions);
            $state->addRun($started, $clock->now(), $trigger, $outcome->toArray());
            $this->runtime->log->info(sprintf('tick (%s): %s', $trigger, self::describe($outcome)));
            return $outcome;
        } finally {
            try {
                $execution?->finish(false);
            } finally {
                $lock?->release();
            }
        }
    }

    /** Called by the maintenance lane under its short archive-writer turn. */
    public function maintenance(Budget $budget): void
    {
        $now = $this->runtime->clock->now();
        $this->prune($this->runtime->live(), $now);
        if (is_file($this->runtime->config->settings->ingestDbPath())) {
            $this->runtime->ingest()->prune($now);
        }
        \WeewxPhp\Admin\Jobs::run($this->runtime, $budget);
    }

    /**
     * One archive brought up to date. The archiver comes back open, for the
     * uploads to read from, or null when it could not be opened or failed.
     *
     * @return array{0: array<string, mixed>, 1: Archiver|null}
     */
    private function runArchive(ArchiveConfig $config, Budget $budget, LiveDb $live, StateDb $state, int $now): array
    {
        $log = $this->runtime->log;
        $replace = $this->runtime->config->settings->latePackets === LatePackets::Rebuild;
        try {
            $archiver = Archiver::open($config, $this->runtime->config->settings, $live, $state, $log, $now, $this->runtime->archiveChanges($config));
        } catch (Throwable $error) {
            $log->error(sprintf('%s: %s', $config->id, $error->getMessage()));
            $state->noteError($config->id, $now, $error->getMessage());
            return [['records' => 0, 'error' => $error->getMessage()], null];
        }
        try {
            // Close the newest intervals before spending the remaining turn on history.
            $written = $archiver->processDue(
                $now,
                Budget::of($this->runtime->clock, min(2.0, max(0.0, $budget->timeLeft())), 3),
                $replace,
                max(0, $now - $this->runtime->config->settings->archiveDelay - 3 * $config->interval($this->runtime->config->settings)),
            );
            $written += $archiver->processReplay($now, $budget);
            // A repair owns its day summaries until its durable cursor is done.
            if ($live->replay()->job($config->id) !== null) {
                $state->noteRun($config->id, $now, $written, $archiver->archive()->lastTimestamp());
                return [['records' => $written, 'replay_pending' => true, 'budget_left' => $budget->allows()], $archiver];
            }
            $written += $archiver->processDue($now, $budget, $replace);
            $caughtUp = $state->archive($config->id)->caughtUpAt;
            $walked = false;
            if ($caughtUp === null || $now - $caughtUp >= self::CATCH_UP_EVERY) {
                $progress = $archiver->catchUp(null, null, $budget, $replace);
                $written += $progress->built;
                $walked = $progress->finished;
                if ($progress->finished) {
                    $state->noteCaughtUp($config->id, $now);
                }
            }
            $state->noteRun($config->id, $now, $written, $archiver->archive()->lastTimestamp());
            return [['records' => $written, 'caught_up' => $walked, 'budget_left' => $budget->allows()], $archiver];
        } catch (Throwable $error) {
            $log->error(sprintf('%s: %s', $config->id, $error->getMessage()));
            $state->noteError($config->id, $now, $error->getMessage());
            $archiver->close();
            return [['records' => 0, 'error' => $error->getMessage()], null];
        }
    }

    /**
     * Every configured station judged by its silence, transitions logged.
     *
     * @return array<string, array<string, mixed>>
     */
    private function watchStations(LiveDb $live, StateDb $state, int $now): array
    {
        $heard = $live->lastSeen();
        $stations = [];
        foreach ($this->runtime->config->stations as $id => $station) {
            $lastSeen = $heard[$id] ?? null;
            $status = StationWatch::judge($station, $lastSeen, $now);
            $before = $state->station($id);
            if ($before->status !== $status) {
                $this->runtime->log->info(sprintf(
                    'station %s: %s, was %s%s',
                    $id,
                    $status->value,
                    $before->status->value,
                    $lastSeen === null ? '' : sprintf(' (last heard %d s ago)', $now - $lastSeen),
                ));
            }
            $after = $state->setStation($id, $lastSeen, $status, $now);
            $stations[$id] = ['status' => $status->value, 'last_seen' => $lastSeen, 'since' => $after->statusSince];
        }
        return $stations;
    }

    /** Let old packets and older raw uploads go, at most every ten minutes. */
    private function prune(LiveDb $live, int $now): void
    {
        $settings = $this->runtime->config->settings;
        $last = (int) ($live->getMeta(self::PRUNED_AT) ?? '0');
        if ($now - $last < self::PRUNE_EVERY) {
            return;
        }
        $forgotten = $live->forgetRaw($now - $settings->rawRetention);
        $live->replay()->retain(array_keys($this->runtime->config->archives));
        $live->collector()->prune($now);
        $pruned = $live->prune($now - $settings->liveRetention);
        if ($pruned > 0) {
            $live->vacuum();
        }
        $live->setMeta(self::PRUNED_AT, (string) $now);
        if ($pruned > 0 || $forgotten > 0) {
            $this->runtime->log->debug(sprintf('journal: pruned %d packet(s), forgot %d raw upload(s)', $pruned, $forgotten));
        }
    }

    /** Seconds a tick may spend: the configured budget, capped under PHP's own limit. */
    private function timeBudget(): float
    {
        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $elapsed = PHP_SAPI !== 'cli' && is_float($requestStart) ? max(0.0, microtime(true) - $requestStart) : 0.0;
        return ExecutionProfile::limit($this->runtime->config->settings->timeBudget, (int) ini_get('max_execution_time'), $elapsed);
    }

    private static function describe(Outcome $outcome): string
    {
        $parts = [];
        foreach ($outcome->archives as $id => $result) {
            $records = $result['records'] ?? 0;
            $parts[] = isset($result['error'])
                ? sprintf('%s failed', $id)
                : sprintf('%s %s', $id, is_int($records) ? sprintf('%d record(s)', $records) : '?');
        }
        foreach ($outcome->uploads as $id => $result) {
            $sent = $result['sent'] ?? null;
            $parts[] = match (true) {
                isset($result['error']) => sprintf('upload %s failed', $id),
                isset($result['blocked']) => sprintf('upload %s off', $id),
                isset($result['skipped']) && !is_int($sent) => sprintf('upload %s skipped', $id),
                default => sprintf('upload %s %s sent', $id, is_int($sent) ? (string) $sent : '?'),
            };
        }
        return sprintf('%s in %d ms', $parts === [] ? 'no archives' : implode(', ', $parts), $outcome->durationMs);
    }
}
