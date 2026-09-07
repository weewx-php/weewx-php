<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WeewxPhp\Version;

final class Builder
{
    public static function installation(string $root, string $json, string $output): void
    {
        if (file_exists($output) || is_link($output)) {
            throw new \RuntimeException('Installation archive already exists');
        }
        $package = Package::parse($json, Version::STRING);
        $files = $package->files;
        foreach (['public/.htaccess' => 'public/.htaccess', 'weewx-php.conf.example' => 'weewx-php.conf.example', 'INSTALL.md' => 'docs/install-release.md'] as $name => $source) {
            $path = $root . '/' . $source;
            if (is_link($path) || !is_file($path)) {
                throw new \RuntimeException('Missing installation file');
            }
            $body = file_get_contents($path);
            if ($body === false) {
                throw new \RuntimeException('Cannot read installation file');
            }
            $files[$name] = $body;
        }
        ksort($files);
        $archive = new \PharData($output, 0, null, \Phar::ZIP);
        foreach ($files as $name => $body) {
            $archive->addFromString($name, $body);
            $archive[$name]->chmod($name === 'bin/weewx-php' ? 0755 : 0644);
        }
        $archive->compressFiles(\Phar::GZ);
    }

    public static function build(string $root, string $tag): string
    {
        if ($tag !== 'v' . Version::STRING || preg_match(Release::TAG_PATTERN, $tag) !== 1) {
            throw new \InvalidArgumentException('Release tag must match src/Version.php');
        }
        $paths = ['frontend.php', 'LICENSE', 'bin/weewx-php', 'bin/worker.php', 'bin/ingest-router.php'];
        foreach (['src', 'resources', 'public', 'themes/basic'] as $tree) {
            $parent = $root;
            foreach (explode('/', $tree) as $part) {
                $parent .= '/' . $part;
                if (is_link($parent) || !is_dir($parent)) {
                    throw new \RuntimeException('Invalid core tree');
                }
            }
            $iterator = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root . '/' . $tree, RecursiveDirectoryIterator::SKIP_DOTS), static function (SplFileInfo $file): bool {
                if ($file->isLink()) {
                    throw new \RuntimeException('Symlink in core package');
                }
                return !str_starts_with($file->getFilename(), '.');
            });
            foreach (new RecursiveIteratorIterator($iterator) as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (in_array($file->getExtension(), ['php', 'json', 'js', 'css', 'svg', 'png', 'ico', 'webp', 'woff', 'woff2', 'md'], true)
                    || in_array($file->getFilename(), ['LICENSE', 'NOTICE'], true)) {
                    $paths[] = $path;
                }
            }
        }
        sort($paths);
        $files = [];
        foreach ($paths as $path) {
            Package::path($path);
            $parent = $root;
            foreach (explode('/', $path) as $part) {
                $parent .= '/' . $part;
                if (is_link($parent)) {
                    throw new \RuntimeException('Symlink in core package');
                }
            }
            if (is_link($root . '/' . $path) || !is_file($root . '/' . $path) || filesize($root . '/' . $path) > Package::FILE_LIMIT) {
                throw new \RuntimeException('Invalid core package file');
            }
            $body = file_get_contents($root . '/' . $path);
            if ($body === false) {
                throw new \RuntimeException('Cannot read core package file');
            }
            $files[$path] = ['sha256' => hash('sha256', $body), 'body' => base64_encode($body)];
        }
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($composer) || !is_array($composer['require'] ?? null) || !is_string($composer['require']['php'] ?? null)
            || preg_match('/^>=(\d+\.\d+(?:\.\d+)?)$/D', $composer['require']['php'], $minimum) !== 1) {
            throw new \RuntimeException('Unsupported PHP requirement');
        }
        $requires = [];
        foreach (array_keys($composer['require']) as $dependency) {
            if (is_string($dependency) && str_starts_with($dependency, 'ext-')) {
                $requires[] = substr($dependency, 4);
            }
        }
        $json = json_encode(['schema' => 1, 'version' => Version::STRING, 'php' => $minimum[1], 'requires' => $requires, 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Package::parse($json, Version::STRING);
        return $json;
    }
}
