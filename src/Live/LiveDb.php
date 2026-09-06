<?php

declare(strict_types=1);

namespace WeewxPhp\Live;

use Generator;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\CollectorStore;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\UnitSystem;

/**
 * The live table: every reading that ever arrived, kept for a while.
 *
 * This is the store that replaces WeeWX's in-memory accumulator, with the
 * schema of weewx-evo's `db/live.py`. A packet is written here the moment
 * it arrives and nothing else happens to it; archive records are worked out
 * from this table afterwards, which is what makes them reproducible: the
 * same packets always yield the same record, whether aggregated now, after
 * a restart, or a week later.
 *
 * It is a sensor journal, not a queue: nothing has been placed into an
 * archive column, and nothing has been left out.
 */
final class LiveDb
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS packet (
            seq       INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
            dateTime  INTEGER NOT NULL,
            received  INTEGER NOT NULL,
            driver    TEXT    NOT NULL,
            identity  TEXT    NOT NULL,
            sender    TEXT    NOT NULL,
            dialect   TEXT,
            mapping   TEXT,
            kind      TEXT    NOT NULL,
            usUnits   INTEGER NOT NULL,
            interval  REAL,
            digest    TEXT    NOT NULL,
            data      TEXT    NOT NULL,
            raw       TEXT
        );
        CREATE UNIQUE INDEX IF NOT EXISTS packet_identity ON packet(driver, identity, kind, dateTime, digest);
        CREATE INDEX IF NOT EXISTS packet_dateTime ON packet(dateTime);
        CREATE INDEX IF NOT EXISTS packet_station ON packet(driver, identity, dateTime);
        CREATE INDEX IF NOT EXISTS packet_sender ON packet(sender, dateTime);
        CREATE TABLE IF NOT EXISTS dialect_mapping (
            digest TEXT NOT NULL PRIMARY KEY,
            spec   TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sender_identity (
            sender     TEXT NOT NULL PRIMARY KEY,
            driver     TEXT NOT NULL,
            identity   TEXT NOT NULL,
            label      TEXT,
            first_seen INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS sender_pair ON sender_identity(driver, identity COLLATE NOCASE);
        CREATE TABLE IF NOT EXISTS pending (
            stop    INTEGER NOT NULL,
            seconds INTEGER NOT NULL,
            archive TEXT    NOT NULL DEFAULT 'default',
            PRIMARY KEY (stop, archive)
        );
        CREATE TABLE IF NOT EXISTS live_metadata (
            name  TEXT NOT NULL PRIMARY KEY,
            value TEXT
        );
        SQL;

    /** The metadata row the tick keeps up to date: which archives read this journal. */
    public const ARCHIVES_KEY = 'archives';

    private function __construct(private readonly Sqlite $db) {}

    /**
     * Open the journal, creating its tables when they are not there.
     *
     * WAL permits concurrent readers. FULL makes a delivery acknowledgement
     * durable before a remote collector releases its local copy.
     */
    public static function open(string $path, JournalMode $journalMode, int $busyTimeout = 5000): self
    {
        $db = Sqlite::open($path, true, $journalMode, 'FULL', $busyTimeout);
        $db->exec('PRAGMA auto_vacuum=INCREMENTAL');
        $db->exec(self::SCHEMA);
        $db->exec(CollectorStore::SCHEMA);
        $db->exec(Replay::SCHEMA);
        return new self($db);
    }

    /** Receipts and packets use this same connection and transaction. */
    public function collector(): CollectorStore
    {
        return new CollectorStore($this->db, $this);
    }

    public function replay(): Replay
    {
        return new Replay($this->db);
    }

    public function close(): void
    {
        $this->db->close();
    }

    public static function readOnly(string $path): self
    {
        return new self(Sqlite::readOnly($path));
    }

    /** Bounded newest-first LOOP window for frontend snapshots; raw uploads are never loaded.
     * @return Generator<int, Packet>
     */
    public function recent(int $stop, int $limit = 512): Generator
    {
        if ($limit < 1 || $limit > 512) {
            throw new \InvalidArgumentException('Live snapshot limit must be 1..512');
        }
        foreach ($this->db->query('SELECT p.seq, p.dateTime, p.received, p.driver, p.identity, p.sender, p.dialect, p.mapping, p.kind, p.usUnits, p.interval, p.data, m.spec FROM packet p LEFT JOIN dialect_mapping m ON p.mapping = m.digest WHERE p.dateTime <= ? ORDER BY p.dateTime DESC, p.seq DESC LIMIT ?', [$stop, $limit]) as $row) {
            if ($row['kind'] === PacketKind::Loop->value) {
                yield self::packetFrom($row, false);
            }
        }
    }

    public function path(): string
    {
        return $this->db->path();
    }

    // -- writing ----------------------------------------------------------

    /**
     * Store one packet and mark its interval for every archive. Returns
     * false if the packet was already there.
     *
     * Idempotent on (driver, identity, kind, dateTime, digest): a console
     * that retries an upload is not counted twice, and a packet counted
     * twice would shift every mean of its interval.
     *
     * @param list<string> $archives The ids to mark the interval pending for.
     * @param int $intervalSeconds The installation's archive interval.
     * @param bool $keepRaw Whether the raw upload is stored at all.
     * @param array<string, int>|null $intervals Archive-specific grids; null uses the legacy interval.
     * @param string|null $eventId Native event identity; preserves distinct equal-valued readings within one second.
     */
    public function add(Packet $packet, array $archives, int $intervalSeconds, bool $keepRaw = true, ?int $now = null, ?array $intervals = null, ?string $eventId = null): bool
    {
        // The identity row, the packet and the pending marks are one fact. As
        // separate statements, a prune between them could remove a mapping
        // that the packet a moment later refers to.
        return $this->db->transaction(function () use ($packet, $archives, $intervalSeconds, $keepRaw, $now, $intervals, $eventId): bool {
            $received = $packet->received ?? $now ?? time();
            $this->db->exec(
                'INSERT INTO sender_identity (sender, driver, identity, label, first_seen) VALUES (?, ?, ?, NULL, ?)'
                . ' ON CONFLICT(sender) DO UPDATE SET driver = excluded.driver, identity = excluded.identity,'
                // Never moved forward: a back-dated catch-up is older than the
                // row it lands on, and nothing may push the date up.
                . ' first_seen = CASE WHEN sender_identity.first_seen = 0 OR excluded.first_seen < sender_identity.first_seen'
                . ' THEN excluded.first_seen ELSE sender_identity.first_seen END',
                [$packet->sender, $packet->driver, $packet->identity, $received],
            );
            $changed = $this->db->exec(
                'INSERT OR IGNORE INTO packet (dateTime, received, driver, identity, sender, dialect, mapping, kind,'
                . ' usUnits, interval, digest, data, raw) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $packet->dateTime,
                    $received,
                    $packet->driver,
                    $packet->identity,
                    $packet->sender,
                    $packet->dialect,
                    $this->storeMapping($packet->mapping),
                    $packet->kind->value,
                    $packet->unitSystem->value,
                    $packet->interval,
                    $eventId === null ? $packet->digest() : hash('sha256', 'weewx-event:' . $eventId),
                    Packet::canonical($packet->data),
                    $keepRaw ? $packet->raw : null,
                ],
            );
            if ($changed === 0) {
                return false;
            }
            if ($intervals === null) {
                $this->markPending($packet->dateTime, $intervalSeconds, $archives);
            } else {
                foreach ($intervals as $id => $seconds) {
                    $this->markPending($packet->dateTime, $seconds, [$id]);
                }
            }
            return true;
        });
    }

    /**
     * Store many packets in one transaction. Returns how many were new.
     *
     * @param iterable<Packet> $packets
     * @param list<string> $archives
     */
    public function addAll(iterable $packets, array $archives, int $intervalSeconds, bool $keepRaw = true, ?int $now = null): int
    {
        return $this->db->transaction(function () use ($packets, $archives, $intervalSeconds, $keepRaw, $now): int {
            $added = 0;
            foreach ($packets as $packet) {
                if ($this->add($packet, $archives, $intervalSeconds, $keepRaw, $now)) {
                    $added++;
                }
            }
            return $added;
        });
    }

    /**
     * Note that the interval containing a moment needs working out, for
     * every archive named. Every archive rather than the one a packet is
     * for: working out which archive a sender writes into means reading the
     * configuration from in here, and getting it wrong loses readings with
     * no trace. The cost of the blunt version is one empty query per
     * archive and interval.
     *
     * @param list<string> $archives
     *
     * @return int The end of the interval marked.
     */
    public function markPending(int $timestamp, int $intervalSeconds, array $archives): int
    {
        $stop = Intervals::stop($timestamp, $intervalSeconds);
        foreach ($archives as $archive) {
            $this->db->exec('INSERT OR REPLACE INTO pending (stop, seconds, archive) VALUES (?, ?, ?)', [$stop, $intervalSeconds, $archive]);
        }
        return $stop;
    }

    /** One archive is done with this interval. The others are not. */
    public function clearPending(int $stop, string $archive): void
    {
        $this->db->transaction(function () use ($stop, $archive): void {
            $this->db->exec('DELETE FROM pending WHERE stop = ? AND archive = ?', [$stop, $archive]);
            $this->replay()->release($archive, $stop);
        });
    }

    /**
     * Intervals that have ended and can be worked out, oldest first.
     *
     * @param int $grace Seconds an interval is held back after it ends, so a packet that is
     *     merely slow does not force a second computation.
     *
     * @return list<array{stop: int, seconds: int}>
     */
    public function due(int $now, int $grace, string $archive, int $minStop = 0): array
    {
        $due = [];
        foreach ($this->db->query('SELECT stop, seconds FROM pending WHERE stop <= ? AND archive = ? AND stop >= ? ORDER BY stop', [$now - $grace, $archive, $minStop]) as $row) {
            $due[] = ['stop' => (int) Sqlite::text($row['stop']), 'seconds' => (int) Sqlite::text($row['seconds'])];
        }
        return $due;
    }

    // -- reading ----------------------------------------------------------

    /**
     * Every packet in (start, stop], in time order.
     *
     * @param list<string>|null $senders The senders wanted, or null for every one. An empty list
     *     means none.
     * @param bool $withRaw Whether to read the raw upload too. Off by default: the archiver
     *     walks thousands of packets and has no use for it, and that column is the largest.
     *
     * @return Generator<int, Packet>
     */
    public function packets(int $start, int $stop, ?PacketKind $kind = null, ?array $senders = null, bool $withRaw = false): Generator
    {
        if ($senders === []) {
            return;
        }
        $columns = 'p.dateTime, p.received, p.driver, p.identity, p.sender, p.dialect, p.mapping, m.spec, p.kind, p.usUnits, p.interval, p.data'
            . ($withRaw ? ', p.raw' : '');
        $sql = sprintf('SELECT %s FROM packet AS p LEFT JOIN dialect_mapping AS m ON m.digest = p.mapping WHERE p.dateTime > ? AND p.dateTime <= ?', $columns);
        $params = [$start, $stop];
        if ($kind !== null) {
            $sql .= ' AND p.kind = ?';
            $params[] = $kind->value;
        }
        if ($senders !== null) {
            $sql .= sprintf(' AND p.sender IN (%s)', implode(',', array_fill(0, count($senders), '?')));
            $params = [...$params, ...$senders];
        }
        $sql .= ' ORDER BY p.dateTime, p.seq';
        foreach ($this->db->query($sql, $params) as $row) {
            yield self::packetFrom($row, $withRaw);
        }
    }

    /**
     * The latest packet at or before a moment: what a counter delta needs
     * as its predecessor, however long ago that was.
     *
     * @param list<string>|null $senders The senders wanted, or null for every one.
     */
    public function lastBefore(int $timestamp, ?PacketKind $kind = null, ?array $senders = null): ?Packet
    {
        if ($senders === []) {
            return null;
        }
        $sql = 'SELECT p.dateTime, p.received, p.driver, p.identity, p.sender, p.dialect, p.mapping, m.spec, p.kind, p.usUnits, p.interval, p.data'
            . ' FROM packet AS p LEFT JOIN dialect_mapping AS m ON m.digest = p.mapping WHERE p.dateTime <= ?';
        $params = [$timestamp];
        if ($kind !== null) {
            $sql .= ' AND p.kind = ?';
            $params[] = $kind->value;
        }
        if ($senders !== null) {
            $sql .= sprintf(' AND p.sender IN (%s)', implode(',', array_fill(0, count($senders), '?')));
            $params = [...$params, ...$senders];
        }
        $sql .= ' ORDER BY p.dateTime DESC, p.seq DESC LIMIT 1';
        foreach ($this->db->query($sql, $params) as $row) {
            return self::packetFrom($row, false);
        }
        return null;
    }

    /**
     * The earliest packet timestamp after a moment, for skipping a gap
     * without walking empty intervals across it.
     *
     * @param list<string>|null $senders
     */
    public function nextPacketAfter(int $timestamp, ?array $senders = null): ?int
    {
        if ($senders === []) {
            return null;
        }
        $sql = 'SELECT MIN(dateTime) FROM packet WHERE dateTime > ?';
        $params = [$timestamp];
        if ($senders !== null) {
            $sql .= sprintf(' AND sender IN (%s)', implode(',', array_fill(0, count($senders), '?')));
            $params = [...$params, ...$senders];
        }
        $value = $this->db->scalar($sql, $params);
        return is_int($value) ? $value : null;
    }

    /**
     * The first and the last timestamp in the journal.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public function span(): array
    {
        $row = $this->db->one('SELECT MIN(dateTime) AS first, MAX(dateTime) AS last FROM packet');
        $first = $row['first'] ?? null;
        $last = $row['last'] ?? null;
        return [is_int($first) ? $first : null, is_int($last) ? $last : null];
    }

    public function count(): int
    {
        $value = $this->db->scalar('SELECT COUNT(*) FROM packet');
        return is_int($value) ? $value : 0;
    }

    /**
     * When each sender was last heard: `{sender: timestamp}`.
     *
     * @return array<string, int>
     */
    public function lastSeen(): array
    {
        $seen = [];
        foreach ($this->db->query('SELECT sender, MAX(dateTime) AS last FROM packet GROUP BY sender ORDER BY sender') as $row) {
            if (is_int($row['last'])) {
                $seen[Sqlite::text($row['sender'])] = $row['last'];
            }
        }
        return $seen;
    }

    /** @return list<SenderIdentity> Every sender the journal has heard from. */
    public function senders(): array
    {
        $found = [];
        foreach ($this->db->query('SELECT sender, driver, identity, label, first_seen FROM sender_identity ORDER BY first_seen, sender') as $row) {
            $found[] = new SenderIdentity(
                Sqlite::text($row['sender']),
                Sqlite::text($row['driver']),
                Sqlite::text($row['identity']),
                $row['label'] === null ? '' : Sqlite::text($row['label']),
                is_int($row['first_seen']) ? $row['first_seen'] : 0,
            );
        }
        return $found;
    }

    public function getMeta(string $name): ?string
    {
        $value = $this->db->scalar('SELECT value FROM live_metadata WHERE name = ?', [$name]);
        return $value === null ? null : Sqlite::text($value);
    }

    public function setMeta(string $name, string $value): void
    {
        $this->db->exec('INSERT OR REPLACE INTO live_metadata (name, value) VALUES (?, ?)', [$name, $value]);
    }

    // -- retention --------------------------------------------------------

    /**
     * Drop the raw uploads of packets received before a moment. The
     * packets themselves stay. It goes by arrival time rather than reading
     * time: a late packet was still looked at when it arrived.
     */
    public function forgetRaw(int $before): int
    {
        return $this->db->exec('UPDATE packet SET raw = NULL WHERE seq IN (SELECT seq FROM packet WHERE raw IS NOT NULL AND received < ? LIMIT 1000)', [$before]);
    }

    /** Drop packets older than a moment, and the dialect descriptions nothing refers to any more. */
    public function prune(int $before): int
    {
        return $this->db->transaction(function () use ($before): int {
            // Repair needs the complete retained day and its run-up. The
            // archive cursor survives time limits, restarts and receiver races.
            $before = $this->replay()->retentionCutoff($before);
            $dropped = $this->db->exec('DELETE FROM packet WHERE seq IN (SELECT seq FROM packet WHERE dateTime < ? ORDER BY dateTime LIMIT 1000)', [$before]);
            $this->db->exec('DELETE FROM dialect_mapping WHERE digest NOT IN (SELECT mapping FROM packet WHERE mapping IS NOT NULL)');
            $this->db->exec('DELETE FROM pending WHERE stop < ?', [$before]);
            return $dropped;
        });
    }

    /** Give freed pages back to the file system, a little at a time. */
    public function vacuum(): void
    {
        $this->db->exec('PRAGMA incremental_vacuum(100)');
    }

    // -- helpers ----------------------------------------------------------

    /**
     * Store one dialect description once, addressed by its full digest,
     * rather than beside every eight-second packet.
     *
     * @param array<string, mixed>|null $mapping
     */
    private function storeMapping(?array $mapping): ?string
    {
        if ($mapping === null) {
            return null;
        }
        $canonical = Packet::canonical($mapping);
        $digest = hash('sha256', $canonical);
        $this->db->exec('INSERT OR IGNORE INTO dialect_mapping (digest, spec) VALUES (?, ?)', [$digest, $canonical]);
        return $digest;
    }

    /** @param array<string, mixed> $row */
    private static function packetFrom(array $row, bool $withRaw): Packet
    {
        $spec = $row['spec'] ?? null;
        $mapping = null;
        if (is_string($spec) && is_string($row['mapping']) && hash('sha256', $spec) === $row['mapping']) {
            $mapping = Json::object($spec);
        }
        $interval = $row['interval'];
        $received = $row['received'];
        return new Packet(
            dateTime: (int) Sqlite::text($row['dateTime']),
            unitSystem: UnitSystem::from((int) Sqlite::text($row['usUnits'])),
            data: Json::object(Sqlite::text($row['data'])),
            sender: Sqlite::text($row['sender']),
            driver: Sqlite::text($row['driver']),
            identity: Sqlite::text($row['identity']),
            dialect: $row['dialect'] === null ? null : Sqlite::text($row['dialect']),
            mapping: $mapping,
            kind: PacketKind::from(Sqlite::text($row['kind'])),
            interval: is_int($interval) || is_float($interval) ? (float) $interval : null,
            received: is_int($received) ? $received : null,
            raw: $withRaw && isset($row['raw']) ? Sqlite::text($row['raw']) : null,
        );
    }
}
