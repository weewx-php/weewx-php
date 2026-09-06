<?php

declare(strict_types=1);

namespace WeewxPhp\State;

use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;

/**
 * The application's own state: what it knows about its archives and
 * stations, and what its last runs did. Its own file, so that the archive
 * databases hold nothing but what WeeWX put in them.
 */
final class StateDb
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS execution_profile (name TEXT PRIMARY KEY, payload TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS archives (
            id             TEXT NOT NULL PRIMARY KEY,
            created_by_app INTEGER NOT NULL DEFAULT 0,
            db_created_at  INTEGER,
            caught_up_at   INTEGER,
            last_run_at    INTEGER,
            last_record_at INTEGER,
            records_total  INTEGER NOT NULL DEFAULT 0,
            last_error     TEXT,
            last_error_at  INTEGER
        );
        CREATE TABLE IF NOT EXISTS stations (
            id           TEXT NOT NULL PRIMARY KEY,
            last_seen    INTEGER,
            status       TEXT NOT NULL DEFAULT 'unknown',
            status_since INTEGER
        );
        CREATE TABLE IF NOT EXISTS runs (
            id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            started_at  INTEGER NOT NULL,
            finished_at INTEGER NOT NULL,
            trigger     TEXT NOT NULL,
            summary     TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS uploads (
            id           TEXT NOT NULL PRIMARY KEY,
            through      INTEGER NOT NULL DEFAULT 0,
            last_run_at  INTEGER,
            last_sent_at INTEGER,
            runs         INTEGER NOT NULL DEFAULT 0,
            sent         INTEGER NOT NULL DEFAULT 0,
            failures     INTEGER NOT NULL DEFAULT 0,
            blocked      TEXT,
            blocked_at   INTEGER,
            last_summary TEXT,
            announced_at INTEGER
        );
        SQL;

    /** How many runs are kept. */
    private const RUNS_KEPT = 500;

    private function __construct(private readonly Sqlite $db) {}

    public static function open(string $path, JournalMode $journalMode): self
    {
        $db = Sqlite::open($path, true, $journalMode, 'NORMAL');
        $db->exec(self::SCHEMA);
        return new self($db);
    }

    public function close(): void
    {
        $this->db->close();
    }

    // -- archives ---------------------------------------------------------

    public function archive(string $id): ArchiveState
    {
        $row = $this->db->one('SELECT * FROM archives WHERE id = ?', [$id]);
        if ($row === null) {
            return new ArchiveState($id, false, null, null, null, null, 0, null, null);
        }
        return new ArchiveState(
            $id,
            (bool) self::int($row['created_by_app']),
            self::optionalInt($row['db_created_at']),
            self::optionalInt($row['caught_up_at']),
            self::optionalInt($row['last_run_at']),
            self::optionalInt($row['last_record_at']),
            self::int($row['records_total']),
            $row['last_error'] === null ? null : Sqlite::text($row['last_error']),
            self::optionalInt($row['last_error_at']),
        );
    }

    /** Remember that this application made the archive's database file. */
    public function markCreated(string $id, int $at): void
    {
        $this->ensureArchive($id);
        $this->db->exec('UPDATE archives SET created_by_app = 1, db_created_at = ? WHERE id = ?', [$at, $id]);
    }

    public function noteCaughtUp(string $id, int $at): void
    {
        $this->ensureArchive($id);
        $this->db->exec('UPDATE archives SET caught_up_at = ? WHERE id = ?', [$at, $id]);
    }

    /** Record a run's outcome: when, how many records it wrote, the newest one. */
    public function noteRun(string $id, int $at, int $recordsWritten, ?int $lastRecordAt): void
    {
        $this->ensureArchive($id);
        $this->db->exec(
            'UPDATE archives SET last_run_at = ?, records_total = records_total + ?,'
            . ' last_record_at = COALESCE(?, last_record_at), last_error = NULL, last_error_at = NULL WHERE id = ?',
            [$at, $recordsWritten, $lastRecordAt, $id],
        );
    }

    public function noteError(string $id, int $at, string $message): void
    {
        $this->ensureArchive($id);
        $this->db->exec('UPDATE archives SET last_error = ?, last_error_at = ? WHERE id = ?', [$message, $at, $id]);
    }

    // -- stations ---------------------------------------------------------

    public function station(string $id): StationState
    {
        $row = $this->db->one('SELECT * FROM stations WHERE id = ?', [$id]);
        if ($row === null) {
            return new StationState($id, null, StationStatus::Unknown, null);
        }
        return new StationState(
            $id,
            self::optionalInt($row['last_seen']),
            StationStatus::tryFrom(Sqlite::text($row['status'])) ?? StationStatus::Unknown,
            self::optionalInt($row['status_since']),
        );
    }

    /**
     * Write a station's state. `statusSince` is kept when the status did
     * not change, so it says how long the station has been that way.
     */
    public function setStation(string $id, ?int $lastSeen, StationStatus $status, int $now): StationState
    {
        $before = $this->station($id);
        $since = $before->status === $status && $before->statusSince !== null ? $before->statusSince : $now;
        $this->db->exec(
            'INSERT INTO stations (id, last_seen, status, status_since) VALUES (?, ?, ?, ?)'
            . ' ON CONFLICT(id) DO UPDATE SET last_seen = excluded.last_seen, status = excluded.status, status_since = excluded.status_since',
            [$id, $lastSeen, $status->value, $since],
        );
        return new StationState($id, $lastSeen, $status, $since);
    }

    // -- uploads ----------------------------------------------------------

    public function upload(string $id): UploadState
    {
        $row = $this->db->one('SELECT * FROM uploads WHERE id = ?', [$id]);
        if ($row === null) {
            return new UploadState($id, 0, null, null, 0, 0, 0, null, null, null, null);
        }
        return new UploadState(
            $id,
            self::int($row['through']),
            self::optionalInt($row['last_run_at']),
            self::optionalInt($row['last_sent_at']),
            self::int($row['runs']),
            self::int($row['sent']),
            self::int($row['failures']),
            $row['blocked'] === null ? null : Sqlite::text($row['blocked']),
            self::optionalInt($row['blocked_at']),
            $row['last_summary'] === null ? null : Sqlite::text($row['last_summary']),
            self::optionalInt($row['announced_at']),
        );
    }

    /**
     * A run that got through: the service took what it was sent, or there
     * was nothing to send. Clears a failure count and a block.
     *
     * @param int|null $through The newest record accepted; never moves the mark backwards.
     */
    public function noteUploadRun(string $id, int $at, int $sent, ?int $through, string $summary): void
    {
        $this->ensureUpload($id);
        $this->db->exec(
            'UPDATE uploads SET last_run_at = ?, last_sent_at = CASE WHEN ? > 0 THEN ? ELSE last_sent_at END,'
            . ' runs = runs + 1, sent = sent + ?, failures = 0, blocked = NULL, blocked_at = NULL, last_summary = ?,'
            . ' through = MAX(through, ?) WHERE id = ?',
            [$at, $sent, $at, $sent, $summary, $through ?? 0, $id],
        );
    }

    /**
     * What a run got accepted before it failed: the mark moves, the sent
     * count grows, and the failure is noted apart.
     */
    public function advanceUpload(string $id, int $at, int $sent, int $through): void
    {
        $this->ensureUpload($id);
        $this->db->exec(
            'UPDATE uploads SET sent = sent + ?, last_sent_at = ?, through = MAX(through, ?) WHERE id = ?',
            [$sent, $at, $through, $id],
        );
    }

    /** A run that did not get through, for a reason worth trying again after. */
    public function noteUploadFailure(string $id, int $at, string $summary): void
    {
        $this->ensureUpload($id);
        $this->db->exec(
            'UPDATE uploads SET last_run_at = ?, runs = runs + 1, failures = failures + 1, last_summary = ? WHERE id = ?',
            [$at, $summary, $id],
        );
    }

    /** The service said no for good; nothing is sent until the block is lifted. */
    public function blockUpload(string $id, int $at, string $reason): void
    {
        $this->ensureUpload($id);
        $this->db->exec(
            'UPDATE uploads SET last_run_at = ?, runs = runs + 1, failures = failures + 1, blocked = ?, blocked_at = ?, last_summary = ? WHERE id = ?',
            [$at, $reason, $at, $reason, $id],
        );
    }

    public function unblockUpload(string $id): void
    {
        $this->db->exec('UPDATE uploads SET blocked = NULL, blocked_at = NULL WHERE id = ?', [$id]);
    }

    /**
     * Send everything after a moment again. The one caller that moves the
     * mark backwards, for a rebuilt span or a service that lost its copy.
     */
    public function rewindUpload(string $id, int $through): void
    {
        $this->ensureUpload($id);
        $this->db->exec('UPDATE uploads SET through = ? WHERE id = ?', [$through, $id]);
    }

    public function noteAnnounced(string $id, int $at): void
    {
        $this->ensureUpload($id);
        $this->db->exec('UPDATE uploads SET announced_at = ? WHERE id = ?', [$at, $id]);
    }

    private function ensureUpload(string $id): void
    {
        $this->db->exec('INSERT OR IGNORE INTO uploads (id) VALUES (?)', [$id]);
    }

    // -- runs -------------------------------------------------------------

    /**
     * Keep what a run did, and let the oldest go once there are more than
     * five hundred.
     *
     * @param array<string, mixed> $summary
     */
    public function addRun(int $startedAt, int $finishedAt, string $trigger, array $summary): void
    {
        $this->db->transaction(function () use ($startedAt, $finishedAt, $trigger, $summary): void {
            $this->db->exec(
                'INSERT INTO runs (started_at, finished_at, trigger, summary) VALUES (?, ?, ?, ?)',
                [$startedAt, $finishedAt, $trigger, json_encode($summary, JSON_THROW_ON_ERROR)],
            );
            $this->db->exec(
                'DELETE FROM runs WHERE id NOT IN (SELECT id FROM runs ORDER BY id DESC LIMIT ?)',
                [self::RUNS_KEPT],
            );
        });
    }

    /**
     * The latest runs, newest first.
     *
     * @return list<array{started_at: int, finished_at: int, trigger: string, summary: array<string, mixed>}>
     */
    public function runs(int $limit = 20): array
    {
        $runs = [];
        foreach ($this->db->query('SELECT started_at, finished_at, trigger, summary FROM runs ORDER BY started_at DESC, id DESC LIMIT ?', [$limit]) as $row) {
            $runs[] = [
                'started_at' => self::int($row['started_at']),
                'finished_at' => self::int($row['finished_at']),
                'trigger' => Sqlite::text($row['trigger']),
                'summary' => Json::object(Sqlite::text($row['summary'])),
            ];
        }
        return $runs;
    }

    // -- helpers ----------------------------------------------------------

    /** @return array<string, mixed> */
    public function executionProfile(string $name): array
    {
        $value = $this->db->scalar('SELECT payload FROM execution_profile WHERE name = ?', [$name]);
        return is_string($value) ? Json::object($value) : [];
    }

    /** @param array<string, mixed> $profile */
    public function saveExecutionProfile(string $name, array $profile): void
    {
        $this->db->exec('INSERT INTO execution_profile(name, payload) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET payload = excluded.payload', [$name, json_encode($profile, JSON_THROW_ON_ERROR)]);
    }

    private function ensureArchive(string $id): void
    {
        $this->db->exec('INSERT OR IGNORE INTO archives (id) VALUES (?)', [$id]);
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (int) Sqlite::text($value);
    }

    private static function optionalInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }
}
