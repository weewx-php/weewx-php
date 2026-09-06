<?php

declare(strict_types=1);

namespace WeewxPhp\Backup;

use DateTimeImmutable;
use PharData;
use RuntimeException;
use Throwable;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Version;

/** Full installation snapshots. Caller holds tick.lock for creation and retention. */
final class Backups
{
    private const NAME = '/^backup-([0-9]{10,12})-[a-f0-9]{16}\.tar$/D';

    public function __construct(private readonly Settings $settings) {}

    public function directory(): string
    {
        return $this->settings->dataDir . '/backups';
    }

    private function store(): Sqlite
    {
        $db = Sqlite::open($this->settings->stateDbPath(), true, $this->settings->journalMode);
        $db->exec("CREATE TABLE IF NOT EXISTS backup_status(id INTEGER PRIMARY KEY CHECK(id = 1), requested INTEGER NOT NULL DEFAULT 0, attempted INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', filename TEXT NOT NULL DEFAULT '')");
        $db->exec('INSERT OR IGNORE INTO backup_status(id) VALUES (1)');
        return $db;
    }

    /** @return array{requested: int, attempted: int, completed: int, status: string, filename: string} */
    public function status(): array
    {
        $result = ['requested' => 0, 'attempted' => 0, 'completed' => 0, 'status' => 'pending', 'filename' => ''];
        if (!is_file($this->settings->stateDbPath())) {
            return $result;
        }
        $db = Sqlite::readOnly($this->settings->stateDbPath());
        try {
            if (!in_array('backup_status', $db->tables(), true)) {
                return $result;
            }
            $row = $db->one('SELECT * FROM backup_status WHERE id = 1');
            if ($row !== null) {
                foreach (['requested', 'attempted', 'completed'] as $key) {
                    $result[$key] = (int) Sqlite::text($row[$key]);
                }
                foreach (['status', 'filename'] as $key) {
                    $result[$key] = Sqlite::text($row[$key]);
                }
            }
            return $result;
        } finally {
            $db->close();
        }
    }

    public function request(int $now): void
    {
        $db = $this->store();
        try {
            // Repeated clicks coalesce; running work is never reset by a browser.
            $db->exec("UPDATE backup_status SET requested = ?, status = 'queued' WHERE id = 1 AND status <> 'running'", [$now]);
        } finally {
            $db->close();
        }
    }

    /** @return list<array{name: string, created: int, size: int}> */
    public function files(): array
    {
        $files = [];
        if (is_link($this->directory())) {
            throw new RuntimeException('Backup directory must not be a symbolic link');
        }
        $paths = glob($this->directory() . '/backup-*.tar');
        foreach ($paths === false ? [] : $paths as $path) {
            $name = basename($path);
            if (preg_match(self::NAME, $name, $match) !== 1 || is_link($path) || !is_file($path)) {
                continue;
            }
            $size = filesize($path);
            if ($size !== false) {
                $files[] = ['name' => $name, 'created' => (int) $match[1], 'size' => $size];
            }
        }
        usort($files, static fn(array $a, array $b): int => $a['created'] === $b['created'] ? strcmp($b['name'], $a['name']) : $b['created'] <=> $a['created']);
        return $files;
    }

