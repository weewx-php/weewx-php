<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Mapping;
use WeewxPhp\Archive\Revisions;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tick\Lock;

/** One recoverable configuration/schema change, shared by HTTP and CLI. */
final class Changes
{
    public function __construct(private readonly string $path, private readonly int $now) {}

    /** @param callable(ConfFile, Config): void $edit */
    public function apply(string $expected, string $operation, callable $edit): void
    {
        $before = new ReadModel($this->path);
        $settings = $before->config->settings;
        $lock = Lock::tryAcquire($settings->lockPath()) ?? throw new Problem('error.busy', status: 409);
        try {
            self::recover($this->path, $settings);
            $before = new ReadModel($this->path);
            if (!hash_equals($before->revision, $expected)) {
                throw new Problem('error.conflict', status: 409);
            }
            $file = ConfFile::parse($before->file->toString());
            $edit($file, $before->config);
            $resolved = realpath($this->path);
            $after = ConfigReader::read($file, dirname($resolved === false ? $this->path : $resolved));
            $schemas = self::validate($before->config, $after);
            $revisions = Revisions::open($settings);
            try {
                $revisions->record(Revisions::snapshot($before->file, $before->config), $before->config, $this->now);
            } finally {
                $revisions->close();
            }
            $db = self::store($settings);
            try {
                $db->exec(
                    'INSERT INTO admin_operation(id, expected, config, created, action, schemas, nonce) VALUES (1, ?, ?, ?, ?, ?, ?)',
                    [$expected, $file->toString(), $this->now, $operation, json_encode($schemas, JSON_THROW_ON_ERROR), bin2hex(random_bytes(16))],
                );
            } finally {
                $db->close();
            }
            self::recover($this->path, $settings);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, string> */
    private static function validate(Config $before, Config $after): array
    {
        $paths = [];
        $schemas = [];
        foreach ($after->archives as $id => $archive) {
            $path = DatabasePath::resolve($after->settings->dataDir, $archive->database);
            $key = DatabasePath::key($path);
            if (isset($paths[$key])) {
                throw new Problem('error.duplicate_database', 'database');
            }
            $paths[$key] = true;
            $old = $before->archive($id);
            if ($old !== null && DatabasePath::key(DatabasePath::resolve($before->settings->dataDir, $old->database)) !== $key) {
                throw new Problem('error.database_change', 'database');
            }
            if (!is_file($path)) {
                if ($old !== null) {
                    if (json_encode($old) !== json_encode($archive)) {
                        throw new Problem('error.archive');
                    }
                } else {
                    $schemas[$id] = 'create';
                }
                continue;
            }
            if ($old === null) {
                $schemas[$id] = 'connect';
            } elseif ($old->columns !== $archive->columns) {
                $schemas[$id] = 'update';
            }
            $info = ReadModel::archive($archive);
            if ($info['units'] !== null && $info['units'] !== $archive->unitSystem->value && ($old === null || $old->unitSystem !== $archive->unitSystem)) {
                throw new Problem('error.storage_units', 'unit_system');
            }
            if ($old !== null && $info['first'] !== null && ($old->interval($before->settings) !== $archive->interval($after->settings)
                || $old->timezone->getName() !== $archive->timezone->getName())) {
                throw new Problem('error.structural_change');
            }
            foreach ($archive->columns as $name => $type) {
                foreach ($info['schema']->columnTypes as $existing => $actual) {
                    if (strtolower($name) === strtolower($existing) && ($name !== $existing || strtoupper($actual) !== $type->value)) {
                        throw new Problem('error.column_type', 'column');
                    }
                }
            }
            (new Mapping($archive, $archive->primary))->verify($info['schema']->withColumns(array_keys($archive->columns)), false);
        }
        return $schemas;
    }

    /** Called only while holding the application writer lock. */
    public static function recover(string $path, Settings $settings): void
    {
        $db = self::store($settings);
        try {
            $pending = $db->one('SELECT * FROM admin_operation WHERE id = 1');
            if ($pending === null) {
                return;
            }
            $text = Sqlite::text($pending['config']);
            $current = file_get_contents($path);
            if ($current === false || (!hash_equals(Sqlite::text($pending['expected']), hash('sha256', $current)) && !hash_equals(hash('sha256', $text), hash('sha256', $current)))) {
                throw new Problem('error.recovery_conflict', status: 409);
            }
            $file = ConfFile::parse($text);
            $resolved = realpath($path);
            $config = ConfigReader::read($file, dirname($resolved === false ? $path : $resolved));
            $schemas = \WeewxPhp\Db\Json::object(Sqlite::text($pending['schemas']));
            foreach ($schemas as $id => $mode) {
                $archive = $config->archive($id) ?? throw new Problem('error.archive');
                DatabasePath::resolve($settings->dataDir, $archive->database);
                if ($mode === 'connect') {
                    ReadModel::archive($archive);
                    continue;
                }
                $staged = null;
                if ($mode === 'create') {
                    $staged = dirname($archive->database) . '/.' . Sqlite::text($pending['nonce']) . '-' . $id . '.sdb';
                    $owned = $db->one('SELECT archive FROM admin_created WHERE operation = ? AND archive = ?', [Sqlite::text($pending['nonce']), $id]);
                    if ($owned !== null) {
                        // A previous attempt already installed this file; never replace it.
                        ReadModel::archive($archive);
                        continue;
                    }
                    if (is_file($archive->database)) {
                        // Hard-link installation can finish before its state transaction.
                        $a = is_file($staged) ? stat($staged) : false;
                        $b = stat($archive->database);
                        if ($a === false || $b === false || $a['ino'] !== $b['ino'] || $a['dev'] !== $b['dev'] || $a['ino'] === 0) {
                            throw new Problem('error.exists');
                        }
                    }
                    if (!is_file($archive->database) && is_file($staged)) {
                        $inspection = Sqlite::readOnly($staged);
                        try {
                            $empty = $inspection->tables() === [];
                        } finally {
                            $inspection->close();
                        }
                        // A crash before the schema transaction committed leaves an
                        // empty staging file owned by this operation, never an archive.
                        if ($empty && !unlink($staged)) {
                            throw new Problem('error.path');
                        }
                    }
                }
                $archiveDb = ArchiveDb::open($staged ?? $archive->database, $settings->journalMode, $archive->policy(), $archive->timezone, $mode === 'create');
                try {
                    foreach ($archive->columns as $name => $type) {
                        if ($archiveDb->schema()->hasColumn($name) && strtoupper($archiveDb->schema()->columnTypes[$name]) !== $type->value) {
                            throw new Problem('error.column_type');
                        }
                        $archiveDb->addColumn($name, $type);
                    }
                    (new Mapping($archive, $archive->primary))->verify($archiveDb->schema(), false);
                } finally {
                    $archiveDb->close();
                }
                if ($staged !== null) {
                    // link() is an atomic no-replace install on a single filesystem.
                    if (!is_file($archive->database) && !link($staged, $archive->database)) {
                        throw new Problem('error.path');
                    }
                    $db->exec('INSERT OR IGNORE INTO admin_created(operation, archive) VALUES (?, ?)', [Sqlite::text($pending['nonce']), $id]);
                    unlink($staged);
                    $state = \WeewxPhp\State\StateDb::open($settings->stateDbPath(), $settings->journalMode);
                    try {
                        $state->markCreated($id, (int) Sqlite::text($pending['created']));
                    } finally {
                        $state->close();
                    }
                }
            }
            if (!hash_equals(hash('sha256', $text), hash('sha256', $current))) {
                $file->write($path);
            }
            $revisions = Revisions::open($settings);
            try {
                $revisions->record(Revisions::snapshot($file, $config), $config, (int) Sqlite::text($pending['created']));
            } finally {
                $revisions->close();
            }
            $db->transaction(function () use ($db, $pending): void {
                $db->exec('INSERT INTO admin_audit(created, action, subject) VALUES (?, ?, ?)', [(int) Sqlite::text($pending['created']), Sqlite::text($pending['action']), 'configuration']);
                $db->exec('DELETE FROM admin_operation WHERE id = 1');
                $db->exec('DELETE FROM admin_created WHERE operation = ?', [Sqlite::text($pending['nonce'])]);
                $db->exec('DELETE FROM admin_audit WHERE id < (SELECT MAX(id) - 2000 FROM admin_audit)');
            });
        } finally {
            $db->close();
        }
    }

    public static function store(Settings $settings): Sqlite
    {
        $db = Sqlite::open($settings->stateDbPath(), true, $settings->journalMode);
        $db->exec('CREATE TABLE IF NOT EXISTS admin_operation(id INTEGER PRIMARY KEY, expected TEXT NOT NULL, config TEXT NOT NULL, created INTEGER NOT NULL, action TEXT NOT NULL, schemas TEXT NOT NULL, nonce TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS admin_created(operation TEXT NOT NULL, archive TEXT NOT NULL, PRIMARY KEY(operation, archive))');
        $db->exec('CREATE TABLE IF NOT EXISTS admin_audit(id INTEGER PRIMARY KEY, created INTEGER NOT NULL, action TEXT NOT NULL, subject TEXT NOT NULL)');
        return $db;
    }
}
