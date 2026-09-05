<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use RuntimeException;

/**
 * A directory a test may write to. Under WEEWX_PHP_TMP, because in the test
 * image the repository is mounted read-only and /tmp is the only place that
 * takes a file.
 */
final class TempDir
{
    public static function create(string $label): string
    {
        $base = getenv('WEEWX_PHP_TMP');
        if ($base === false || $base === '') {
            $base = sys_get_temp_dir();
        }
        $dir = $base . '/weewx-php-' . $label . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException(sprintf('Cannot create %s', $dir));
        }
        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
