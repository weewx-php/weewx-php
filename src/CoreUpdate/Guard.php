<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

/** Holds a shared lock for the lifetime of each PHP request, including workers. */
final class Guard
{
    /** @var array<string, resource> */
    private static array $handles = [];

    public static function enter(string $root): bool
    {
        if (isset(self::$handles[$root])) {
            return true;
        }
        $directory = $root . '/.weewx-deploy';
        if (is_link($directory) || is_link($directory . '/runtime.lock') || is_link($directory . '/.htaccess') || is_link($directory . '/pending.json')) {
            throw new \RuntimeException('Unsafe core update storage');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700)) {
            return false;
        }
        if (!is_file($directory . '/.htaccess') && @file_put_contents($directory . '/.htaccess', "Require all denied\n") === false) {
            return false;
        }
        $handle = @fopen($directory . '/runtime.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Core update in progress');
        }
        self::$handles[$root] = $handle;
        if (is_file($directory . '/pending.json')) {
            self::exclusive($root);
            try {
                // Recovery uses the installer saved before any core file was replaced.
                $pending = json_decode((string) file_get_contents($directory . '/pending.json'), true, 16, JSON_THROW_ON_ERROR);
                $id = is_array($pending) ? ($pending['transaction'] ?? null) : null;
                if (!is_string($id) || preg_match('/^core-[a-f0-9]{24}$/D', $id) !== 1
                    || is_link($directory . '/' . $id) || is_link($directory . '/' . $id . '/installer.php')) {
                    throw new \RuntimeException('Invalid core recovery state');
                }
                require_once $directory . '/' . $id . '/installer.php';
                Installer::recover($root);
            } finally {
                self::shared($root);
            }
        }
        return true;
    }

    public static function exclusive(string $root): void
    {
        if (!self::enter($root)) {
            throw new \RuntimeException('Core update storage is not writable');
        }
        if (!flock(self::$handles[$root], LOCK_EX | LOCK_NB)) {
            flock(self::$handles[$root], LOCK_SH);
            throw new \RuntimeException('Other PHP requests are still running');
        }
    }

    public static function shared(string $root): void
    {
        if (isset(self::$handles[$root])) {
            flock(self::$handles[$root], LOCK_SH);
        }
    }

    public static function leave(string $root): void
    {
        if (isset(self::$handles[$root])) {
            fclose(self::$handles[$root]);
            unset(self::$handles[$root]);
        }
    }
}
