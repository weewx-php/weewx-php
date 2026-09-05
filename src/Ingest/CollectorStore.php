<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use InvalidArgumentException;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\Settings;
use WeewxPhp\Config\StationConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Weewx\Intervals;

/** Native credentials, admission and delivery receipts, in the live transaction. */
final class CollectorStore
{
    public const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS weewx_collector (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL,
            last_seen INTEGER, minute INTEGER NOT NULL DEFAULT 0, requests INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS weewx_station (
            collector TEXT NOT NULL, station TEXT NOT NULL, sender TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', adopted INTEGER NOT NULL DEFAULT 0,
            driver_module TEXT NOT NULL, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL,
            sample TEXT, stored INTEGER NOT NULL DEFAULT 0, duplicates INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (collector, station)
        );
        CREATE TABLE IF NOT EXISTS weewx_receipt (
            collector TEXT NOT NULL, event TEXT NOT NULL, digest TEXT NOT NULL,
            station TEXT NOT NULL, sender TEXT NOT NULL, received INTEGER NOT NULL,
            PRIMARY KEY (collector, event)
        );
        CREATE INDEX IF NOT EXISTS weewx_receipt_age ON weewx_receipt(received);
        SQL;

    public function __construct(private readonly Sqlite $db, private readonly LiveDb $live) {}

    /** @return array{id: string, token: string} */
    public function create(string $name, int $now): array
    {
        self::label($name);
        return $this->db->transaction(function () use ($name, $now): array {
            if ((int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM weewx_collector')) >= 100) {
                throw new InvalidArgumentException('collector limit reached');
            }
            $id = self::newUuid();
            $token = bin2hex(random_bytes(32));
            $this->db->exec('INSERT INTO weewx_collector (id, name, token_hash, created) VALUES (?, ?, ?, ?)', [$id, $name, hash('sha256', $token), $now]);
            return ['id' => $id, 'token' => $token];
        });
    }

    /** A new local token discovers an unadopted collector; existing bindings never change. */
    private function discover(string $collector, string $token, Config $config, int $now): void
    {
        NativeParser::uuid($collector);
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new Rejected('unauthorized', 401);
        }
        $bound = $this->db->one('SELECT id FROM weewx_collector WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($bound !== null && $bound['id'] !== $collector) {
            throw new Rejected('collector_mismatch', 403);
        }
        $row = $this->db->one('SELECT id, token_hash, enabled FROM weewx_collector WHERE id = ?', [$collector]);
        if ($row !== null) {
            if ($row['enabled'] !== 1 || !hash_equals(Sqlite::text($row['token_hash']), hash('sha256', $token))) {
                throw new Rejected('unauthorized', 401);
            }
            return;
        }
        if ($this->db->one('SELECT id FROM weewx_collector WHERE token_hash = ?', [hash('sha256', $token)]) !== null) {
            throw new Rejected('collector_mismatch', 403);
        }
        $pending = (int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM weewx_collector c WHERE NOT EXISTS (SELECT 1 FROM weewx_station s WHERE s.collector = c.id AND s.adopted = 1)'));
        $total = (int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM weewx_collector'));
        if ($pending >= $config->ingest->maxPending || $total >= 100) {
            throw new Rejected('discovery_capacity', 503);
        }
        $this->db->exec('INSERT INTO weewx_collector (id, name, token_hash, created) VALUES (?, ?, ?, ?)', [$collector, $collector, hash('sha256', $token), $now]);
        $this->permit($collector, $now, $config->ingest->senderRequestsPerMinute);
    }

    /** @return array<string, mixed>|null */
    public function sender(string $sender): ?array
    {
        return $this->db->one('SELECT * FROM weewx_station WHERE sender = ?', [$sender]);
    }

    /** @return list<array<string, mixed>> */
    public function senders(): array
    {
        return iterator_to_array($this->db->query('SELECT * FROM weewx_station ORDER BY first_seen, sender'), false);
    }

    public function setState(string $sender, string $state, ?string $name = null): void
    {
        $row = $this->sender($sender) ?? throw new InvalidArgumentException('unknown station');
        if ($state === 'adopted') {
            $this->adopt(Sqlite::text($row['collector']), Sqlite::text($row['station']), $name);
        } elseif (in_array($state, ['pending', 'blocked', 'ignored'], true)) {
            $this->db->exec('UPDATE weewx_station SET state = ?, sample = NULL WHERE sender = ?', [$state, $sender]);
        } else {
            throw new InvalidArgumentException('invalid station state');
        }
    }

    public function rotate(string $id): string
    {
        NativeParser::uuid($id);
        return $this->db->transaction(function () use ($id): string {
            $token = bin2hex(random_bytes(32));
            if ($this->db->exec('UPDATE weewx_collector SET token_hash = ? WHERE id = ?', [hash('sha256', $token), $id]) === 0) {
                throw new InvalidArgumentException('unknown collector');
            }
            return $token;
        });
    }

    public function enable(string $id, bool $enabled): void
    {
        NativeParser::uuid($id);
        if ($this->db->exec('UPDATE weewx_collector SET enabled = ? WHERE id = ?', [$enabled, $id]) === 0) {
            throw new InvalidArgumentException('unknown collector');
        }
    }

    /** @return list<array<string, mixed>> */
    public function collectors(): array
    {
        return iterator_to_array($this->db->query('SELECT id, name, enabled, created, last_seen FROM weewx_collector ORDER BY created, id'), false);
    }

    /** @return list<array<string, mixed>> */
    public function stations(string $collector): array
    {
        NativeParser::uuid($collector);
        return iterator_to_array($this->db->query('SELECT * FROM weewx_station WHERE collector = ? ORDER BY first_seen, station', [$collector]), false);
    }

    public function adopt(string $collector, string $station, ?string $name = null): void
    {
        NativeParser::uuid($collector);
        NativeParser::uuid($station);
        if ($name !== null) {
            self::label($name);
        }
        $this->db->transaction(function () use ($collector, $station, $name): void {
            if ($this->db->exec("UPDATE weewx_station SET state = 'adopted', adopted = 1, name = COALESCE(?, name) WHERE collector = ? AND station = ?", [$name, $collector, $station]) === 0) {
                throw new InvalidArgumentException('unknown station');
            }
        });
    }

    public function block(string $collector, string $station): void
    {
        NativeParser::uuid($collector);
        NativeParser::uuid($station);
        if ($this->db->exec("UPDATE weewx_station SET state = 'blocked', sample = NULL WHERE collector = ? AND station = ?", [$collector, $station]) === 0) {
            throw new InvalidArgumentException('unknown station');
        }
    }

    /** Admission also supplies station metadata to configuration, CLI and admin views.
     * @return array<string, StationConfig>
     */
    public static function configuredStations(Settings $settings): array
    {
        if (!is_file($settings->liveDbPath())) {
            return [];
        }
        $db = Sqlite::readOnly($settings->liveDbPath());
        try {
            if (!in_array('weewx_station', $db->tables(), true)) {
                return [];
            }
            $stations = [];
            foreach ($db->query('SELECT sender, name FROM weewx_station WHERE adopted = 1') as $row) {
                $id = Sqlite::text($row['sender']);
                $stations[$id] = new StationConfig($id, Sqlite::text($row['name']), null, 3, 20);
            }
            return $stations;
        } finally {
            $db->close();
        }
    }

    public function knownCollector(string $token): ?string
    {
        $row = $this->db->one('SELECT id FROM weewx_collector WHERE token_hash = ?', [hash('sha256', $token)]);
        return $row === null ? null : $this->authenticate($token);
    }

    public function authenticate(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new Rejected('unauthorized', 401);
        }
        $row = $this->db->one('SELECT id, token_hash FROM weewx_collector WHERE token_hash = ? AND enabled = 1', [hash('sha256', $token)]);
        if ($row === null || !hash_equals(Sqlite::text($row['token_hash']), hash('sha256', $token))) {
            throw new Rejected('unauthorized', 401);
        }
        return Sqlite::text($row['id']);
    }

    public function permit(string $collector, int $now, int $limit): bool
    {
        return $this->db->transaction(function () use ($collector, $now, $limit): bool {
            $minute = intdiv($now, 60);
            $this->db->exec('UPDATE weewx_collector SET requests = CASE WHEN minute = ? THEN requests + 1 ELSE 1 END, minute = ? WHERE id = ?', [$minute, $minute, $collector]);
            return (int) Sqlite::text($this->db->scalar('SELECT requests FROM weewx_collector WHERE id = ?', [$collector])) <= $limit;
        });
    }

    /** Entire batch commits before the HTTP response, including receipts and repairs.
     * @param list<NativeEvent> $events
     * @return list<array{event_id: string, station_id: string, status: string, sender?: string, reason?: string}>
     */
    public function receive(string $collector, string $token, array $events, Config $config, int $now): array
    {
        return $this->db->transaction(function () use ($collector, $token, $events, $config, $now): array {
            $this->discover($collector, $token, $config, $now);
            $results = [];
            $receiptCount = (int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM weewx_receipt'));
            foreach ($events as $event) {
                $result = ['event_id' => $event->id, 'station_id' => $event->station];
                $receipt = $this->db->one('SELECT digest, sender FROM weewx_receipt WHERE collector = ? AND event = ?', [$collector, $event->id]);
                if ($receipt !== null) {
                    if (!hash_equals(Sqlite::text($receipt['digest']), $event->digest())) {
                        $results[] = $result + ['status' => 'rejected', 'reason' => 'event_conflict'];
                    } else {
                        $this->db->exec('UPDATE weewx_station SET duplicates = duplicates + 1 WHERE collector = ? AND station = ?', [$collector, $event->station]);
                        $results[] = $result + ['status' => 'duplicate', 'sender' => Sqlite::text($receipt['sender'])];
                    }
                    continue;
                }
                $age = NativeParser::maxAge($config->settings);
                if ($event->timestamp < $now - $age || $event->timestamp > $now + NativeParser::FUTURE_SKEW) {
                    $results[] = $result + ['status' => 'rejected', 'reason' => $event->timestamp < $now - $age ? 'too_old' : 'future_timestamp'];
                    continue;
                }
                $station = $this->station($collector, $event, $config->ingest->maxPending, $now);
                $sender = Sqlite::text($station['sender']);
                $result['sender'] = $sender;
                if ($station['state'] !== 'adopted') {
                    $results[] = $station['state'] === 'pending'
                        ? $result + ['status' => 'pending']
                        : $result + ['status' => 'rejected', 'reason' => 'station_blocked'];
                    continue;
                }
                if (++$receiptCount > $config->ingest->maxNativeReceipts) {
                    throw new Rejected('receipt_capacity', 503);
                }
                $packet = new Packet(
                    $event->timestamp,
                    $event->units,
                    $event->data,
                    $sender,
                    'weewx',
                    $collector . '/' . $event->station,
                    received: $now,
                );
                $stored = $this->live->add(
                    $packet,
                    array_keys($config->archives),
                    $config->settings->archiveInterval,
                    false,
                    $now,
                    $config->intervals(),
                    eventId: $event->id,
                );
                if ($stored) {
                    foreach ($config->archives as $archive) {
                        $seconds = $archive->interval($config->settings);
                        if ($archive->enabled) {
                            $this->live->replay()->hold($archive->id, Intervals::stop($event->timestamp, $seconds));
                        }
                        if ($archive->enabled && Intervals::stop($event->timestamp, $seconds) + $config->settings->archiveDelay <= $now) {
                            // Historical mapping revisions may select different senders.
                            // Conservatively repair every configured archive.
                            $this->live->replay()->mark($archive, $event->timestamp, $now, $seconds);
                        }
                    }
                }
                $this->db->exec('INSERT INTO weewx_receipt VALUES (?, ?, ?, ?, ?, ?)', [$collector, $event->id, $event->digest(), $event->station, $sender, $now]);
                $this->db->exec('UPDATE weewx_station SET stored = stored + ?, duplicates = duplicates + ? WHERE collector = ? AND station = ?', [$stored ? 1 : 0, $stored ? 0 : 1, $collector, $event->station]);
                $results[] = $result + ['status' => $stored ? 'stored' : 'duplicate'];
            }
            $this->db->exec('UPDATE weewx_collector SET last_seen = ? WHERE id = ?', [$now, $collector]);
            return $results;
        });
    }

    /** @return array<string, mixed> */
    private function station(string $collector, NativeEvent $event, int $maxPending, int $now): array
    {
        $row = $this->db->one('SELECT * FROM weewx_station WHERE collector = ? AND station = ?', [$collector, $event->station]);
        if ($row === null) {
            $pending = (int) Sqlite::text($this->db->scalar("SELECT COUNT(*) FROM weewx_station WHERE collector = ? AND state = 'pending'", [$collector]));
            $total = (int) Sqlite::text($this->db->scalar('SELECT COUNT(*) FROM weewx_station'));
            if ($pending >= $maxPending || $total >= 2000) {
                throw new Rejected('station_capacity', 503);
            }
            $sender = 'weewx_' . substr(hash('sha256', $collector . '/' . $event->station), 0, 24);
            $this->db->exec(
                'INSERT INTO weewx_station (collector, station, sender, name, driver_module, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$collector, $event->station, $sender, $sender, $event->module, $now, $now],
            );
            $row = $this->db->one('SELECT * FROM weewx_station WHERE collector = ? AND station = ?', [$collector, $event->station]);
        }
        if ($row === null) {
            throw new Rejected('station_unavailable', 503);
        }
        $sample = Packet::canonical($event->data);
        $this->db->exec(
            'UPDATE weewx_station SET last_seen = ?, driver_module = ?, sample = ? WHERE collector = ? AND station = ?',
            [$now, $event->module, in_array($row['state'], ['pending', 'adopted'], true) && strlen($sample) <= 8192 ? $sample : null, $collector, $event->station],
        );
        return $row;
    }

    public function prune(int $now): void
    {
        $this->db->transaction(function () use ($now): void {
            $this->db->exec('DELETE FROM weewx_receipt WHERE received < ?', [$now - NativeParser::RECEIPT_RETENTION]);
            $this->db->exec('UPDATE weewx_station SET sample = NULL WHERE last_seen < ?', [$now - 86400]);
        });
    }

    private static function label(string $name): void
    {
        if ($name === '' || strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new InvalidArgumentException('invalid name');
        }
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
