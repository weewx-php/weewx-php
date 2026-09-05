<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use DateTimeImmutable;
use Throwable;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Weewx\Intervals;

/** Persistent maintenance work; rebuilding/verification advances one day per step. */
final class Jobs
{
    public function __construct(private readonly string $path, private readonly int $now) {}

    private static function store(Settings $settings): Sqlite
    {
        $db = Changes::store($settings);
        $db->exec("CREATE TABLE IF NOT EXISTS admin_job(id TEXT PRIMARY KEY, archive TEXT NOT NULL, kind TEXT NOT NULL, created INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'queued', start INTEGER NOT NULL, stop INTEGER NOT NULL, cursor INTEGER NOT NULL, result TEXT NOT NULL DEFAULT '')");
        return $db;
    }

    /** @param array<string, mixed> $input */
    public function queue(array $input): void
    {
        $read = new ReadModel($this->path);
        $archive = $read->config->archive(Input::text($input, 'archive')) ?? throw new Problem('error.archive');
        $info = ReadModel::archive($archive);
        $kind = Input::text($input, 'kind');
        $id = Input::text($input, 'job_key');
        if (!in_array($kind, ['backup', 'verify', 'rebuild'], true) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new Problem('error.input');
        }
        $start = $info['first'] ?? $this->now;
        $stop = $info['last'] ?? $this->now;
        if ($kind === 'rebuild') {
            $startText = Input::text($input, 'from');
            $stopText = Input::text($input, 'to');
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $startText) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $stopText) !== 1) {
                throw new Problem('error.input');
            }
            $startDate = new DateTimeImmutable($startText, $archive->timezone);
            $stopDate = new DateTimeImmutable($stopText, $archive->timezone);
            if ($startDate->format('Y-m-d\TH:i') !== $startText || $stopDate->format('Y-m-d\TH:i') !== $stopText) {
                throw new Problem('error.input');
            }
            $start = $startDate->getTimestamp();
            $stop = $stopDate->getTimestamp();
            if ($stop <= $start || $stop > $this->now || $start < $this->now - $read->config->settings->liveRetention) {
                throw new Problem('error.rebuild_range');
            }
        }
        $db = self::store($read->config->settings);
        try {
            $count = (int) Sqlite::text($db->scalar("SELECT COUNT(*) FROM admin_job WHERE status IN ('queued', 'running')"));
            if ($count >= 20) {
                throw new Problem('error.busy');
            }
            $cursor = $kind === 'rebuild' ? $start : Intervals::startOfArchiveDay($start, $archive->timezone);
            $db->exec('INSERT OR IGNORE INTO admin_job(id, archive, kind, created, start, stop, cursor) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $archive->id, $kind, $this->now, $start, $stop, $cursor]);
            $db->exec('INSERT INTO admin_audit(created, action, subject) VALUES (?, ?, ?)', [$this->now, 'maintenance.' . $kind, $archive->id]);
        } finally {
            $db->close();
        }
    }

    /** Called with the tick's writer lock held. */
    public static function run(Runtime $runtime, Budget $budget): void
    {
        $db = self::store($runtime->config->settings);
        try {
            $job = $db->one("SELECT * FROM admin_job WHERE status IN ('queued', 'running') ORDER BY created LIMIT 1");
            if ($job === null || !$budget->allows()) {
                return;
            }
            $id = Sqlite::text($job['id']);
            $archive = $runtime->config->archive(Sqlite::text($job['archive']));
            $weather = null;
            $archiver = null;
            try {
                if ($archive === null) {
                    throw new Problem('error.archive');
                }
                $db->exec("UPDATE admin_job SET status = 'running' WHERE id = ?", [$id]);
                $weather = ArchiveDb::open($archive->database, $runtime->config->settings->journalMode, $archive->policy(), $archive->timezone);
                $kind = Sqlite::text($job['kind']);
                $cursor = (int) Sqlite::text($job['cursor']);
                $stop = (int) Sqlite::text($job['stop']);
                $result = '';
                if ($kind === 'backup') {
                    $directory = $runtime->config->settings->dataDir . '/backups';
                    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                        throw new Problem('error.path');
                    }
                    $target = $directory . '/' . $archive->id . '-' . $id . '.sdb';
                    // SQLite's online backup API is atomic but not incrementally exposed by PHP.
                    $weather->backup($target);
                    $result = 'backups/' . basename($target);
                    $cursor = $stop + 1;
                } elseif ($kind === 'rebuild') {
                    $next = (new DateTimeImmutable('@' . $cursor))->setTimezone($archive->timezone)->modify('tomorrow')->setTime(0, 0)->getTimestamp();
                    $end = min($next, $stop);
                    $archiver = Archiver::open($archive, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, $runtime->clock->now(), $runtime->archiveChanges($archive));
                    $archiver->rebuild($cursor, $end);
                    $cursor = $end === $stop ? $stop + 1 : $end;
                } else {
                    [$fresh] = $weather->dayFromRecords($cursor);
                    $stored = $weather->loadDay($cursor, $weather->unitSystem());
                    foreach ($fresh->types() as $name) {
                        if (!isset($weather->schema()->dayTypes[$name])) {
                            continue;
                        }
                        if (!$stored->has($name)) {
                            throw new Problem('error.summary');
                        }
                        $expected = $fresh->get($name)->statsTuple();
                        $actual = $stored->get($name)->statsTuple();
                        // LOOP extrema can legitimately be sharper than archive records.
                        // Compare the archive-derived sums, weights and vector components.
                        foreach (array_keys($expected) as $index) {
                            if ($index < 4 || $index === 8) {
                                continue;
                            }
                            $a = $actual[$index];
                            $b = $expected[$index];
                            if ($a === null || $b === null) {
                                if ($a !== $b) {
                                    throw new Problem('error.summary');
                                }
                            } elseif (abs($a - $b) > 1e-9 * max(1.0, abs($a), abs($b))) {
                                throw new Problem('error.summary');
                            }
                        }
                    }
                    $cursor = (new DateTimeImmutable('@' . $cursor))->setTimezone($archive->timezone)->modify('+1 day')->getTimestamp();
                }
                $db->exec('UPDATE admin_job SET cursor = ?, status = ?, result = ? WHERE id = ?', [$cursor, $cursor > $stop ? 'complete' : 'running', $result, $id]);
            } catch (Throwable $error) {
                $db->exec("UPDATE admin_job SET status = 'failed', result = ? WHERE id = ?", [$error instanceof Problem ? $error->getMessage() : 'error.maintenance', $id]);
                $runtime->log->error('maintenance failed: ' . $id . ' (' . $error::class . ')');
            } finally {
                $archiver?->close();
                $weather?->close();
            }
        } finally {
            $db->close();
        }
    }
}
