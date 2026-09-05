<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Tick\Lock;

/** The whole journal, walked to the end, whatever the time budget. */
final class CatchupCommand implements Command
{
    public function name(): string
    {
        return 'catchup';
    }

    public function usage(): string
    {
        return 'catchup [<archive>]';
    }

    public function summary(): string
    {
        return 'build every interval the journal covers that is not archived';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $archives = $runtime->config->archives;
        if (isset($args[0])) {
            $one = $runtime->config->archive($args[0]);
            if ($one === null) {
                $console->error('no archive ' . $args[0]);
                return Application::USAGE_ERROR;
            }
            $archives = [$args[0] => $one];
        }
        $now = $runtime->clock->now();
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($runtime->config->settings->lockPath());
        if ($lock === null) {
            $console->error('busy');
            return 1;
        }
        try {
            $runtime->refresh();
            $archives = isset($args[0]) ? array_intersect_key($runtime->config->archives, [$args[0] => true]) : $runtime->config->archives;
            foreach ($archives as $id => $archive) {
                if (!$archive->enabled) {
                    continue;
                }
                $archiver = Archiver::open($archive, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, $now, $runtime->archiveChanges($archive));
                try {
                    $progress = $archiver->catchUp(null, null, Budget::unlimited($runtime->clock));
                    $runtime->state()->noteCaughtUp($id, $now);
                    $runtime->state()->noteRun($id, $now, $progress->built, $archiver->archive()->lastTimestamp());
                    $console->line(sprintf('%s: %d record(s) written', $id, $progress->built));
                } finally {
                    $archiver->close();
                }
            }
            return 0;
        } finally {
            $lock->release();
        }
    }
}
