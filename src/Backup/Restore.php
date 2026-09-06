<?php

declare(strict_types=1);

namespace WeewxPhp\Backup;

use Phar;
use PharData;
use PharFileInfo;
use RuntimeException;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;

/** Restore into a new private directory, published only after complete validation. */
final class Restore
{
    public static function run(string $package, string $target): string
    {
        $parent = realpath(dirname($target));
        $base = basename($target);
        if ($parent === false || in_array($base, ['', '.', '..'], true) || file_exists($target) || is_link($target)) {
            throw new RuntimeException('Restore target must be a new directory with an existing parent');
        }
        $target = $parent . '/' . $base;
        $tar = new PharData($package);
        if (!$tar->isFileFormat(Phar::TAR) || !isset($tar['manifest.json'])) {
            throw new RuntimeException('Expected a backup TAR');
        }
        $entry = $tar['manifest.json'];
        if (!$entry instanceof PharFileInfo || !$entry->isFile() || $entry->isLink() || $entry->getSize() > 4194304) {
            throw new RuntimeException('Invalid backup manifest');
        }
        $manifest = Json::object($entry->getContent());
        if (($manifest['format'] ?? null) !== 1 || !is_array($manifest['files'] ?? null) || !is_array($manifest['archives'] ?? null)) {
            throw new RuntimeException('Unsupported backup format');
        }
        $archives = [];
        foreach ($manifest['archives'] as $id => $file) {
            $id = (string) $id;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $id) !== 1
                || !is_string($file) || preg_match('/^archive-[0-9]+\.sdb$/D', $file) !== 1 || in_array($file, $archives, true)) {
                throw new RuntimeException('Invalid archive mapping');
            }
            $archives[$id] = $file;
        }
        $allowed = array_merge(['weewx-php.conf', 'live.sdb', 'state.sdb', 'ingest.sdb'], array_values($archives));
        $files = [];
        $total = 0;
        foreach ($manifest['files'] as $file => $metadata) {
            if (!is_string($file) || !in_array($file, $allowed, true) || !is_array($metadata)
                || !is_int($metadata['size'] ?? null) || $metadata['size'] < 0
                || !is_string($metadata['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) !== 1
                || !isset($tar[$file])) {
                throw new RuntimeException('Invalid backup file');
            }
            $info = $tar[$file];
            if (!$info instanceof PharFileInfo || !$info->isFile() || $info->isLink() || $info->isCompressed() || $info->getSize() !== $metadata['size']) {
                throw new RuntimeException('Invalid backup entry');
            }
            $total += $metadata['size'];
            $files[$file] = ['size' => $metadata['size'], 'sha256' => $metadata['sha256']];
        }
        foreach (array_merge(['weewx-php.conf', 'state.sdb', 'live.sdb'], array_values($archives)) as $file) {
            if (!isset($files[$file])) {
                throw new RuntimeException('Incomplete backup');
            }
        }
        if ($files['weewx-php.conf']['size'] > 4194304 || count($tar) !== count($files) + 1) {
            throw new RuntimeException('Unexpected backup contents');
        }
        $free = disk_free_space($parent);
        if ($free !== false && $free < $total + 1048576) {
            throw new RuntimeException('Insufficient restore space');
        }
        $work = $parent . '/.restore-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700) || !mkdir($work . '/data', 0700)) {
            throw new RuntimeException('Cannot create restore directory');
        }
        try {
            foreach ($files as $file => $metadata) {
                $path = $work . ($file === 'weewx-php.conf' ? '/' : '/data/') . $file;
                $info = $tar[$file];
                if (!$info instanceof PharFileInfo) {
                    throw new RuntimeException('Invalid backup entry');
                }
                $input = fopen($info->getPathname(), 'rb');
                if ($input === false) {
                    throw new RuntimeException('Cannot read backup entry');
                }
                try {
                    $output = fopen($path, 'xb');
                    if ($output === false) {
                        throw new RuntimeException('Cannot write restored file');
                    }
                    try {
                        if (stream_copy_to_stream($input, $output) !== $metadata['size']) {
                            throw new RuntimeException('Incomplete restored file');
                        }
                    } finally {
                        fclose($output);
                    }
                } finally {
                    fclose($input);
                }
                chmod($path, 0600);
                if (hash_file('sha256', $path) !== $metadata['sha256']) {
                    throw new RuntimeException('Backup checksum mismatch');
                }
                if ($file !== 'weewx-php.conf') {
                    Backups::checkDatabase($path);
                }
            }
            $configuration = ConfFile::read($work . '/weewx-php.conf');
            $ids = array_keys($configuration->root()->optionalSection('Archives')?->sections() ?? []);
            if (array_diff($ids, array_keys($archives)) !== [] || array_diff(array_keys($archives), $ids) !== []) {
                throw new RuntimeException('Configuration and backup archives disagree');
            }
            self::relocate($configuration, $work . '/data', $archives);
            $configuration->write($work . '/weewx-php.conf');
            Config::load($work . '/weewx-php.conf');
            $db = Sqlite::open($work . '/data/state.sdb', false, JournalMode::Delete);
            try {
                $tables = $db->tables();
                if (in_array('admin_operation', $tables, true) && $db->scalar('SELECT COUNT(*) FROM admin_operation') !== 0) {
                    throw new RuntimeException('Backup contains an unfinished configuration operation');
                }
                foreach (['admin_session', 'admin_login', 'admin_job', 'backup_status'] as $table) {
                    if (in_array($table, $tables, true)) {
                        $db->exec('DELETE FROM ' . $table);
                    }
                }
                if (in_array('archive_revision', $tables, true)) {
                    $rows = iterator_to_array($db->query('SELECT archive, boundary, config FROM archive_revision'), false);
                    foreach ($rows as $row) {
                        $revision = ConfFile::parse(Sqlite::text($row['config']));
                        self::relocate($revision, $target . '/data', $archives);
                        $text = $revision->toString();
                        $db->exec('UPDATE archive_revision SET config = ?, hash = ? WHERE archive = ? AND boundary = ?', [$text, hash('sha256', $text), Sqlite::text($row['archive']), (int) Sqlite::text($row['boundary'])]);
                    }
                }
            } finally {
                $db->close();
            }
            self::relocate($configuration, $target . '/data', $archives);
            $configuration->write($work . '/weewx-php.conf');
            Backups::write($work . '/.htaccess', "Require all denied\n");
            Backups::write($work . '/data/.htaccess', "Require all denied\n");
            if (file_exists($target) || is_link($target) || !rename($work, $target)) {
                throw new RuntimeException('Cannot publish restored installation');
            }
            return $target . '/weewx-php.conf';
        } finally {
            Backups::removeWork($work . '/data');
            Backups::removeWork($work);
        }
    }

    /** @param array<string, string> $archives */
    private static function relocate(ConfFile $file, string $data, array $archives): void
    {
        $file->root()->set('data_dir', str_replace('\\', '/', $data));
        foreach ($file->root()->optionalSection('Archives')?->sections() ?? [] as $id => $archive) {
            // Historical configurations may include archives removed since then.
            $archive->set('database', $archives[$id] ?? 'removed-' . hash('sha256', $id) . '.sdb');
        }
    }
}
