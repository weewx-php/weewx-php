<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

/** Recovery methods depend only on PHP itself so they also work after an interrupted replacement. */
final class Installer
{
    public function __construct(private readonly string $root) {}

    private static function target(string $root, string $name): string
    {
        if (is_link($root) || strlen($name) > 240 || preg_match('#^(?:src/|resources/|public/|themes/basic/|bin/|frontend\.php$|LICENSE$)#D', $name) !== 1) {
            throw new \RuntimeException('Invalid core recovery path');
        }
        $path = $root;
        foreach (explode('/', $name) as $part) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $part) !== 1 || str_ends_with($part, '.')) {
                throw new \RuntimeException('Invalid core recovery path');
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new \RuntimeException('Symlink in core update path');
            }
        }
        if (file_exists($path) && !is_file($path)) {
            throw new \RuntimeException('Core target is not a file');
        }
        return $path;
    }

    private static function write(string $path, string $body, int $mode = 0644): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Symlink in core update storage');
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create core directory');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body) || !chmod($temporary, $mode)
                || !hash_equals(hash('sha256', $body), (string) hash_file('sha256', $temporary)) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot write core update');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function install(Package $package): string
    {
        if (realpath($this->root) !== $this->root || !is_file($this->root . '/src/autoload.php')) {
            throw new \RuntimeException('Invalid core root');
        }
        Guard::exclusive($this->root);
        $directory = $this->root . '/.weewx-deploy';
        $lock = null;
        try {
            if (is_link($directory . '/ssh.lock')) {
                throw new \RuntimeException('Unsafe deployment lock');
            }
            $lock = fopen($directory . '/ssh.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB) || file_exists($directory . '/lock') || file_exists($directory . '/pending.json')) {
                throw new \RuntimeException('Another core deployment is in progress');
            }
            $id = 'core-' . bin2hex(random_bytes(12));
            $transaction = $directory . '/' . $id;
            if (!mkdir($transaction, 0700)) {
                throw new \RuntimeException('Cannot stage core update');
            }
            self::write($transaction . '/installer.php', (string) file_get_contents(__FILE__), 0600);
            $files = [];
            foreach ($package->files as $name => $body) {
                $target = self::target($this->root, $name);
                $original = is_file($target) ? file_get_contents($target) : null;
                if ($original === false) {
                    throw new \RuntimeException('Cannot read installed core');
                }
                if ($original === $body) {
                    continue;
                }
                $parent = dirname($target);
                while (!is_dir($parent)) {
                    $parent = dirname($parent);
                }
                if (!is_writable($parent)) {
                    throw new \RuntimeException('Core files are not writable');
                }
                $mode = $original === null ? 0644 : ((int) fileperms($target) & 0777);
                if ($original !== null) {
                    self::write($transaction . '/backup/' . $name, $original, 0600);
                }
                self::write($transaction . '/new/' . $name, $body, $mode);
                $files[$name] = ['before' => $original === null ? null : hash('sha256', $original), 'after' => hash('sha256', $body), 'mode' => $mode];
            }
            $manifest = json_encode(['transaction' => $id, 'version' => $package->version, 'files' => $files], JSON_THROW_ON_ERROR);
            self::write($transaction . '/manifest.json', $manifest, 0600);
            self::write($directory . '/pending.json', $manifest, 0600);
            try {
                foreach ($files as $name => $item) {
                    $target = self::target($this->root, $name);
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                        throw new \RuntimeException('Cannot create core directory');
                    }
                    if (!rename($transaction . '/new/' . $name, $target)
                        || !hash_equals($item['after'], (string) hash_file('sha256', $target))) {
                        throw new \RuntimeException('Cannot publish core update');
                    }
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($target, true);
                    }
                }
                if (!unlink($directory . '/pending.json')) {
                    throw new \RuntimeException('Cannot complete core update');
                }
            } catch (\Throwable $error) {
                self::recover($this->root);
                throw $error;
            }
            return $id;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            Guard::shared($this->root);
        }
    }

    public static function recover(string $root): void
    {
        $directory = $root . '/.weewx-deploy';
        $pending = $directory . '/pending.json';
        if (!is_file($pending) || is_link($pending)) {
            throw new \RuntimeException('Core recovery manifest missing');
        }
        $manifest = json_decode((string) file_get_contents($pending), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_string($manifest['transaction'] ?? null)
            || preg_match('/^core-[a-f0-9]{24}$/D', $manifest['transaction']) !== 1 || !is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException('Invalid core recovery manifest');
        }
        $transaction = $directory . '/' . $manifest['transaction'];
        if (is_link($transaction)) {
            throw new \RuntimeException('Unsafe core recovery storage');
        }
        foreach ($manifest['files'] as $name => $item) {
            if (!is_string($name) || !is_array($item) || !array_key_exists('before', $item) || !is_string($item['after'] ?? null) || !is_int($item['mode'] ?? null)) {
                throw new \RuntimeException('Invalid core recovery file');
            }
            $target = self::target($root, $name);
            if ($item['before'] === null) {
                if (is_file($target) && !unlink($target)) {
                    throw new \RuntimeException('Cannot remove incomplete core file');
                }
            } else {
                $backup = self::target($transaction . '/backup', $name);
                $body = file_get_contents($backup);
                if (!is_string($item['before']) || $body === false || !hash_equals($item['before'], hash('sha256', $body))) {
                    throw new \RuntimeException('Core backup checksum mismatch');
                }
                self::write($target, $body, $item['mode']);
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }
        }
        if (!unlink($pending)) {
            throw new \RuntimeException('Cannot finish core recovery');
        }
    }
}
