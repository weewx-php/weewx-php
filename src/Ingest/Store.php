<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use InvalidArgumentException;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Config\Settings;
use WeewxPhp\Config\StationConfig;
use WeewxPhp\Db\Sqlite;

/** Bounded discovery, credentials, adoption and throttles, separate from live.sdb. */
final class Store
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS ingest_meta (name TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS ingest_sender (
            id TEXT PRIMARY KEY, protocol TEXT NOT NULL, identity TEXT NOT NULL,
            credential TEXT UNIQUE, name TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending',
            model TEXT NOT NULL, peer TEXT NOT NULL, first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL, received INTEGER NOT NULL DEFAULT 0,
            stored INTEGER NOT NULL DEFAULT 0, transport TEXT NOT NULL,
            sample TEXT, adopted_at INTEGER
        );
        CREATE UNIQUE INDEX IF NOT EXISTS ingest_ecowitt_identity
            ON ingest_sender(identity) WHERE protocol = 'ecowitt';
        CREATE TABLE IF NOT EXISTS ingest_limit (
            peer TEXT PRIMARY KEY, window INTEGER NOT NULL, requests INTEGER NOT NULL,
            failed_window INTEGER NOT NULL, failures INTEGER NOT NULL DEFAULT 0,
            blocked_until INTEGER NOT NULL DEFAULT 0, touched INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ingest_field (
            sender TEXT NOT NULL, native TEXT NOT NULL, observation TEXT,
            value REAL, unit TEXT, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL,
            PRIMARY KEY (sender, native)
        );
        CREATE TABLE IF NOT EXISTS ingest_diagnostic (
            sender TEXT PRIMARY KEY, since INTEGER NOT NULL,
            received INTEGER NOT NULL DEFAULT 0, stored INTEGER NOT NULL DEFAULT 0,
            duplicates INTEGER NOT NULL DEFAULT 0, discarded INTEGER NOT NULL DEFAULT 0,
            pending INTEGER NOT NULL DEFAULT 0, last_received INTEGER, last_valid INTEGER,
            last_interval INTEGER, clock_offset INTEGER, time_source TEXT, time_reason TEXT,
            last_error TEXT, last_error_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS ingest_rejection (
            reason TEXT PRIMARY KEY, count INTEGER NOT NULL, last_seen INTEGER NOT NULL
        );
        SQL;

    private function __construct(private readonly Sqlite $db) {}

    public static function open(Settings $settings): self
    {
        $db = Sqlite::open($settings->ingestDbPath(), true, $settings->journalMode);
        $db->exec(self::SCHEMA);
        return new self($db);
    }

    public function close(): void
    {
        $this->db->close();
    }

    /**
     * No database is created merely by reading configuration.
     * @return array<string, StationConfig>
     */
    public static function stations(Settings $settings): array
    {
        if (!is_file($settings->ingestDbPath())) {
            return [];
        }
        $db = Sqlite::readOnly($settings->ingestDbPath());
        try {
            $stations = [];
            foreach ($db->query('SELECT id, name FROM ingest_sender WHERE adopted_at IS NOT NULL') as $row) {
                $id = Sqlite::text($row['id']);
                $stations[$id] = new StationConfig($id, Sqlite::text($row['name']), null, 3, 20);
            }
            return $stations;
        } finally {
            $db->close();
        }
    }

    /** The current free WU credential is shown only through the local administration API/CLI.
     * @return array{ecowitt: string, wunderground: string}
     */
    public function credentials(): array
    {
        return $this->db->transaction(function (): array {
            foreach (['ecowitt', 'wunderground'] as $name) {
                if ($this->meta($name) === null) {
                    $this->db->exec('INSERT INTO ingest_meta VALUES (?, ?)', [$name, $this->unusedSecret()]);
                }
            }
            return ['ecowitt' => $this->meta('ecowitt') ?? '', 'wunderground' => $this->meta('wunderground') ?? ''];
        });
    }

    public function ecowittKeyMatches(string $offered): bool
    {
        $expected = $this->meta('ecowitt');
        return $expected !== null && hash_equals($expected, $offered);
    }

    /** Authenticate before parsing measurements, so malformed readings affect their own sender.
     * The receiver must validate the Ecowitt path before calling this method.
     */
    public function authenticate(Protocol $protocol, string $identity, string $password): ?Sender
    {
        if ($protocol === Protocol::Ecowitt) {
            if (preg_match('/^[a-fA-F0-9]{32}$/D', $identity) !== 1) {
                throw new Rejected('invalid ecowitt identity');
            }
            $row = $this->db->one("SELECT * FROM ingest_sender WHERE protocol = 'ecowitt' AND identity = ?", [strtoupper($identity)]);
        } else {
            $row = $this->db->one('SELECT * FROM ingest_sender WHERE credential = ?', [hash('sha256', $password)]);
            if ($row === null) {
                $free = $this->meta('wunderground');
                if ($free === null || !hash_equals($free, $password)) {
                    throw new Rejected('unauthorised', 403);
                }
            }
        }
        return $row === null ? null : Sender::fromRow($row);
    }

    /** Replace only the setup credential; assigned WU senders remain untouched. */
    public function rotateSetup(Protocol $protocol): string
    {
        return $this->db->transaction(function () use ($protocol): string {
            $secret = $this->unusedSecret();
            $this->db->exec('INSERT INTO ingest_meta VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value', [$protocol->value, $secret]);
            return $secret;
        });
    }

    /** Revoke the old password immediately, preserving the sender id, state and history. */
    public function replacePassword(string $id): string
    {
        return $this->db->transaction(function () use ($id): string {
            $sender = $this->sender($id) ?? throw new InvalidArgumentException('unknown sender');
            if ($sender->protocol !== Protocol::Wunderground) {
                throw new InvalidArgumentException('only Wunderground senders have a password');
            }
            $secret = $this->unusedSecret();
            $this->db->exec('UPDATE ingest_sender SET credential = ? WHERE id = ?', [hash('sha256', $secret), $id]);
            return $secret;
        });
    }

    /** The secret never becomes a sender identity. Only its SHA-256 lookup digest survives assignment.
     * @param callable(Sender): bool $write Called only for an adopted sender; returns whether a new live packet was stored.
     */
    public function receive(
        Observation $observation,
        string $password,
        string $peer,
        bool $https,
        int $now,
        int $maxPending,
        callable $write,
    ): Sender {
        return $this->db->transaction(function () use ($observation, $password, $peer, $https, $now, $maxPending, $write): Sender {
            $wu = $observation->protocol === Protocol::Wunderground;
            $digest = $wu ? hash('sha256', $password) : null;
            $row = $wu
                ? $this->db->one('SELECT * FROM ingest_sender WHERE credential = ?', [$digest])
                : $this->db->one("SELECT * FROM ingest_sender WHERE protocol = 'ecowitt' AND identity = ?", [$observation->identity]);
            if ($row === null) {
                if ($wu) {
                    $free = $this->meta('wunderground');
                    if ($free === null || !hash_equals($free, $password)) {
                        throw new Rejected('unauthorised', 403);
                    }
                }
                $pending = (int) Sqlite::text($this->db->scalar("SELECT COUNT(*) FROM ingest_sender WHERE state = 'pending'"));
                $total = (int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM ingest_sender'));
                if ($pending >= $maxPending || $total >= 2000) {
                    throw new Rejected('discovery full', 503);
                }
                $id = $observation->protocol->value . '_' . bin2hex(random_bytes(6));
                $this->db->exec(
                    'INSERT INTO ingest_sender (id, protocol, identity, credential, name, model, peer, first_seen, last_seen, transport)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $observation->protocol->value, $observation->identity, $digest, $id,
                        $observation->model, $peer, $now, $now, $https ? 'https' : 'http'],
                );
                if ($wu) {
                    $this->db->exec("UPDATE ingest_meta SET value = ? WHERE name = 'wunderground'", [$this->unusedSecret()]);
                }
                $row = $this->db->one('SELECT * FROM ingest_sender WHERE id = ?', [$id]);
            }
            if ($row === null) {
                throw new Rejected('sender unavailable', 503);
            }
            $sender = Sender::fromRow($row);
            if (in_array($sender->state, ['pending', 'adopted'], true)) {
                $known = [];
                foreach ($this->db->query('SELECT native FROM ingest_field WHERE sender = ?', [$sender->id]) as $fieldRow) {
                    $known[Sqlite::text($fieldRow['native'])] = true;
                }
                foreach ($observation->fields as $native => $field) {
                    if (!Parser::measurementKey($native)) {
                        continue;
                    }
                    if (count($known) >= 256 && !isset($known[$native])) {
                        continue;
                    }
                    $this->db->exec(
                        'INSERT INTO ingest_field (sender, native, observation, value, unit, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?)'
                        . ' ON CONFLICT(sender, native) DO UPDATE SET observation = excluded.observation, value = excluded.value, unit = excluded.unit, last_seen = excluded.last_seen',
                        [$sender->id, $native, $field['observation'], $field['value'], $field['unit'], $now, $now],
                    );
                    $known[$native] = true;
                }
            }
            // Blocking and adoption serialize with this write. No pre-adoption
            // sample is replayed into the journal when the state later changes.
            $stored = $sender->state === 'adopted' && $write($sender);
            $outcome = $sender->state === 'adopted' ? ($stored ? 'stored' : 'duplicates')
                : ($sender->state === 'pending' ? 'pending' : 'discarded');
            $this->record($sender->id, $outcome, $now, $observation);
            $sample = in_array($sender->state, ['pending', 'adopted'], true) ? $observation->sample : null;
            $this->db->exec(
                'UPDATE ingest_sender SET last_seen = ?, received = received + 1, stored = stored + ?, '
                . 'peer = ?, transport = ?, model = ?, sample = ? WHERE id = ?',
                [$now, $stored ? 1 : 0, $peer, $https ? 'https' : 'http', $observation->model, $sample, $sender->id],
            );
            return $this->sender($sender->id) ?? $sender;
        });
    }

    /** @return list<Sender> */
    public function senders(): array
    {
        return array_map(Sender::fromRow(...), iterator_to_array($this->db->query('SELECT * FROM ingest_sender ORDER BY first_seen, id'), false));
    }

    public function sender(string $id): ?Sender
    {
        $row = $this->db->one('SELECT * FROM ingest_sender WHERE id = ?', [$id]);
        return $row === null ? null : Sender::fromRow($row);
    }

    public function adopt(string $id, ?string $name, int $now): void
    {
        if ($name !== null && ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1)) {
            throw new InvalidArgumentException('name must contain 1 to 100 characters without control characters');
        }
        $this->db->transaction(function () use ($id, $name, $now): void {
            $sender = $this->sender($id) ?? throw new InvalidArgumentException('unknown sender');
            $this->db->exec(
                "UPDATE ingest_sender SET state = 'adopted', name = ?, adopted_at = COALESCE(adopted_at, ?) WHERE id = ?",
                [$name ?? $sender->name, $now, $id],
            );
        });
    }

    public function setState(string $id, string $state): void
    {
        if (!in_array($state, ['ignored', 'blocked', 'pending'], true)) {
            throw new InvalidArgumentException('invalid sender state');
        }
        $this->db->transaction(function () use ($id, $state): void {
            if ($this->db->exec('UPDATE ingest_sender SET state = ?, sample = NULL WHERE id = ?', [$state, $id]) === 0) {
                throw new InvalidArgumentException('unknown sender');
            }
            $this->db->exec('UPDATE ingest_field SET value = NULL WHERE sender = ?', [$id]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $sender): array
    {
        return iterator_to_array($this->db->query('SELECT * FROM ingest_field WHERE sender = ? ORDER BY native LIMIT 256', [$sender]), false);
    }

    /** Shared across PHP requests/processes; never trusts a client-supplied forwarding header here. */
    public function permit(string $peer, int $now, IngestConfig $config): bool
    {
        return $this->db->transaction(function () use ($peer, $now, $config): bool {
            // Only the small limiter table is maintained on reception. Samples
            // and field inventories are cleaned by the periodic tick.
            $this->pruneLimits($now);
            foreach (['*', $peer] as $key) {
                $limit = $key === '*' ? $config->requestsPerMinute * 10 : $config->requestsPerMinute;
                // IP failure blocks are checked AFTER authentication. Known
                // consoles behind that IP keep their own allowance.
                if (!$this->limit($key, $now, $limit, false)) {
                    return false;
                }
            }
            return true;
        });
    }

    public function permitSender(?Sender $sender, string $peer, int $now, IngestConfig $config): bool
    {
        if ($sender === null) {
            return !$this->blocked($peer, $now);
        }
        return $this->db->transaction(fn(): bool => $this->limit('sender:' . $sender->id, $now, $config->senderRequestsPerMinute, true));
    }

    public function blocked(string $scope, int $now): bool
    {
        $until = $this->db->scalar('SELECT blocked_until FROM ingest_limit WHERE peer = ?', [$scope]);
        return $until !== null && (int) Sqlite::text($until) > $now;
    }

    private function limit(string $key, int $now, int $limit, bool $sender): bool
    {
        $row = $this->db->one('SELECT * FROM ingest_limit WHERE peer = ?', [$key]);
        if ($row === null) {
            // Authenticated sender rows have a separate, inherently bounded
            // namespace, so an IP-table flood cannot consume their slots.
            if (!$sender && (int) Sqlite::text($this->db->scalar("SELECT COUNT(*) FROM ingest_limit WHERE peer NOT LIKE 'sender:%'")) >= 4096) {
                return false;
            }
            $this->db->exec(
                'INSERT INTO ingest_limit (peer, window, requests, failed_window, touched) VALUES (?, ?, 0, ?, ?)',
                [$key, intdiv($now, 60), intdiv($now, 300), $now],
            );
        }
        $minute = intdiv($now, 60);
        $this->db->exec(
            'UPDATE ingest_limit SET requests = CASE WHEN window = ? THEN requests + 1 ELSE 1 END, window = ?, touched = ? WHERE peer = ?',
            [$minute, $minute, $now, $key],
        );
        return $row === null || ((!$sender || (int) Sqlite::text($row['blocked_until']) <= $now)
            && ((int) Sqlite::text($row['window']) !== $minute || (int) Sqlite::text($row['requests']) < $limit));
    }

    /** Thirty failures in five minutes block the authenticated sender, or discovery on its IP, for ten minutes. */
    public function failed(string $peer, int $now, ?string $sender = null): void
    {
        $peer = $sender === null ? $peer : 'sender:' . $sender;
        $this->db->transaction(function () use ($peer, $now): void {
            $window = intdiv($now, 300);
            $this->db->exec(
                'UPDATE ingest_limit SET failures = CASE WHEN failed_window = ? THEN failures + 1 ELSE 1 END, failed_window = ? WHERE peer = ?',
                [$window, $window, $peer],
            );
            $this->db->exec('UPDATE ingest_limit SET blocked_until = ? WHERE peer = ? AND failures >= 30', [$now + 600, $peer]);
        });
    }

    public function prune(int $now): void
    {
        $this->db->transaction(function () use ($now): void {
            if ($now - (int) ($this->meta('pruned') ?? '0') < 600) {
                return;
            }
            $this->pruneLimits($now);
            $this->db->exec('UPDATE ingest_field SET value = NULL WHERE last_seen < ? AND value IS NOT NULL', [$now - 86400]);
            // Retain identities/assigned credentials so an old console never becomes a new one.
            $this->db->exec('UPDATE ingest_sender SET sample = NULL WHERE last_seen < ? AND sample IS NOT NULL', [$now - 86400]);
            $this->db->exec("INSERT INTO ingest_meta VALUES ('pruned', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [(string) $now]);
        });
    }

    private function pruneLimits(int $now): void
    {
        if ($now - (int) ($this->meta('limits_pruned') ?? '0') < 600) {
            return;
        }
        $this->db->exec('DELETE FROM ingest_limit WHERE touched < ? AND blocked_until < ?', [$now - 3600, $now]);
        $this->db->exec("INSERT INTO ingest_meta VALUES ('limits_pruned', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [(string) $now]);
    }

    public function diagnostics(string $sender): ?Diagnostics
    {
        $row = $this->db->one('SELECT * FROM ingest_diagnostic WHERE sender = ?', [$sender]);
        return $row === null ? null : Diagnostics::fromRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function rejections(): array
    {
        return iterator_to_array($this->db->query('SELECT * FROM ingest_rejection ORDER BY last_seen DESC, reason'), false);
    }

    /** Only fixed internal reasons may be supplied, never submitted values. */
    public function rejected(?string $sender, string $reason, int $now): void
    {
        $this->db->transaction(function () use ($sender, $reason, $now): void {
            $this->db->exec('INSERT INTO ingest_rejection VALUES (?, 1, ?) ON CONFLICT(reason) DO UPDATE SET count = count + 1, last_seen = excluded.last_seen', [$reason, $now]);
            if ($sender !== null) {
                $this->record($sender, 'discarded', $now);
                $this->db->exec('UPDATE ingest_diagnostic SET last_error = ?, last_error_at = ? WHERE sender = ?', [$reason, $now, $sender]);
            }
        });
    }

    private function record(string $sender, string $outcome, int $now, ?Observation $observation = null): void
    {
        // These counters start on upgrade; historical pending/duplicate packets
        // cannot be reconstructed from the old received/stored pair.
        $this->db->exec('INSERT OR IGNORE INTO ingest_diagnostic (sender, since) VALUES (?, ?)', [$sender, $now]);
        $this->db->exec(
            'UPDATE ingest_diagnostic SET received = received + 1, stored = stored + ?, duplicates = duplicates + ?, '
            . 'discarded = discarded + ?, pending = pending + ?, last_received = ? WHERE sender = ?',
            [$outcome === 'stored' ? 1 : 0, $outcome === 'duplicates' ? 1 : 0,
                $outcome === 'discarded' ? 1 : 0, $outcome === 'pending' ? 1 : 0, $now, $sender],
        );
        if ($observation !== null) {
            $this->db->exec(
                'UPDATE ingest_diagnostic SET last_interval = CASE WHEN last_valid IS NOT NULL AND ? >= last_valid THEN ? - last_valid ELSE NULL END, '
                . 'last_valid = ?, clock_offset = ?, time_source = ?, time_reason = ? WHERE sender = ?',
                [$now, $now, $now, $observation->reportedTimestamp === null ? null : $observation->reportedTimestamp - $now,
                    $observation->deviceTime ? 'device' : 'server', $observation->timeReason, $sender],
            );
        }
    }

    /** At most one ingest-triggered tick per minute, across PHP workers. */
    public function claimTick(int $now): bool
    {
        return $this->db->transaction(function () use ($now): bool {
            if ($now - (int) ($this->meta('tick') ?? '0') < 60) {
                return false;
            }
            $this->db->exec("INSERT INTO ingest_meta VALUES ('tick', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [(string) $now]);
            return true;
        });
    }

    private function meta(string $name): ?string
    {
        $value = $this->db->scalar('SELECT value FROM ingest_meta WHERE name = ?', [$name]);
        return $value === null ? null : Sqlite::text($value);
    }

    /** Called under the write lock, so concurrent assignment/rotation cannot collide. */
    private function unusedSecret(): string
    {
        do {
            $secret = self::secret();
        } while ($secret === $this->meta('ecowitt') || $secret === $this->meta('wunderground')
            || $this->db->scalar('SELECT id FROM ingest_sender WHERE credential = ?', [hash('sha256', $secret)]) !== null);
        return $secret;
    }

    private static function secret(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $secret = '';
        for ($i = 0; $i < 12; ++$i) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $secret;
    }
}
