<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use DateTimeZone;
use Throwable;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Cli\Console;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\Settings;
use WeewxPhp\Tick\StationWatch;
use WeewxPhp\Weewx\Policy;

/** The installation at a glance: every archive, every station. */
final class StatusCommand implements Command
{
    public function name(): string
    {
        return 'status';
    }

    public function usage(): string
    {
        return 'status';
    }

    public function summary(): string
    {
        return 'what each archive and station is up to';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $config = $runtime->config;
        $now = $runtime->clock->now();
        $live = $runtime->live();
        $state = $runtime->state();

        $console->line(sprintf('weewx-php status at %s %s', Console::when($now, $config->settings->timezone), $config->settings->timezone->getName()));
        $console->line(sprintf('journal: %d packet(s), %s', $live->count(), self::span($live->span(), $config->settings)));
        $backup = (new \WeewxPhp\Backup\Backups($config->settings))->status();
        $console->line(sprintf('backup: %s, last success %s', $backup['status'], Console::when($backup['completed'] > 0 ? $backup['completed'] : null, $config->settings->timezone)));
        foreach (\WeewxPhp\Backup\Health::warnings($config, $now) as $warning) {
            $console->line('warning: ' . (new \WeewxPhp\Admin\Translator())->text($warning));
        }
        $console->line();
        $console->line('Archives');
        foreach ($config->archives as $id => $archive) {
            $known = $state->archive($id);
            $zone = $archive->timezone;
            $console->line(sprintf('  %s  %s', $id, $archive->name));
            $console->line(sprintf('    database   %s  %s', $archive->database, self::describeDatabase($archive->database, $runtime->config->settings->journalMode, $archive->timezone)));
            $console->line(sprintf(
                '    last run   %s  wrote %s, %d in total',
                Console::when($known->lastRunAt, $zone),
                $known->lastRecordAt === null ? 'nothing yet' : 'up to ' . Console::when($known->lastRecordAt, $zone),
                $known->recordsTotal,
            ));
            $console->line(sprintf('    caught up  %s', Console::when($known->caughtUpAt, $zone)));
            $console->line(sprintf('    pending    %d interval(s)', count($live->due($now, 0, $id))));
            $console->line(sprintf(
                '    error      %s',
                $known->lastError === null ? '-' : sprintf('%s (%s)', $known->lastError, Console::when($known->lastErrorAt, $zone)),
            ));
        }
        $console->line();
        $console->line('Stations');
        $heard = $live->lastSeen();
        foreach ($config->stations as $id => $station) {
            $lastSeen = $heard[$id] ?? null;
            $console->line(sprintf(
                '  %-16s %-8s %s',
                $id,
                StationWatch::judge($station, $lastSeen, $now)->value,
                $lastSeen === null ? 'never heard' : sprintf('last heard %s (%d s ago)', Console::when($lastSeen, $config->settings->timezone), $now - $lastSeen),
            ));
        }
        foreach ($heard as $id => $lastSeen) {
            if (!isset($config->stations[$id])) {
                $console->line(sprintf('  %-16s %-8s last heard %s, not in [Stations]', $id, '?', Console::when($lastSeen, $config->settings->timezone)));
            }
        }
        return 0;
    }

    private static function describeDatabase(string $path, JournalMode $journalMode, DateTimeZone $zone): string
    {
        if (!is_file($path)) {
            return '(not there yet; the first tick creates it)';
        }
        try {
            $archive = ArchiveDb::open($path, $journalMode, new Policy(), $zone);
        } catch (Throwable $error) {
            return sprintf('(cannot open: %s)', $error->getMessage());
        }
        try {
            $system = $archive->unitSystem();
            return sprintf(
                '(%d record(s), %s .. %s, %s)',
                $archive->count(),
                Console::when($archive->firstTimestamp(), $zone),
                Console::when($archive->lastTimestamp(), $zone),
                $system === null ? 'no unit system yet' : $system->name,
            );
        } finally {
            $archive->close();
        }
    }

    /** @param array{0: int|null, 1: int|null} $span */
    private static function span(array $span, Settings $settings): string
    {
        [$first, $last] = $span;
        if ($first === null) {
            return 'empty';
        }
        return sprintf('%s .. %s', Console::when($first, $settings->timezone), Console::when($last, $settings->timezone));
    }
}
