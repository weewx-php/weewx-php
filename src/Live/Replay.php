<?php

declare(strict_types=1);

namespace WeewxPhp\Live;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Weewx\Intervals;

/** Durable repair cursors. New packets behind a cursor rewind its whole day. */
final class Replay
{
    public const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS weewx_replay (
            archive TEXT PRIMARY KEY, cursor INTEGER NOT NULL, stop INTEGER NOT NULL,
            seconds INTEGER NOT NULL, generation INTEGER NOT NULL DEFAULT 1, stats TEXT
        );
        CREATE TABLE IF NOT EXISTS weewx_hold (
            archive TEXT NOT NULL, stop INTEGER NOT NULL, PRIMARY KEY (archive, stop)
        );
        SQL;

    public function __construct(private readonly Sqlite $db) {}

    public function mark(ArchiveConfig $archive, int $timestamp, int $now, int $seconds): void
    {
        $this->db->transaction(function () use ($archive, $timestamp, $now, $seconds): void {
            $start = Intervals::startOfArchiveDay($timestamp, $archive->timezone);
            $stop = Intervals::stop($now, $seconds);
            $row = $this->job($archive->id);
            if ($row === null) {
                $this->db->exec('INSERT INTO weewx_replay (archive, cursor, stop, seconds) VALUES (?, ?, ?, ?)', [$archive->id, $start, $stop, $seconds]);
            } elseif ($timestamp <= $row['cursor'] || $seconds !== $row['seconds']) {
                $this->db->exec(
                    'UPDATE weewx_replay SET cursor = MIN(cursor, ?), stop = MAX(stop, ?), seconds = ?, generation = generation + 1, stats = NULL WHERE archive = ?',
                    [min($start, Intervals::startOfArchiveDay($row['cursor'] + 1, $archive->timezone)), $stop, $seconds, $archive->id],
                );
            } else {
                $this->db->exec('UPDATE weewx_replay SET stop = MAX(stop, ?), generation = generation + 1 WHERE archive = ?', [$stop, $archive->id]);
            }
        });
    }

    /** @return array{cursor: int, stop: int, seconds: int, generation: int, stats: ?string}|null */
    public function job(string $archive): ?array
    {
        $row = $this->db->one('SELECT cursor, stop, seconds, generation, stats FROM weewx_replay WHERE archive = ?', [$archive]);
        return $row === null ? null : [
            'cursor' => (int) Sqlite::text($row['cursor']), 'stop' => (int) Sqlite::text($row['stop']),
            'seconds' => (int) Sqlite::text($row['seconds']), 'generation' => (int) Sqlite::text($row['generation']),
            'stats' => $row['stats'] === null ? null : Sqlite::text($row['stats']),
        ];
    }

    /** A receiver may rewind this job while an archive write is in progress. */
    public function advance(string $archive, int $generation, int $before, int $cursor, ?string $stats): bool
    {
        return $this->db->transaction(function () use ($archive, $generation, $before, $cursor, $stats): bool {
            $changed = $this->db->exec(
                'UPDATE weewx_replay SET cursor = ?, stats = ? WHERE archive = ? AND generation = ? AND cursor = ?',
                [$cursor, $stats, $archive, $generation, $before],
            );
            if ($changed > 0) {
                $this->db->exec('DELETE FROM weewx_replay WHERE archive = ? AND cursor >= stop', [$archive]);
            }
            return $changed > 0;
        });
    }

    public function pending(): bool
    {
        return $this->db->one('SELECT 1 FROM weewx_replay LIMIT 1') !== null;
    }

    public function hold(string $archive, int $stop): void
    {
        $this->db->exec('INSERT OR IGNORE INTO weewx_hold VALUES (?, ?)', [$archive, $stop]);
    }

    public function release(string $archive, int $stop): void
    {
        $this->db->exec('DELETE FROM weewx_hold WHERE archive = ? AND stop = ?', [$archive, $stop]);
    }

    public function protected(): bool
    {
        return $this->pending() || $this->db->one('SELECT 1 FROM weewx_hold LIMIT 1') !== null;
    }

    /** Preserve each unfinished day plus calculation run-up, without pinning unrelated old history. */
    public function retentionCutoff(int $before): int
    {
        $oldest = $this->db->scalar('SELECT MIN(point) FROM (SELECT cursor AS point FROM weewx_replay UNION ALL SELECT stop AS point FROM weewx_hold)');
        return $oldest === null ? $before : min($before, (int) Sqlite::text($oldest) - 2 * 86400);
    }

    /** Removed archives cannot keep retention pinned forever; disabled archives retain their work.
     * @param list<string> $archives
     */
    public function retain(array $archives): void
    {
        foreach ($this->db->query('SELECT archive FROM weewx_replay UNION SELECT archive FROM weewx_hold') as $row) {
            $id = Sqlite::text($row['archive']);
            if (!in_array($id, $archives, true)) {
                $this->db->exec('DELETE FROM weewx_replay WHERE archive = ?', [$id]);
                $this->db->exec('DELETE FROM weewx_hold WHERE archive = ?', [$id]);
            }
        }
    }
}
