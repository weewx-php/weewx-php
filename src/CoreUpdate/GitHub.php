<?php

declare(strict_types=1);

namespace WeewxPhp\CoreUpdate;

use WeewxPhp\Extension\Files;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;

final class GitHub
{
    public function __construct(private readonly Files $store, private readonly HttpClient $http, private readonly string $channel = 'stable')
    {
        if (!in_array($channel, ['stable', 'beta'], true)) {
            throw new \InvalidArgumentException('Unknown core update channel');
        }
    }

    public function checked(): bool
    {
        return is_file($this->store->root . '/latest-' . $this->channel . '.json');
    }

    public function cached(): ?Release
    {
        $json = Files::read($this->store->root . '/latest-' . $this->channel . '.json', 524288);
        if ($json === null) {
            return null;
        }
        try {
            return Release::from(json_decode($json, true, 32, JSON_THROW_ON_ERROR), $this->channel === 'beta');
        } catch (\Throwable) {
            return null;
        }
    }

    public function check(): ?Release
    {
        $url = $this->channel === 'beta' ? substr(Release::API, 0, -7) . '?per_page=100' : Release::API;
        $response = $this->http->send(new HttpRequest('GET', $url, headers: ['Accept' => 'application/vnd.github+json'], timeout: 5, maxResponseBytes: 2097152));
        if (!in_array($response->status, [200, 404], true)) {
            throw new \RuntimeException('Cannot check core releases');
        }
        $data = $response->status === 404 ? null : json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        if ($this->channel === 'beta' && $data !== null) {
            if (!is_array($data) || !array_is_list($data)) {
                throw new \RuntimeException('Invalid core release list');
            }
            $selected = null;
            $version = '0.0.0';
            foreach ($data as $row) {
                if (is_array($row) && ($row['draft'] ?? null) === false && is_string($row['tag_name'] ?? null)
                    && preg_match(Release::TAG_PATTERN, $row['tag_name'], $match) === 1
                    && ($row['prerelease'] ?? null) === str_contains($match[1], '-beta.') && version_compare($match[1], $version, '>')) {
                    $selected = $row;
                    $version = $match[1];
                }
            }
            $data = $selected;
        }
        $release = $data === null ? null : Release::from($data, $this->channel === 'beta');
        $this->store->directory();
        Files::write($this->store->root . '/latest-' . $this->channel . '.json', json_encode($data, JSON_THROW_ON_ERROR));
        return $release;
    }

    public function download(Release $release): Package
    {
        $url = $release->url;
        for ($redirects = 0; $redirects < 4; ++$redirects) {
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
                || !in_array($parts['host'] ?? '', ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) {
                throw new \RuntimeException('Unsafe core download redirect');
            }
            $response = $this->http->send(new HttpRequest('GET', $url, timeout: 10, maxResponseBytes: Package::LIMIT));
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $url = $response->headers['location'] ?? '';
                continue;
            }
            if ($response->status !== 200 || strlen($response->body) !== $release->size || !hash_equals($release->sha256, hash('sha256', $response->body))) {
                throw new \RuntimeException('Core package checksum mismatch');
            }
            return Package::parse($response->body, $release->version);
        }
        throw new \RuntimeException('Too many core download redirects');
    }
}
