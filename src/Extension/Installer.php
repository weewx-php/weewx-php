<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Config\Section;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;

/** Downloads are staged and verified without loading any package PHP. */
final class Installer
{
    public function __construct(private readonly Files $files, private readonly HttpClient $http) {}

    public function directory(Release $release): string
    {
        return $this->files->root . '/packages/' . $release->id . '/' . $release->fingerprint();
    }

    public function entry(Release $release): string
    {
        return $this->directory($release) . '/' . $release->entry;
    }

    public static function managed(Section $section): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $section->optional('managed_release')?->string() ?? '') === 1;
    }

    public function installed(string $id, Section $section, bool $theme = false): ?Release
    {
        $fingerprint = $section->optional('managed_release')?->string() ?? '';
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return null;
        }
        Release::id($id);
        $json = Files::read($this->files->root . '/packages/' . $id . '/' . $fingerprint . '/.release.json', Catalog::LIMIT);
        if ($json === null) {
            return null;
        }
        try {
            $release = Release::from(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
            return $release->id === $id && hash_equals($fingerprint, $release->fingerprint())
                && $section->optional($theme ? 'directory' : 'entry')?->string() === ($theme ? $this->directory($release) : $this->entry($release)) ? $release : null;
        } catch (\InvalidArgumentException|\JsonException) {
            return null;
        }
    }

    /** The caller holds the store operation lock; the archive lock stays free. */
    public function prepare(Release $release): void
    {
        if (!$release->compatible()) {
            throw new \RuntimeException('Incompatible extension');
        }
        $relative = 'packages/' . $release->id;
        $this->files->directory($relative);
        $destination = $this->directory($release);
        if (file_exists($destination) || is_link($destination)) {
            $this->verify($release);
            Files::write($destination . '/.release.json', $release->json());
            return;
        }
        $staging = $relative . '/stage-' . $release->fingerprint();
        $directory = $this->files->directory($staging);
        $total = 0;
        foreach ($release->files as $path => $expected) {
            $parent = dirname($path);
            $this->files->directory($staging . ($parent === '.' ? '' : '/' . $parent));
            $file = $directory . '/' . $path;
            $body = Files::read($file, Release::FILE_LIMIT);
            if ($body === null || !hash_equals($expected, hash('sha256', $body))) {
                $response = $this->http->send(new HttpRequest('GET', $release->url($path), timeout: 5, maxResponseBytes: Release::FILE_LIMIT));
                if ($response->status !== 200 || strlen($response->body) > Release::FILE_LIMIT || !hash_equals($expected, hash('sha256', $response->body))) {
                    throw new \RuntimeException('Extension file verification failed');
                }
                $body = $response->body;
                Files::write($file, $body);
            }
            $total += strlen($body);
            if ($total > Release::TOTAL_LIMIT) {
                throw new \RuntimeException('Extension package too large');
            }
        }
        Files::write($directory . '/.release.json', $release->json());
        if (!rename($directory, $destination)) {
            throw new \RuntimeException('Cannot publish extension package');
        }
    }

    public function verify(Release $release): void
    {
        $directory = $this->files->directory('packages/' . $release->id . '/' . $release->fingerprint());
        $total = 0;
        foreach ($release->files as $path => $expected) {
            $parent = dirname($path);
            // Check each parent before opening the file (including on reactivation).
            if ($parent !== '.') {
                $this->files->directory('packages/' . $release->id . '/' . $release->fingerprint() . '/' . $parent);
            }
            $body = Files::read($directory . '/' . $path, Release::FILE_LIMIT);
            if ($body === null || !hash_equals($expected, hash('sha256', $body))) {
                throw new \RuntimeException('Installed extension verification failed');
            }
            $total += strlen($body);
        }
        if ($total > Release::TOTAL_LIMIT) {
            throw new \RuntimeException('Extension package too large');
        }
    }
}
