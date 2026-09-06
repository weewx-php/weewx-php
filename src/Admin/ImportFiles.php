<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\Config;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;

/** Private staging, sequential upload blocks and server-owned filesystem search cursors. */
final class ImportFiles
{
    public const CHUNK = 1048576;
    public readonly string $root;

    public function __construct(private readonly Config $config, private readonly int $now, private readonly string $webspace)
    {
        $this->root = $config->settings->dataDir . '/.imports';
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new Problem('error.path');
        }
        if (!is_file($this->root . '/.htaccess')) {
            file_put_contents($this->root . '/.htaccess', "Require all denied\n");
        }
    }

    /** @param array<string, mixed> $state */
    public function begin(array $state): string
    {
        $id = bin2hex(random_bytes(16));
        if (!mkdir($this->directory($id), 0700)) {
            throw new Problem('error.path');
        }
        $this->save($id, $state + ['created' => $this->now]);
        return $id;
    }

    public function directory(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new Problem('error.input');
        }
        return $this->root . '/' . $id;
    }

    /** @return array<string, mixed> */
    public function read(string $id): array
    {
        $path = $this->directory($id) . '/state.json';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false || strlen($text) > 4194304) {
            throw new Problem('error.import_expired');
        }
        return Json::object($text);
    }

    /** @param array<string, mixed> $state */
    public function save(string $id, array $state): void
    {
        $state['updated'] = $this->now;
        $path = $this->directory($id) . '/state.json';
        $temporary = $path . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) === false || !rename($temporary, $path)) {
            throw new Problem('error.path');
        }
    }

    /** Delete only the known temporary payload, never the installed archive or search source. */
    public function removePayload(string $id): void
    {
        foreach (['archive.sdb', 'archive.sdb-wal', 'archive.sdb-shm', 'archive.sdb-journal'] as $name) {
            $path = $this->directory($id) . '/' . $name;
            if (is_file($path) && !unlink($path)) {
                throw new Problem('error.path');
            }
        }
    }

    /** @return array<string, mixed> */
    public function discard(string $id): array
    {
        return $this->locked($id, function () use ($id): array {
            $state = $this->read($id);
            if (($state['phase'] ?? '') === 'publishing') {
                throw new Problem('error.busy', status: 409);
            }
            $this->removePayload($id);
            $state['phase'] = 'discarded';
            $this->save($id, $state);
            return ['id' => $id, 'phase' => 'discarded'];
        });
    }

    /** @param callable(): array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function locked(string $id, callable $action): array
    {
        $directory = $this->directory($id);
        if (!is_dir($directory)) {
            throw new Problem('error.import_expired');
        }
        $lock = fopen($directory . '/lock', 'c');
        if ($lock === false) {
            throw new Problem('error.path');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new Problem('error.busy', status: 409);
            }
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return int<0, max> */
    public static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new Problem('error.input');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public function upload(string $name, int $size): array
    {
        if ($size < 512 || $size > 68719476736 || strlen($name) > 180) {
            throw new Problem('error.import_size');
        }
        $free = disk_free_space($this->root);
        if ($free !== false && $free < $size + 10485760) {
            throw new Problem('error.import_space');
        }
        $id = $this->begin(['kind' => 'upload', 'phase' => 'upload', 'name' => basename(str_replace('\\', '/', $name)), 'size' => $size, 'received' => 0]);
        return ['id' => $id, 'received' => 0, 'chunkSize' => self::CHUNK];
    }

    /** @return array<string, mixed> */
    public function chunk(string $id, int $offset, string $bytes): array
    {
        return $this->locked($id, function () use ($id, $offset, $bytes): array {
            $state = $this->read($id);
            $received = self::integer($state['received'] ?? null);
            if (($state['phase'] ?? '') !== 'upload' || $offset !== $received) {
                throw new Problem('error.import_offset', status: 409);
            }
            $length = strlen($bytes);
            if ($length < 1 || $length > self::CHUNK || $offset + $length > self::integer($state['size'] ?? null)) {
                throw new Problem('error.import_size');
            }
            $file = fopen($this->directory($id) . '/archive.sdb', 'c+b');
            if ($file === false) {
                throw new Problem('error.path');
            }
            try {
                if (!ftruncate($file, $received) || fseek($file, $received) !== 0 || fwrite($file, $bytes) !== $length || !fflush($file)) {
                    throw new Problem('error.import_space');
                }
            } finally {
                fclose($file);
            }
            $state['received'] = $received + $length;
            $this->save($id, $state);
            return ['received' => $state['received']];
        });
    }

    /** Only regular SQLite tables are candidates; views and unrelated databases are excluded. */
    public static function candidate(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            return false;
        }
        $signature = fread($file, 16);
        fclose($file);
        if ($signature !== "SQLite format 3\0") {
            return false;
        }
        try {
            $db = Sqlite::readOnly($path);
            try {
                if (!in_array('archive', $db->tables(), true)) {
                    return false;
                }
                $columns = array_column($db->columns('archive'), 'name');
                return array_diff(['dateTime', 'usUnits', 'interval'], $columns) === [];
            } finally {
                $db->close();
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function search(?string $id): array
    {
        if ($id === null) {
            $root = realpath($this->webspace);
            if ($root === false || dirname($root) === $root) {
                throw new Problem('error.path');
            }
            $id = $this->begin(['kind' => 'search', 'root' => $root, 'queue' => [$root], 'offset' => 0, 'found' => [], 'visited' => []]);
        }
        return $this->locked($id, function () use ($id): array {
            $state = $this->read($id);
            if (($state['kind'] ?? '') !== 'search') {
                throw new Problem('error.input');
            }
            $queue = self::paths($state['queue'] ?? []);
            $visited = self::paths($state['visited'] ?? []);
            $found = is_array($state['found'] ?? null) ? $state['found'] : [];
            $root = Input::text($state, 'root');
            $offset = self::integer($state['offset'] ?? null);
            $linked = $this->linked();
            $limited = ($state['limited'] ?? false) === true;
            $started = microtime(true);
            $processed = 0;
            while ($queue !== [] && $processed < 500 && microtime(true) - $started < .25) {
                $directory = $queue[0];
                try {
                    $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
                    $position = 0;
                    $finished = true;
                    foreach ($iterator as $entry) {
                        if (!$entry instanceof \SplFileInfo) {
                            continue;
                        }
                        if ($position++ < $offset) {
                            continue;
                        }
                        $offset = $position;
                        ++$processed;
                        $path = $entry->getRealPath();
                        if ($path !== false && str_starts_with(DatabasePath::key($path), rtrim(DatabasePath::key($root), '/') . '/')) {
                            if ($entry->isDir()) {
                                if (!in_array($entry->getFilename(), ['.git', 'node_modules', 'vendor', '.imports', 'logs', '.cache'], true)
                                    && DatabasePath::key($path) !== DatabasePath::key($this->config->settings->dataDir . '/backups')
                                    && !in_array($path, $visited, true) && !in_array($path, $queue, true)) {
                                    if (count($visited) + count($queue) < 20000) {
                                        $queue[] = $path;
                                    } else {
                                        $limited = true;
                                    }
                                }
                            } elseif (preg_match('/\.(sdb|db|sqlite|sqlite3)$/i', $entry->getFilename()) === 1 && !in_array(DatabasePath::key($path), $linked, true) && self::candidate($path)) {
                                $key = substr(hash('sha256', $path), 0, 32);
                                if (count($found) < 100) {
                                    $found[$key] = ['key' => $key, 'path' => $path, 'label' => substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $root)) + 1), 'size' => $entry->getSize()];
                                } else {
                                    $limited = true;
                                }
                            }
                        }
                        if ($processed >= 500 || microtime(true) - $started >= .25) {
                            $finished = false;
                            break;
                        }
                    }
                } catch (\UnexpectedValueException) {
                    $finished = true;
                }
                if ($finished) {
                    $visited[] = array_shift($queue);
                    $offset = 0;
                }
            }
            $state['queue'] = $queue;
            $state['visited'] = $visited;
            $state['offset'] = $offset;
            $state['found'] = $found;
            if (strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 3145728) {
                $state['queue'] = $queue = [];
                $state['visited'] = [];
                $limited = true;
            }
            $state['limited'] = $limited;
            $this->save($id, $state);
            $files = [];
            foreach ($found as $entry) {
                if (is_array($entry)) {
                    $files[] = ['key' => $entry['key'], 'label' => $entry['label'], 'size' => $entry['size']];
                }
            }
            return ['id' => $id, 'done' => $queue === [], 'limited' => $limited, 'files' => $files];
        });
    }

    /** @return array<string, mixed> */
    public function select(string $search, string $key): array
    {
        $state = $this->read($search);
        $found = is_array($state['found'] ?? null) ? $state['found'] : [];
        $entry = $found[$key] ?? null;
        if (($state['kind'] ?? '') !== 'search' || !is_array($entry) || !is_string($entry['path'] ?? null)) {
            throw new Problem('error.path');
        }
        $root = Input::text($state, 'root');
        $source = realpath($entry['path']);
        if ($source === false || !str_starts_with(DatabasePath::key($source), rtrim(DatabasePath::key($root), '/') . '/') || !self::candidate($source)) {
            throw new Problem('error.path');
        }
        if (in_array(DatabasePath::key($source), $this->linked(), true)) {
            throw new Problem('error.duplicate_database');
        }
        $free = disk_free_space($this->root);
        $size = filesize($source);
        if ($free !== false && $size !== false && $free < $size + 10485760) {
            throw new Problem('error.import_space');
        }
        $id = $this->begin(['kind' => 'copy', 'phase' => 'uploaded', 'name' => basename($source), 'source' => $source]);
        $db = Sqlite::readOnly($source);
        try {
            // Include committed WAL data without touching the source database or its configuration.
            $db->backup($this->directory($id) . '/archive.sdb');
        } finally {
            $db->close();
        }
        return ['id' => $id];
    }

    /** @return list<string> */
    private function linked(): array
    {
        $linked = [];
        foreach ($this->config->archives as $archive) {
            $resolved = realpath($archive->database);
            $linked[] = DatabasePath::key($resolved === false ? $archive->database : $resolved);
        }
        if (is_file($this->config->settings->stateDbPath())) {
            $db = Sqlite::readOnly($this->config->settings->stateDbPath());
            try {
                if (in_array('admin_import_source', $db->tables(), true)) {
                    foreach ($db->query('SELECT source, archive FROM admin_import_source') as $row) {
                        if ($this->config->archive(Sqlite::text($row['archive'])) !== null) {
                            $linked[] = Sqlite::text($row['source']);
                        }
                    }
                }
            } finally {
                $db->close();
            }
        }
        return $linked;
    }

    /** @return list<string> */
    private static function paths(mixed $values): array
    {
        if (!is_array($values) || count($values) > 20000) {
            throw new Problem('error.input');
        }
        $paths = [];
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 4096) {
                throw new Problem('error.input');
            }
            $paths[] = $value;
        }
        return $paths;
    }
}
