<?php

declare(strict_types=1);

namespace WeewxPhp\Log;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WeewxPhp\Time\Clock;

/**
 * Appends to one log file and starts a new one each local day.
 *
 * Every line is written with its own open-append-close, under a lock, so
 * the tick and a future ingest can share the file without either holding it.
 * Yesterday's file is renamed after its date the first time a line is
 * written today, and files older than the retention are removed then.
 */
final class FileLogger implements Logger
{
    use LogLevelMethods;

    /**
     * @param string $path Where the current file lives, e.g. 'data/log/weewx-php.log'.
     *     Rotated files sit beside it as 'weewx-php.2026-09-04.log'.
     * @param LogLevel $threshold The lowest level that is written.
     * @param DateTimeZone $zone The zone the timestamps and the day boundaries are in.
     * @param int $keepDays How many rotated files to keep. Default is 14.
     */
    public function __construct(
        private readonly string $path,
        private readonly LogLevel $threshold,
        private readonly DateTimeZone $zone,
        private readonly Clock $clock,
        private readonly int $keepDays = 14,
    ) {}

    public function log(LogLevel $level, string $message): void
    {
        if ($level->value < $this->threshold->value) {
            return;
        }
        $now = $this->clock->now();
        $this->ensureDirectory();
        $this->rotate($now);
        $line = sprintf("%s %s %s\n", $this->localTime($now)->format('Y-m-d H:i:s'), $level->label(), $message);
        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Cannot write to log file %s', $this->path));
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        // Two processes may create it at once; whoever loses the race finds
        // it there and carries on.
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create log directory %s', $dir));
        }
    }

    private function rotate(int $now): void
    {
        // The day a file belongs to is read off its first line rather than
        // its modification time: every line starts with its local date, and
        // that date came from the same clock this method is given, so a
        // test can move the clock and the file follows.
        $fileDay = $this->firstDay();
        if ($fileDay === null || $fileDay === $this->localTime($now)->format('Y-m-d')) {
            return;
        }
        // The rename is atomic. When two processes rotate at once, the second
        // one finds the file gone, which is the outcome it wanted anyway, so
        // its failure is not reported.
        if (!is_file($this->path) || !rename($this->path, $this->rotatedName($fileDay))) {
            return;
        }
        $this->prune($now);
    }

    private function prune(int $now): void
    {
        $oldest = $this->localTime($now)->modify(sprintf('-%d days', $this->keepDays))->format('Y-m-d');
        $files = glob($this->rotatedName('????-??-??'));
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $day = substr(basename($file), strlen($this->stem()) + 1, 10);
            if ($day < $oldest) {
                unlink($file);
            }
        }
    }

    /** The date on the first line of the current file, or null without one. */
    private function firstDay(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = fread($handle, 10);
        fclose($handle);
        if ($head === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $head) !== 1) {
            return null;
        }
        return $head;
    }

    private function rotatedName(string $day): string
    {
        return dirname($this->path) . '/' . $this->stem() . '.' . $day . '.log';
    }

    /** 'weewx-php' for 'data/log/weewx-php.log'. */
    private function stem(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    private function localTime(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone);
    }
}
