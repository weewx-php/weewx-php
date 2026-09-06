<?php

declare(strict_types=1);

namespace WeewxPhp\Backup;

use WeewxPhp\Config\Config;
use WeewxPhp\Db\Sqlite;

final class Health
{
    /** @return list<string> Translation keys; no paths or credentials. */
    public static function warnings(Config $config, int $now): array
    {
        $settings = $config->settings;
        $warnings = [];
        $lastTick = 0;
        if (is_file($settings->stateDbPath())) {
            $db = Sqlite::readOnly($settings->stateDbPath());
            try {
                if (in_array('runs', $db->tables(), true)) {
                    $value = $db->scalar('SELECT MAX(started_at) FROM runs');
                    $lastTick = is_int($value) ? $value : 0;
                }
            } finally {
                $db->close();
            }
        }
        if ($now - $lastTick > max(900, 3 * $settings->archiveInterval)) {
            $warnings[] = 'health.tick';
        }
        $backup = (new Backups($settings))->status();
        if ($backup['status'] === 'failed') {
            $warnings[] = 'health.backup_failed';
        }
        if ($settings->backupEnabled && ($backup['completed'] === 0 || $now - $backup['completed'] > 36 * 3600)) {
            $warnings[] = 'health.backup';
        }
        $estimate = 1048576;
        $paths = [$settings->liveDbPath(), $settings->stateDbPath(), $settings->ingestDbPath()];
        foreach ($config->archives as $archive) {
            $paths[] = $archive->database;
        }
        foreach ($paths as $path) {
            foreach ([$path, $path . '-wal'] as $file) {
                if (is_file($file)) {
                    $size = filesize($file);
                    $estimate += $size === false ? 0 : $size;
                }
            }
        }
        $free = is_dir($settings->dataDir) ? disk_free_space($settings->dataDir) : false;
        if ($settings->backupEnabled && $free !== false && $free < 2 * $estimate) {
            $warnings[] = 'health.space';
        }
        return $warnings;
    }
}