    /** @return resource Opened before retention can remove the directory entry. */
    public function download(string $name)
    {
        if (preg_match(self::NAME, $name) !== 1 || is_link($this->directory())) {
            throw new RuntimeException('Backup not found');
        }
        $path = $this->directory() . '/' . $name;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Backup not found');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Backup not found');
        }
        return $stream;
    }

    private function day(int $time): string
    {
        return (new DateTimeImmutable('@' . $time))->setTimezone($this->settings->timezone)->format('Y-m-d');
    }

    /** Called under tick.lock; a due backup is not starved by archive backlog.
     * @param callable(): void|null $snapshotReady Release the application writer after WAL snapshots are pinned.
     * @return array{status: string, completed: int, filename: string} */
    public function run(Runtime $runtime, bool $force = false, ?callable $snapshotReady = null): array
    {
        $now = $runtime->clock->now();
        $status = $this->status();
        $due = $status['requested'] > 0 || ($this->settings->backupEnabled && ($status['completed'] === 0 || $this->day($status['completed']) !== $this->day($now)));
        if (!$force && (!$due || (in_array($status['status'], ['failed', 'running'], true) && $now - $status['attempted'] < 600))) {
            return ['status' => $status['status'], 'completed' => $status['completed'], 'filename' => $status['filename']];
        }
        $owner = \WeewxPhp\Tick\Lock::tryAcquire($this->settings->dataDir . '/backup.lock');
        if ($owner === null) {
            return ['status' => 'running', 'completed' => $status['completed'], 'filename' => $status['filename']];
        }
        try {
            $db = $this->store();
            try {
                $db->exec("UPDATE backup_status SET attempted = ?, status = 'running' WHERE id = 1", [$now]);
                try {
                    $name = $this->create($runtime, $now, $snapshotReady);
                    $db->exec("UPDATE backup_status SET completed = ?, status = 'complete', filename = ?, requested = 0 WHERE id = 1", [$now, $name]);
                    $this->prune($now, $name);
                    $runtime->log->info('backup complete: ' . $name);
                    return ['status' => 'complete', 'completed' => $now, 'filename' => $name];
                } catch (Throwable $error) {
                    $db->exec("UPDATE backup_status SET status = 'failed' WHERE id = 1");
                    // File paths, configuration and archive contents may contain secrets.
                    $runtime->log->error('backup failed (' . $error::class . ')');
                    return ['status' => 'failed', 'completed' => $status['completed'], 'filename' => $status['filename']];
                }
            } finally {
                $db->close();
            }
        } finally {
            $owner->release();
        }
    }

    /** @param callable(): void|null $snapshotReady */
    private function create(Runtime $runtime, int $now, ?callable $snapshotReady): string
    {
        // Ensure a first concurrent ingest cannot create an omitted database.
        $runtime->ingest();
        $configPath = $runtime->configPath() ?? throw new RuntimeException('Backup needs a configuration file');
        $configuration = file_get_contents($configPath);
        if ($configuration === false) {
            throw new RuntimeException('Cannot read configuration');
        }
        $directory = $this->directory();
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
            throw new RuntimeException('Cannot create backup directory');
        }
        // Remove only our own abandoned work directories, with the writer lock held.
        $abandoned = glob($directory . '/.work-*');
        foreach ($abandoned === false ? [] : $abandoned as $old) {
            if (preg_match('/^\.work-[a-f0-9]{16}$/D', basename($old)) === 1) {
                self::removeWork($old);
            }
        }
        $nonce = bin2hex(random_bytes(8));
        $work = $directory . '/.work-' . $nonce;
        if (!mkdir($work, 0700)) {
            throw new RuntimeException('Cannot create backup staging directory');
        }
        $name = 'backup-' . $now . '-' . $nonce . '.tar';
        $locks = [];
        $readers = [];
        try {
            // Ingest takes its discovery transaction before writing live.sdb.
            $sources = [];
            foreach (['ingest.sdb', 'live.sdb', 'state.sdb'] as $file) {
                $path = $this->settings->dataDir . '/' . $file;
                if (is_file($path)) {
                    $sources[$file] = $path;
                }
            }
            $archives = [];
            foreach ($runtime->config->archives as $id => $archive) {
                // Numeric names avoid filesystem restrictions on configured IDs.
                $file = 'archive-' . count($archives) . '.sdb';
                $archives[$id] = $file;
                $sources[$file] = $archive->database;
            }
            $estimate = 1048576;
            foreach ($sources as $path) {
                if (!is_file($path)) {
                    throw new RuntimeException('Missing backup source');
                }
                $size = filesize($path);
                if ($size === false) {
                    throw new RuntimeException('Missing backup source');
                }
                $estimate += $size;
                if (is_file($path . '-wal')) {
                    $walSize = filesize($path . '-wal');
                    $estimate += $walSize === false ? 0 : $walSize;
                }
            }
            $free = disk_free_space($directory);
            if ($free !== false && $free < 2 * $estimate) {
                throw new RuntimeException('Insufficient backup space');
            }
            $seen = [];
            foreach ($sources as $path) {
                $resolved = realpath($path);
                if ($resolved === false) {
                    throw new RuntimeException('Missing backup source');
                }
                if (isset($seen[$resolved])) {
                    continue;
                }
                $seen[$resolved] = true;
                $lock = Sqlite::open($path, false, $this->settings->journalMode);
                $locks[] = $lock;
                $lock->exec('BEGIN IMMEDIATE');
            }
            $allWal = true;
            foreach ($sources as $file => $path) {
                $source = Sqlite::readOnly($path);
                $readers[$file] = $source;
                $allWal = $allWal && $source->scalar('PRAGMA journal_mode') === 'wal';
                $source->exec('BEGIN');
                $source->scalar('SELECT COUNT(*) FROM sqlite_master');
            }
            // WAL snapshots share one frozen point in time, but retain no writer
            // reservation during copying, validation or compression.
            if ($allWal) {
                foreach (array_reverse($locks) as $lock) {
                    $lock->close();
                }
                $locks = [];
                if ($snapshotReady !== null) {
                    $snapshotReady();
                }
            }
            foreach ($readers as $file => $source) {
                $source->backup($work . '/' . $file);
                $source->close();
                unset($readers[$file]);
            }
            if (file_get_contents($configPath) !== $configuration) {
                throw new RuntimeException('Configuration changed during backup');
            }
            self::write($work . '/weewx-php.conf', $configuration);
            foreach (array_reverse($locks) as $lock) {
                $lock->close();
            }
            $locks = [];
            $files = [];
            foreach (array_merge(array_keys($sources), ['weewx-php.conf']) as $file) {
                $path = $work . '/' . $file;
                if ($file !== 'weewx-php.conf') {
                    self::checkDatabase($path);
                }
                $size = filesize($path);
                $hash = hash_file('sha256', $path);
                if ($size === false || $hash === false) {
                    throw new RuntimeException('Cannot verify backup');
                }
                $files[$file] = ['size' => $size, 'sha256' => $hash];
            }
            $manifest = ['format' => 1, 'version' => Version::STRING, 'created' => $now, 'archives' => $archives, 'files' => $files];
            $tar = new PharData($work . '/package.tar');
            foreach (array_keys($files) as $file) {
                $tar->addFile($work . '/' . $file, $file);
            }
            $tar->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            unset($tar);
            chmod($work . '/package.tar', 0600);
            if (!rename($work . '/package.tar', $directory . '/' . $name)) {
                throw new RuntimeException('Cannot publish backup');
            }
            return $name;
        } finally {
            foreach ($readers as $reader) {
                $reader->close();
            }
            foreach (array_reverse($locks) as $lock) {
                $lock->close();
            }
            self::removeWork($work);
        }
    }

    private function prune(int $now, string $keep): void
    {
        foreach ($this->files() as $file) {
            if ($file['name'] !== $keep && $file['created'] <= $now - $this->settings->backupRetentionDays * 86400) {
                if (!unlink($this->directory() . '/' . $file['name'])) {
                    throw new RuntimeException('Cannot remove expired backup');
                }
            }
        }
    }

    public static function checkDatabase(string $path): void
    {
        $db = Sqlite::readOnly($path);
        try {
            if ($db->scalar('PRAGMA quick_check') !== 'ok') {
                throw new RuntimeException('Backup integrity check failed');
            }
        } finally {
            $db->close();
        }
    }

    public static function write(string $path, string $text): void
    {
        if (file_put_contents($path, $text) !== strlen($text)) {
            throw new RuntimeException('Cannot write backup file');
        }
        chmod($path, 0600);
    }

    /** Only flat, private staging directories created by this class. Never follow links. */
    public static function removeWork(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $file) {
            if ($file !== '.' && $file !== '..' && (is_file($path . '/' . $file) || is_link($path . '/' . $file))) {
                unlink($path . '/' . $file);
            }
        }
        rmdir($path);
    }
}
