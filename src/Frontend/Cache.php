<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;

/** Rebuildable analytics, separate from every WeeWX archive. All writes are short transactions. */
final class Cache
{
    private readonly Sqlite $db;
    /** @var array<string, int> */
    private array $writeRevisions = [];
    public const VERSION = 'frontend-5';
    public const MAX_REQUESTS = 1000;

    public function __construct(Settings $settings)
    {
        $this->db = Sqlite::open(self::path($settings), true, $settings->journalMode, 'NORMAL', 25);
        $this->db->exec('CREATE TABLE IF NOT EXISTS source (archive TEXT PRIMARY KEY, signature TEXT NOT NULL, token TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 0, pending INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS request (id TEXT PRIMARY KEY, archive TEXT NOT NULL, spec TEXT NOT NULL, payload TEXT, work TEXT, start INTEGER, end INTEGER, global_dependency INTEGER NOT NULL DEFAULT 0, due INTEGER NOT NULL DEFAULT 0, dirty INTEGER NOT NULL DEFAULT 1, touched INTEGER NOT NULL, pinned INTEGER NOT NULL DEFAULT 0, error TEXT)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS request_due ON request(due)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS request_archive ON request(archive)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS chunk (id TEXT PRIMARY KEY, archive TEXT NOT NULL, start INTEGER NOT NULL, end INTEGER NOT NULL, payload TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS chunk_archive_span ON chunk(archive, start, end)');
        $columns = array_column(iterator_to_array($this->db->query('PRAGMA table_info(request)'), false), 'name');
        foreach (['priority' => 'INTEGER NOT NULL DEFAULT 0', 'serviced' => 'INTEGER NOT NULL DEFAULT 0', 'diagnostics' => 'TEXT'] as $name => $type) {
            if (!in_array($name, $columns, true)) {
                $this->db->exec('ALTER TABLE request ADD COLUMN ' . $name . ' ' . $type);
            }
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS theme_query (theme TEXT NOT NULL, name TEXT NOT NULL, request TEXT NOT NULL, PRIMARY KEY(theme, name))');
        $this->db->exec('CREATE INDEX IF NOT EXISTS theme_request ON theme_query(request)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS aggregate_state (scope TEXT NOT NULL, archive TEXT NOT NULL, start INTEGER NOT NULL, end INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(scope, start, end))');
        $this->db->exec('CREATE INDEX IF NOT EXISTS state_archive_span ON aggregate_state(archive, start, end)');
    }

    public static function path(Settings $settings): string
    {
        return $settings->dataDir . '/analytics.sdb';
    }

    public function close(): void
    {
        $this->db->close();
    }

    public static function key(string $archive, Spec $spec): string
    {
        return hash('sha256', self::VERSION . ':' . $archive . ':' . $spec->json());
    }

    /** Detect foreign writers and configuration changes. Called before serving cached results. */
    public function observe(ArchiveConfig $archive): int
    {
        $token = ArchiveReader::fingerprint($archive->database);
        $signature = hash('sha256', self::VERSION . CacheJson::encode($archive));
        return $this->db->transaction(function () use ($archive, $token, $signature): int {
            $known = $this->db->one('SELECT * FROM source WHERE archive = ?', [$archive->id]);
            if ($known === null) {
                $this->db->exec('INSERT INTO source(archive, signature, token) VALUES (?, ?, ?)', [$archive->id, $signature, $token]);
                return 0;
            }
            $revision = self::integer($known['revision']);
            if (self::integer($known['pending']) > 0) {
                return $revision;
            }
            if ($known['signature'] !== $signature || $known['token'] !== $token) {
                $this->invalidate($archive->id);
                $this->db->exec('UPDATE source SET signature = ?, token = ? WHERE archive = ?', [$signature, $token, $archive->id]);
                ++$revision;
            }
            return $revision;
        });
    }

    public function pending(string $archive): bool
    {
        return self::integer($this->db->scalar('SELECT pending FROM source WHERE archive = ?', [$archive])) > 0;
    }

    public function revision(string $archive): int
    {
        return self::integer($this->db->scalar('SELECT revision FROM source WHERE archive = ?', [$archive]));
    }

    /** Durable marker BEFORE an archive transaction; workers never publish while it is open. */
    public function beginMutation(ArchiveConfig $archive, Span $span): void
    {
        $this->observe($archive);
        $this->db->transaction(function () use ($archive, $span): void {
            $this->db->exec('UPDATE source SET pending = 1 WHERE archive = ?', [$archive->id]);
            $this->invalidate($archive->id, $span);
        });
    }

    /** After commit (or rollback), invalidate again before accepting the new source token. */
    public function endMutation(ArchiveConfig $archive, Span $span): void
    {
        $this->db->transaction(function () use ($archive, $span): void {
            $this->invalidate($archive->id, $span);
            $this->db->exec('UPDATE source SET pending = 0, token = ? WHERE archive = ?', [ArchiveReader::fingerprint($archive->database), $archive->id]);
        });
    }

    /** Only under tick.lock: no application writer can still own a marker from a crashed run. */
    public function recover(ArchiveConfig $archive): void
    {
        if ($this->pending($archive->id)) {
            $this->db->transaction(function () use ($archive): void {
                $this->invalidate($archive->id);
                $this->db->exec('UPDATE source SET pending = 0, token = ? WHERE archive = ?', [ArchiveReader::fingerprint($archive->database), $archive->id]);
            });
        }
    }

    public function invalidate(string $archive, ?Span $span = null): void
    {
        $params = [$archive];
        $affected = '';
        if ($span !== null) {
            $affected = ' AND (global_dependency = 1 OR start IS NULL OR (end > ? AND start < ?))';
            $params[] = $span->start;
            $params[] = $span->end;
        }
        // Scheduled data keeps its publication cadence. Fixed, closed data can be repaired now.
        $resetWork = $span === null ? 'NULL' : 'CASE WHEN start IS NULL OR (end > ? AND start < ?) OR spec LIKE ? THEN NULL ELSE work END';
        $resetParams = $span === null ? $params : [$span->start, $span->end, '%"aggregate":"historical_%', ...$params];
        $this->db->exec('UPDATE request SET dirty = 1, work = ' . $resetWork . ', due = CASE WHEN due = 9223372036854775807 THEN 0 ELSE due END WHERE archive = ?' . $affected, $resetParams);
        $this->db->exec('DELETE FROM chunk WHERE archive = ?' . ($span === null ? '' : ' AND end > ? AND start < ?'), $span === null ? [$archive] : [$archive, $span->start, $span->end]);
        $this->db->exec('DELETE FROM aggregate_state WHERE archive = ?' . ($span === null ? '' : ' AND end > ? AND start < ?'), $span === null ? [$archive] : [$archive, $span->start, $span->end]);
        $this->db->exec('UPDATE source SET revision = revision + 1 WHERE archive = ?', [$archive]);
    }

    public function register(string $archive, Spec $spec, int $now, bool $pinned = false): string
    {
        $id = self::key($archive, $spec);
        $this->db->transaction(function () use ($id, $archive, $spec, $now, $pinned): void {
            $known = $this->db->one('SELECT touched, pinned FROM request WHERE id = ?', [$id]);
            if ($known !== null) {
                if (self::integer($known['touched']) < $now - 3600 || ($pinned && self::integer($known['pinned']) === 0)) {
                    $this->db->exec('UPDATE request SET touched = ?, pinned = MAX(pinned, ?) WHERE id = ?', [$now, $pinned, $id]);
                }
                return;
            }
            if (self::integer($this->db->scalar('SELECT COUNT(*) FROM request')) >= self::MAX_REQUESTS) {
                throw new QueryError('Analytics query limit reached; remove unused registrations');
            }
            $this->db->exec('INSERT INTO request(id, archive, spec, touched, pinned, priority) VALUES (?, ?, ?, ?, ?, ?)', [$id, $archive, $spec->json(), $now, $pinned, $spec->priority]);
        });
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function request(string $id): ?array
    {
        return $this->db->one('SELECT * FROM request WHERE id = ?', [$id]);
    }

    /** Work and its source generation must be read in the same SQLite snapshot.
     * @return array<string, mixed>|null
     */
    public function requestForWork(string $id): ?array
    {
        return $this->db->one('SELECT r.*, s.revision AS source_revision FROM request r JOIN source s ON s.archive = r.archive WHERE r.id = ? AND s.pending = 0', [$id]);
    }

    public function guardWrites(string $archive, int $revision): void
    {
        $this->writeRevisions[$archive] = $revision;
    }

    private function canWrite(string $archive): bool
    {
        return !isset($this->writeRevisions[$archive])
            || (!$this->pending($archive) && $this->revision($archive) === $this->writeRevisions[$archive]);
    }

    /** @return list<array<string, mixed>> */
    public function due(int $now, int $limit = 16): array
    {
        return iterator_to_array($this->db->query('SELECT * FROM request WHERE due <= ? AND (pinned = 1 OR touched >= ? OR EXISTS(SELECT 1 FROM theme_query WHERE request = id)) ORDER BY MIN(20, priority + MAX(0, (? - CASE WHEN due = 0 THEN touched ELSE MAX(due, serviced) END) / 3600)) DESC, serviced, due, id LIMIT ?', [$now, $now - 30 * 86400, $now, $limit]), false);
    }

    public function progress(string $id, Computation $work, int $now, int $revision): void
    {
        $this->db->transaction(function () use ($id, $work, $now, $revision): void {
            $archive = $this->request($id)['archive'] ?? null;
            if (!is_string($archive) || $this->pending($archive) || $this->revision($archive) !== $revision) {
                return;
            }
            $this->db->exec('UPDATE request SET work = ?, start = ?, end = ?, global_dependency = ?, due = ? WHERE id = ?', [$work->save(), $work->span->start, $work->span->end, str_starts_with($work->spec->aggregate, 'historical_') || $work->spec->period === 'alltime' || $work->spec->analysis === 'compare_month', $now, $id]);
        });
    }

    public function discardChunks(string $archive, Span $span): void
    {
        $this->db->exec('DELETE FROM chunk WHERE archive = ? AND end > ? AND start < ?', [$archive, $span->start, $span->end]);
        $this->db->exec('DELETE FROM aggregate_state WHERE archive = ? AND end > ? AND start < ?', [$archive, $span->start, $span->end]);
    }

    public function publish(string $id, Computation $work, int $now, int $due, int $revision): bool
    {
        return $this->db->transaction(function () use ($id, $work, $now, $due, $revision): bool {
            $archive = $this->request($id)['archive'] ?? null;
            if (!is_string($archive) || $this->pending($archive) || $this->revision($archive) !== $revision) {
                return false;
            }
            $this->db->exec('UPDATE request SET payload = ?, work = NULL, start = ?, end = ?, global_dependency = ?, due = ?, dirty = 0, error = NULL WHERE id = ?', [ResultCodec::encode($work->result($now)), $work->span->start, $work->span->end, str_starts_with($work->spec->aggregate, 'historical_') || $work->spec->period === 'alltime' || $work->spec->analysis === 'compare_month', $due, $id]);
            return true;
        });
    }

    public function failed(string $id, string $error, int $now): void
    {
        $this->db->exec('UPDATE request SET error = ?, work = NULL, due = ? WHERE id = ?', [substr($error, 0, 500), $now + 3600, $id]);
    }

    public function chunk(string $key): ?Value
    {
        $payload = $this->db->scalar('SELECT payload FROM chunk WHERE id = ?', [$key]);
        if (!is_string($payload)) {
            return null;
        }
        $value = ResultCodec::decode($payload, 'UTC');
        return $value instanceof Value ? $value : null;
    }

    public function storeChunk(string $key, string $archive, Span $span, Value $value): void
    {
        $this->db->transaction(function () use ($key, $archive, $span, $value): void {
            if ($this->canWrite($archive)) {
                $this->db->exec('INSERT INTO chunk(id, archive, start, end, payload) VALUES (?, ?, ?, ?, ?) ON CONFLICT(id) DO NOTHING', [$key, $archive, $span->start, $span->end, ResultCodec::encode($value)]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function status(): array
    {
        $rows = iterator_to_array($this->db->query('SELECT id, archive, spec, due, dirty, pinned, priority, serviced, diagnostics, (SELECT COUNT(*) FROM theme_query WHERE request = id) AS owners, error, payload IS NOT NULL AS ready, work IS NOT NULL AS building FROM request ORDER BY archive, id'), false);
        foreach ($rows as &$row) {
            $row['diagnostics'] = is_string($row['diagnostics']) ? json_decode($row['diagnostics'], true, 32, JSON_THROW_ON_ERROR) : null;
        }
        unset($row);
        return $rows;
    }

    public function forget(string $id): void
    {
        if (self::integer($this->db->scalar('SELECT COUNT(*) FROM theme_query WHERE request = ?', [$id])) > 0) {
            throw new QueryError('Query is still owned by a theme');
        }
        $this->db->exec('DELETE FROM request WHERE id = ?', [$id]);
    }

    /** Atomic reconciliation; a failed manifest leaves the preceding registration intact.
     * @param array<string, array{archive: string, spec: Spec}> $definitions
     * @return array<string, string>
     */
    public function sync(string $theme, array $definitions, int $now): array
    {
        if ($theme === '' || strlen($theme) > 240) {
            throw new QueryError('Theme identifier must contain 1..240 bytes');
        }
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new QueryError('Theme queries must be named');
            }
            $definition['spec']->validate();
        }
        return $this->db->transaction(function () use ($theme, $definitions, $now): array {
            $old = iterator_to_array($this->db->query('SELECT name, request FROM theme_query WHERE theme = ? ORDER BY name', [$theme]), false);
            $existing = [];
            foreach ($old as $row) {
                if (is_string($row['name']) && is_string($row['request'])) {
                    $existing[$row['name']] = $row['request'];
                }
            }
            $wanted = array_map(static fn(array $d): string => self::key($d['archive'], $d['spec']), $definitions);
            $sorted = $wanted;
            ksort($sorted);
            if ($existing === $sorted) {
                return $wanted;
            }
            $ids = [];
            $this->db->exec('DELETE FROM theme_query WHERE theme = ?', [$theme]);
            // Release removed requests before enforcing the registration limit.
            foreach ($old as $row) {
                if (is_string($row['request']) && !in_array($row['request'], $wanted, true)) {
                    $this->db->exec('DELETE FROM request WHERE id = ? AND pinned = 0 AND NOT EXISTS(SELECT 1 FROM theme_query WHERE request = id)', [$row['request']]);
                }
            }
            foreach ($definitions as $name => $definition) {
                $id = $this->register($definition['archive'], $definition['spec'], $now);
                $this->db->exec('INSERT INTO theme_query(theme, name, request) VALUES (?, ?, ?)', [$theme, $name, $id]);
                $ids[$name] = $id;
            }
            return $ids;
        });
    }

    /** @param array<string, mixed> $details */
    public function diagnose(string $id, array $details, int $now): void
    {
        $this->db->exec('UPDATE request SET diagnostics = ?, serviced = ? WHERE id = ?', [json_encode($details, JSON_THROW_ON_ERROR), $now, $id]);
    }

    public function storeState(string $scope, string $archive, Span $span, Accumulator $state): void
    {
        $this->db->transaction(function () use ($scope, $archive, $span, $state): void {
            if ($this->canWrite($archive)) {
                $this->db->exec('INSERT INTO aggregate_state(scope, archive, start, end, payload) VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING', [$scope, $archive, $span->start, $span->end, json_encode($state->save(), JSON_THROW_ON_ERROR)]);
            }
        });
    }

    /** Merge a complete partition only; no approximation, overlap or double counting. */
    public function state(string $scope, Span $span, ReadBudget $budget): ?Accumulator
    {
        $cursor = $span->start;
        $state = new Accumulator();
        $budget->statement();
        foreach ($this->db->query('SELECT start, end, payload FROM aggregate_state WHERE scope = ? AND start >= ? AND end <= ? ORDER BY start, end DESC LIMIT 512', [$scope, $span->start, $span->end]) as $row) {
            $budget->row();
            if ($row['start'] !== $cursor) {
                continue;
            }
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR) : null;
            if (!is_array($payload)) {
                throw new QueryError('Invalid saved aggregate state');
            }
            $state->merge(Accumulator::restore($payload));
            $cursor = self::integer($row['end']);
            if ($cursor === $span->end) {
                $budget->source('aggregate_state');
                return $state;
            }
        }
        return null;
    }

    public static function integer(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
