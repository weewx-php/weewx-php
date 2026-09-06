<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\VecStats;

/** Inspect and repair private snapshots before publishing an archive configuration. */
final class ArchiveImport
{
    public readonly ImportFiles $files;

    public function __construct(private readonly string $configPath, private readonly int $now, string $webspace)
    {
        $this->files = new ImportFiles(Config::load($configPath), $now, $webspace);
    }

    /** @return array<string, mixed> */
    public function inspect(string $id): array
    {
        return $this->files->locked($id, function () use ($id): array {
            $state = $this->files->read($id);
            if (in_array($state['phase'] ?? '', ['repair', 'publishing', 'ready', 'complete'], true)) {
                return $this->publicState($id, $state);
            }
            if (($state['kind'] ?? '') === 'upload' && ($state['received'] ?? null) !== ($state['size'] ?? null)) {
                throw new Problem('error.import_incomplete');
            }
            $path = $this->files->directory($id) . '/archive.sdb';
            if (!ImportFiles::candidate($path)) {
                throw new Problem('error.archive');
            }
            $units = ReadModel::detectArchiveUnits($path);
            $db = Sqlite::readOnly($path);
            try {
                $last = $db->one('SELECT dateTime, interval FROM archive ORDER BY dateTime DESC LIMIT 1');
                $first = $db->scalar('SELECT dateTime FROM archive ORDER BY dateTime LIMIT 1');
                $interval = $last['interval'] ?? 5;
                $seconds = is_int($interval) || is_float($interval) ? (int) round($interval * 60) : 300;
                $state['interval'] = $seconds >= 60 && $seconds <= 3600 && $seconds % 60 === 0 ? $seconds : 300;
                $state['first'] = ImportFiles::integer($first);
                $state['last'] = ImportFiles::integer($last['dateTime'] ?? null);
            } finally {
                $db->close();
            }
            $state['units'] = $units->name;
            $state['phase'] = 'ready';
            $state['size'] = filesize($path);
            $this->files->save($id, $state);
            return $this->publicState($id, $state);
        });
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function publicState(string $id, array $state): array
    {
        if (($state['phase'] ?? '') === 'complete') {
            $this->files->removePayload($id);
        }
        return array_intersect_key($state, array_flip(['phase', 'name', 'size', 'received', 'units', 'first', 'last', 'interval', 'progress', 'archive', 'check'])) + ['id' => $id];
    }

    /** @return array<string, mixed> */
    public function detect(string $id): array
    {
        return $this->files->locked($id, function () use ($id): array {
            $state = $this->files->read($id);
            if (($state['phase'] ?? '') !== 'ready') {
                throw new Problem('error.import_incomplete');
            }
            return DailyStatistics::detect($this->files->directory($id) . '/archive.sdb', $this->now);
        });
    }

    /** @return array<string, mixed> */
    public function commit(string $id, string $name, string $timezone): array
    {
        $name = Input::label($name);
        $zone = DailyStatistics::zone($timezone);
        return $this->files->locked($id, function () use ($id, $name, $timezone, $zone): array {
            $state = $this->files->read($id);
            if (($state['phase'] ?? '') === 'publishing') {
                return $this->publish($id, $state);
            }
            if (in_array($state['phase'] ?? '', ['repair', 'complete'], true)) {
                return $this->publicState($id, $state);
            }
            if (($state['phase'] ?? '') !== 'ready') {
                throw new Problem('error.import_incomplete');
            }
            $path = $this->files->directory($id) . '/archive.sdb';
            $state['label'] = $name;
            $state['timezone'] = $timezone;
            $state['archive'] ??= 'archive-' . substr($id, 0, 16);
            $check = DailyStatistics::check($path, $zone, DailyStatistics::samples($path, $this->now));
            $state['check'] = $check;
            $this->files->save($id, $state);
            if ($check['status'] !== 'match') {
                $weather = ArchiveDb::prepareImportRepair($path, $zone);
                $weather->close();
                $state['phase'] = 'repair';
                $state['cursor'] = Intervals::startOfArchiveDay(ImportFiles::integer($state['first'] ?? null), $zone);
                $state['progress'] = 0;
                $this->files->save($id, $state);
                return $this->publicState($id, $state);
            }
            return $this->publish($id, $state);
        });
    }

    /** @return array<string, mixed> */
    public function step(string $id): array
    {
        return $this->files->locked($id, function () use ($id): array {
            $state = $this->files->read($id);
            if (($state['phase'] ?? '') === 'publishing') {
                return $this->publish($id, $state);
            }
            if (($state['phase'] ?? '') === 'complete') {
                return $this->publicState($id, $state);
            }
            if (($state['phase'] ?? '') !== 'repair') {
                throw new Problem('error.import_incomplete');
            }
            $zone = DailyStatistics::zone(Input::text($state, 'timezone'));
            $cursor = ImportFiles::integer($state['cursor'] ?? null);
            $last = ImportFiles::integer($state['last'] ?? null);
            $end = Intervals::startOfArchiveDay($last, $zone);
            $path = $this->files->directory($id) . '/archive.sdb';
            $weather = ArchiveDb::open($path, JournalMode::Wal, new Policy(), $zone);
            $original = Sqlite::readOnly($path);
            $temporaryTables = $original->tables();
            $started = microtime(true);
            try {
                for ($days = 0; $cursor <= $end && $days < 10 && microtime(true) - $started < 1.0; ++$days) {
                    [$day, $count] = $weather->dayFromRecords($cursor);
                    $stop = Intervals::endOfDay($cursor, $zone);
                    if ($count > 0) {
                        // Preserve known high-resolution extrema by their actual timestamps.
                        foreach ($weather->schema()->dayTypes as $field => $kind) {
                            $table = '_import_day_' . $field;
                            if (!in_array($table, $temporaryTables, true)) {
                                continue;
                            }
                            $quoted = '"' . str_replace('"', '""', $table) . '"';
                            foreach ($original->query('SELECT * FROM ' . $quoted . ' WHERE dateTime >= ? AND dateTime <= ?', [$cursor - 172800, $stop + 172800]) as $row) {
                                foreach (['min' => 'mintime', 'max' => 'maxtime'] as $valueKey => $timeKey) {
                                    $value = $row[$valueKey];
                                    $time = $row[$timeKey];
                                    if ((is_int($value) || is_float($value)) && is_int($time) && $time > $cursor && $time <= $stop) {
                                        $stats = $day->get($field);
                                        $stats->addHilo($stats instanceof VecStats ? [$value, $valueKey === 'max' ? ($row['max_dir'] ?? null) : null] : $value, $time);
                                    }
                                }
                            }
                        }
                        $weather->storeDay($cursor, $day, min($stop, $last));
                    }
                    $cursor = $stop;
                    $state['cursor'] = $cursor;
                    $state['progress'] = min(100, (int) round(($cursor - ImportFiles::integer($state['first'] ?? null)) / max(1, $last - ImportFiles::integer($state['first'] ?? null)) * 100));
                    // Repeating an interrupted day is safe: only unpublished summary rows are replaced.
                    $this->files->save($id, $state);
                }
            } finally {
                $original->close();
                if ($cursor > $end) {
                    $weather->finishImportRepair();
                }
                $weather->close();
            }
            return $cursor > $end ? $this->publish($id, $state) : $this->publicState($id, $state);
        });
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function publish(string $id, array $state): array
    {
        // Persist the resumable publication step before installing the file or configuration.
        $state['phase'] = 'publishing';
        $this->files->save($id, $state);
        $config = Config::load($this->configPath);
        $archive = Input::id(Input::text($state, 'archive'));
        $directory = $config->settings->dataDir . '/archives';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Problem('error.path');
        }
        $target = $directory . '/' . $archive . '.sdb';
        $source = $this->files->directory($id) . '/archive.sdb';
        $existing = $config->archive($archive);
        if ($existing !== null && DatabasePath::key($existing->database) !== DatabasePath::key($target)) {
            throw new Problem('error.duplicate_database');
        }
        if (is_file($target) && hash_file('sha256', $target) !== hash_file('sha256', $source)) {
            throw new Problem('error.duplicate_database');
        }
        if (!is_file($target) && !link($source, $target)) {
            throw new Problem('error.path');
        }
        if ($config->archive($archive) === null) {
            $read = new ReadModel($this->configPath);
            (new Service($this->configPath, $this->now))->execute('archive.connect', ['revision' => $read->revision, 'archive' => $archive, 'name' => Input::text($state, 'label'),
                'database' => $target, 'timezone' => Input::text($state, 'timezone'), 'archive_interval' => (string) ImportFiles::integer($state['interval'] ?? null)]);
        }
        if (is_string($state['source'] ?? null)) {
            $db = Changes::store($config->settings);
            try {
                $db->exec('CREATE TABLE IF NOT EXISTS admin_import_source(source TEXT PRIMARY KEY, archive TEXT NOT NULL)');
                $db->exec('INSERT OR REPLACE INTO admin_import_source(source, archive) VALUES (?, ?)', [DatabasePath::key($state['source']), $archive]);
            } finally {
                $db->close();
            }
        }
        $state['phase'] = 'complete';
        $state['progress'] = 100;
        $this->files->save($id, $state);
        return $this->publicState($id, $state);
    }
}
