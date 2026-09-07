<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

final class Release
{
    public const REPOSITORY = 'weewx-php/weewx-php';
    public const ASSET = 'weewx-php-core.json';
    public const API = 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest';
    public const TAG_PATTERN = '/^v((?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})(?:-beta\.(?:0|[1-9][0-9]{0,3}))?)$/D';

    private function __construct(public readonly string $version, public readonly string $url, public readonly string $sha256, public readonly int $size) {}

    public static function from(mixed $data, bool $beta = false): self
    {
        if (!is_array($data) || ($data['draft'] ?? null) !== false
            || !is_string($data['tag_name'] ?? null) || preg_match(self::TAG_PATTERN, $data['tag_name'], $match) !== 1
            || ($data['prerelease'] ?? null) !== str_contains($match[1], '-beta.') || (!$beta && $data['prerelease'])
            || !is_array($data['assets'] ?? null)) {
            throw new \InvalidArgumentException('Invalid core release');
        }
        $url = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . $data['tag_name'] . '/' . self::ASSET;
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== self::ASSET) {
                continue;
            }
            if (($asset['state'] ?? null) !== 'uploaded' || ($asset['browser_download_url'] ?? null) !== $url
                || !is_int($asset['size'] ?? null) || $asset['size'] < 1 || $asset['size'] > Package::LIMIT
                || !is_string($asset['digest'] ?? null) || preg_match('/^sha256:([a-f0-9]{64})$/D', $asset['digest'], $hash) !== 1) {
                throw new \InvalidArgumentException('Invalid core release asset');
            }
            return new self($match[1], $url, $hash[1], $asset['size']);
        }
        throw new \InvalidArgumentException('Core release has no update package');
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->version . ':' . $this->sha256 . ':' . $this->size);
    }

    public function newer(): bool
    {
        return version_compare($this->version, \WeewxPhp\Version::STRING, '>');
    }
}
