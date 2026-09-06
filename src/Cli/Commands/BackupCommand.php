<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Weewx\Policy;

/**
 * A copy of an archive that is a database: SQLite's own backup, which
 * reads through the write-ahead log. A `cp` of a database in WAL mode
 * copies a file whose latest records are in the log beside it.
 */
final class BackupCommand implements Command
{
    public function name(): string
    {
        return 'backup';
    }

    public function usage(): string
    {
        return 'backup [<archive> <target file>]';
    }

    public function summary(): string
    {
        return 'back up the installation or copy one archive safely';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        if ($args === []) {
            $runtime->ensureDataDir();
            $lock = \WeewxPhp\Tick\Lock::tryAcquire($runtime->config->settings->lockPath());
            if ($lock === null) {
                $console->error('Another writer is running');
                return 1;
            }
            try {
                $runtime->refresh();
                $runtime->live();
                $runtime->state();
                $backups = new \WeewxPhp\Backup\Backups($runtime->config->settings);
                $result = $backups->run($runtime, true);
                if ($result['status'] !== 'complete') {
                    $console->error('Backup failed; check source files, free space and the PHP Phar extension');
                    return 1;
                }
                $console->line($backups->directory() . '/' . $result['filename']);
                return 0;
            } finally {
                $lock->release();
            }
        }
        $archive = isset($args[0]) ? $runtime->config->archive($args[0]) : null;
        $target = $args[1] ?? null;
        if ($archive === null || $target === null) {
            $console->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        if (file_exists($target)) {
            $console->error(sprintf('%s exists already; a backup does not overwrite', $target));
            return 1;
        }
        $db = ArchiveDb::open($archive->database, $runtime->config->settings->journalMode, new Policy(), $archive->timezone);
        try {
            $db->backup($target);
        } finally {
            $db->close();
        }
        $size = filesize($target);
        $console->line(sprintf('%s: copied to %s (%s bytes)', $archive->id, $target, $size === false ? '?' : number_format($size)));
        return 0;
    }
}
