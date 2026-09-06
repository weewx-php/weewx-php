<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

/** Private, bounded storage. Never follows symlinks within the store. */
final class Files
{
    public function __construct(public readonly string $root) {}

    public function directory(string $relative = ''): string
    {
        $parts = $relative === '' ? [] : explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/^[A-Za-z0-9_.-]+$/D', $part) !== 1) {
                throw new \InvalidArgumentException('Unsafe extension directory');
            }
        }
        $path = $this->root;
        foreach (array_merge([''], $parts) as $part) {
            $path .= $part === '' ? '' : '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                throw new \RuntimeException('Unsafe extension directory');
            }
            if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                throw new \RuntimeException('Cannot create extension directory');
            }
        }
        if (!is_file($this->root . '/.htaccess')) {
            self::write($this->root . '/.htaccess', "Require all denied\n");
        }
        return $path;
    }

    public static function read(string $path, int $limit): ?string
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $text = file_get_contents($path, false, null, 0, max(1, $limit + 1));
        return $text === false || strlen($text) > $limit ? null : $text;
    }

    public static function write(string $path, string $body): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe extension file');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $handle = fopen($temporary, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Cannot stage extension file');
            }
            try {
                if (fwrite($handle, $body) !== strlen($body) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot write extension file');
                }
            } finally {
                fclose($handle);
            }
            chmod($temporary, 0640);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish extension file');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
