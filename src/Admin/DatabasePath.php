<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

/** Resolved paths, including symlink parents, must stay in the private data root. */
final class DatabasePath
{
    public static function resolve(string $root, string $path, bool $existing = false): string
    {
        $rootReal = realpath($root);
        if ($rootReal === false || str_contains($path, "\0") || preg_match('/^[a-z]+:\/\//i', $path) === 1) {
            throw new Problem('error.path');
        }
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $candidate = $absolute ? $path : $rootReal . '/' . $path;
        $resolved = realpath($candidate);
        if ($resolved === false && !$existing) {
            $parent = realpath(dirname($candidate));
            if ($parent !== false && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\.sdb$/D', basename($candidate)) === 1) {
                $resolved = $parent . '/' . basename($candidate);
            }
        }
        if ($resolved === false || !str_starts_with(self::key($resolved), rtrim(self::key($rootReal), '/') . '/')) {
            throw new Problem('error.path');
        }
        if (in_array(strtolower(basename($resolved)), ['live.sdb', 'ingest.sdb', 'state.sdb', 'analytics.sdb'], true)) {
            throw new Problem('error.path');
        }
        return $resolved;
    }

    public static function key(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
