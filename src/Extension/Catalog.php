<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;

final class Catalog
{
    public const URL = 'https://raw.githubusercontent.com/weewx-php/extension-catalog/main/catalog.json';
    public const THEME_URL = 'https://raw.githubusercontent.com/weewx-php/theme-catalog/main/catalog.json';
    public const LIMIT = 524288;

    public function __construct(private readonly Files $files, private readonly HttpClient $http, private readonly int $now, private readonly bool $themes = false) {}

    /** Fresh reads never fall back to an old approval list.
     * @return array<string, Release> */
    public function load(bool $fresh = false): array
    {
        $path = $this->files->root . '/catalog.json';
        $cached = Files::read($path, self::LIMIT);
        $modified = $cached === null ? false : filemtime($path);
        if (!$fresh && $cached !== null && $modified !== false && $modified <= $this->now && $modified > $this->now - 21600) {
            try {
                return self::parse($cached, $this->themes);
            } catch (\InvalidArgumentException|\JsonException) {
                // Replace a broken cache only with a validated response.
            }
        }
        $response = $this->http->send(new HttpRequest('GET', $this->themes ? self::THEME_URL : self::URL, timeout: 5, maxResponseBytes: self::LIMIT));
        if ($response->status !== 200 || strlen($response->body) > self::LIMIT) {
            throw new \RuntimeException('Cannot load extension catalog');
        }
        $releases = self::parse($response->body, $this->themes);
        $this->files->directory();
        Files::write($path, $response->body);
        touch($path, $this->now);
        return $releases;
    }

    /** @return array<string, Release> */
    public static function parse(string $json, bool $themes = false): array
    {
        if (strlen($json) > self::LIMIT) {
            throw new \InvalidArgumentException('Catalog too large');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $collection = $themes ? 'themes' : 'extensions';
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data[$collection] ?? null)
            || !array_is_list($data[$collection]) || count($data[$collection]) > 200) {
            throw new \InvalidArgumentException('Invalid extension catalog');
        }
        $result = [];
        foreach ($data[$collection] as $row) {
            $release = Release::from($row);
            if ($themes && (in_array($release->id, ['basic', 'active'], true) || !str_starts_with($release->repository, 'weewx-php/theme-')
                || $release->entry !== 'theme.php' || !isset($release->files['settings.json'], $release->files['locales/en.json']))) {
                throw new \InvalidArgumentException('Invalid theme package');
            }
            if (isset($result[$release->id])) {
                throw new \InvalidArgumentException('Duplicate extension');
            }
            $result[$release->id] = $release;
        }
        return $result;
    }
}
