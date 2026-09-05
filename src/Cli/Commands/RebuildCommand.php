<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Tick\Lock;

/** A span of the archive built again from the journal, daily summaries included. */
final class RebuildCommand implements Command
{
    public function name(): string
    {
        return 'rebuild';
    }

    public function usage(): string
    {
        return 'rebuild <archive> <from> <to>';
    }

    public function summary(): string
    {
        return 'work a span out again from the journal; times as timestamps or "2026-09-05 14:00"';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $archive = isset($args[0]) ? $runtime->config->archive($args[0]) : null;
        if ($archive === null || !isset($args[1], $args[2])) {
            $console->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $from = self::moment($args[1], $archive->timezone);
        $to = self::moment($args[2], $archive->timezone);
        if ($from === null || $to === null || $to <= $from) {
            $console->error('from and to are timestamps or local times like "2026-09-05 14:00", from before to');
            return Application::USAGE_ERROR;
        }
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($runtime->config->settings->lockPath());
        if ($lock === null) {
            $console->error('busy');
            return 1;
        }
        $archiver = null;
        try {
            $runtime->refresh();
            $archive = $runtime->config->archive($archive->id) ?? throw new \RuntimeException('Archive no longer exists');
            $archiver = Archiver::open($archive, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, $runtime->clock->now(), $runtime->archiveChanges($archive));
            $written = $archiver->rebuild($from, $to);
            $console->line(sprintf('%s: %d record(s) rebuilt', $archive->id, $written));
        } finally {
            $archiver?->close();
            $lock->release();
        }
        return 0;
    }

    private static function moment(string $text, DateTimeZone $zone): ?int
    {
        if (preg_match('/^\d{9,}$/', $text) === 1) {
            return (int) $text;
        }
        try {
            return (new DateTimeImmutable($text, $zone))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }
}
