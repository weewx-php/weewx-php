<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Cli\Console;
use WeewxPhp\Weewx\Policy;

/**
 * The archive's columns against the configuration: which are there,
 * which hold anything, which `[[[columns]]]` still has to add. With
 * `--add`, added now rather than by the next tick.
 */
final class ColumnsCommand implements Command
{
    public function name(): string
    {
        return 'columns';
    }

    public function usage(): string
    {
        return 'columns [--add] <archive>';
    }

    public function summary(): string
    {
        return 'list the archive\'s columns and what [[[columns]]] would add';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $add = in_array('--add', $args, true);
        $args = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--add'));
        $archive = isset($args[0]) ? $runtime->config->archive($args[0]) : null;
        if ($archive === null) {
            $console->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        if ($add) {
            $runtime->ensureDataDir();
            $lock = \WeewxPhp\Tick\Lock::tryAcquire($runtime->config->settings->lockPath());
            if ($lock === null) {
                $console->error('busy');
                return 1;
            }
            try {
                $runtime->refresh();
                $archive = $runtime->config->archive($archive->id) ?? throw new \RuntimeException('Archive no longer exists');
                $archiver = Archiver::open($archive, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, $runtime->clock->now());
                $archiver->close();
            } finally {
                $lock->release();
            }
        }
        if (!is_file($archive->database)) {
            $console->line(sprintf('%s: %s does not exist yet', $archive->id, $archive->database));
            return 0;
        }
        $db = ArchiveDb::open($archive->database, $runtime->config->settings->journalMode, new Policy(), $archive->timezone);
        try {
            $schema = $db->schema();
            $occupied = $db->occupied();
            $console->line(sprintf('%s: %d column(s), %d with data, daily summaries for %d', $archive->id, count($schema->columns), count($occupied), count($schema->dayTypes)));
            foreach ($schema->columns as $column) {
                $held = $occupied[$column] ?? null;
                $console->line(sprintf(
                    '  %-24s %-8s %s',
                    $column,
                    $schema->columnTypes[$column] ?? '',
                    $held === null ? '' : sprintf('%d record(s), last %s', $held[0], Console::when($held[1], $archive->timezone)),
                ));
            }
            $missing = array_diff_key($archive->columns, array_flip($schema->columns));
            foreach ($missing as $name => $type) {
                $console->line(sprintf('  %-24s %-8s to be added from [[[columns]]]', $name, $type->value));
            }
        } finally {
            $db->close();
        }
        return 0;
    }
}
