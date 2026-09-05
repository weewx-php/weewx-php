<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Sqlite;

/** Immutable configuration history, stored outside the weather databases. */
final class Revisions
{
    private function __construct(private readonly Sqlite $db, private readonly Settings $settings) {}

    public static function open(Settings $settings): self
    {
        $db = Sqlite::open($settings->stateDbPath(), true, $settings->journalMode);
        $db->exec('CREATE TABLE IF NOT EXISTS archive_revision (archive TEXT NOT NULL, boundary INTEGER NOT NULL, hash TEXT NOT NULL, config TEXT NOT NULL, PRIMARY KEY(archive, boundary))');
        return new self($db, $settings);
    }

    public function close(): void
    {
        $this->db->close();
    }

    public static function snapshot(ConfFile $file, Config $config): string
    {
        $copy = ConfFile::parse($file->toString());
        $root = $copy->root();
        foreach ($root->keys() as $key) {
            if (!in_array($key, ['data_dir', 'timezone', 'archive_interval', 'archive_delay', 'loop_hilo', 'late_packets',
                'live_retention', 'raw_retention', 'time_budget', 'max_intervals_per_run', 'journal_mode', 'log_level',
                'Stations', 'Archives', 'Measurements', 'Sources'], true)) {
                $root->remove($key);
            }
        }
        $root->set('data_dir', $config->settings->dataDir);
        $stations = $root->optionalSection('Stations') ?? $root->addSection('Stations');
        foreach ($config->stations as $id => $station) {
            ($stations->optionalSection($id) ?? $stations->addSection($id))->set('name', $station->name);
        }
        return $copy->toString();
    }

    /** Called under the application lock; a baseline never claims older provenance. */
    public function record(string $snapshot, Config $config, int $now): void
    {
        $hash = hash('sha256', $snapshot);
        $this->db->transaction(function () use ($snapshot, $hash, $config, $now): void {
            foreach ($config->archives as $id => $archive) {
                $last = $this->db->one('SELECT hash FROM archive_revision WHERE archive = ? ORDER BY boundary DESC LIMIT 1', [$id]);
                if ($last !== null && $last['hash'] === $hash) {
                    continue;
                }
                $boundary = $last === null ? 0 : (intdiv($now, $archive->interval($config->settings)) + 1) * $archive->interval($config->settings);
                $this->db->exec('INSERT INTO archive_revision(archive, boundary, hash, config) VALUES (?, ?, ?, ?)'
                    . ' ON CONFLICT(archive, boundary) DO UPDATE SET hash = excluded.hash, config = excluded.config', [$id, $boundary, $hash, $snapshot]);
            }
        });
    }

    /** @return list<array{boundary: int, config: ArchiveConfig, settings: Settings}> */
    public function timeline(string $id): array
    {
        $result = [];
        foreach ($this->db->query('SELECT boundary, config FROM archive_revision WHERE archive = ? ORDER BY boundary', [$id]) as $row) {
            $config = ConfigReader::read(ConfFile::parse(Sqlite::text($row['config'])), $this->settings->dataDir);
            $archive = $config->archive($id);
            if ($archive !== null) {
                $result[] = ['boundary' => (int) Sqlite::text($row['boundary']), 'config' => $archive, 'settings' => $config->settings];
            }
        }
        return $result;
    }
}
