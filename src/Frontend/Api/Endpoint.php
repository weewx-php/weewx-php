<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend\Api;

use Throwable;
use WeewxPhp\Frontend\UnitPreferences;

final class Endpoint
{
    /** @param array<string, Feed> $feeds */
    public function __construct(private readonly array $feeds) {}

    /** @param array<string, mixed> $query
     * @param array<string, string> $requestHeaders Lowercase names.
     */
    public function handle(string $method, array $query, array $requestHeaders = []): Response
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store', 'Vary' => 'Origin', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'"];
        $error = static fn(int $status, string $code): Response => new Response($status, $headers, $method === 'HEAD' ? '' : '{"version":1,"error":"' . $code . '"}');
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD, OPTIONS'], '{"version":1,"error":"method_not_allowed"}');
        }
        $id = $query['feed'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $id) !== 1 || array_diff(array_keys($query), ['feed', 'fields', 'units']) !== []) {
            return $error(400, 'invalid_query');
        }
        $profile = $query['units'] ?? null;
        if (array_key_exists('units', $query) && (!is_string($profile) || !isset(UnitPreferences::PROFILES[$profile]))) {
            return $error(400, 'invalid_units');
        }
        $units = is_string($profile) ? new UnitPreferences($profile) : null;
        $feed = $this->feeds[$id] ?? null;
        if ($feed === null) {
            return $error(404, 'unknown_feed');
        }
        $origin = $requestHeaders['origin'] ?? null;
        if ($origin !== null) {
            if (strlen($origin) > 256 || $origin === 'null' || (!in_array('*', $feed->origins, true) && !in_array($origin, $feed->origins, true))) {
                return $error(403, 'origin_denied');
            }
            $headers['Access-Control-Allow-Origin'] = in_array('*', $feed->origins, true) ? '*' : $origin;
            $headers['Access-Control-Expose-Headers'] = 'ETag';
        }
        // Errors for an allowed browser origin remain readable by that browser.
        $error = static fn(int $status, string $code): Response => new Response($status, $headers, $method === 'HEAD' ? '' : '{"version":1,"error":"' . $code . '"}');
        $fields = $feed->fields();
        if (isset($query['fields'])) {
            if (!is_string($query['fields']) || strlen($query['fields']) > 1600) {
                return $error(400, 'invalid_fields');
            }
            $requested = explode(',', $query['fields']);
            if (count($requested) > 32 || count(array_unique($requested)) !== count($requested) || array_diff($requested, $fields) !== []) {
                return $error(400, 'invalid_fields');
            }
            $fields = array_values(array_intersect($fields, $requested));
        }
        if ($method === 'OPTIONS') {
            if (!in_array($requestHeaders['access-control-request-method'] ?? 'GET', ['GET', 'HEAD'], true)) {
                return $error(405, 'method_not_allowed');
            }
            $asked = strtolower($requestHeaders['access-control-request-headers'] ?? '');
            if (strlen($asked) > 256 || array_diff(array_filter(array_map('trim', explode(',', $asked)), static fn(string $name): bool => $name !== ''), ['if-none-match']) !== []) {
                return $error(400, 'headers_not_allowed');
            }
            return new Response(204, $headers + ['Access-Control-Allow-Methods' => 'GET, HEAD',
                'Access-Control-Allow-Headers' => 'If-None-Match', 'Access-Control-Max-Age' => '600']);
        }
        try {
            $body = json_encode($feed->snapshot($id, $fields, $units), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (strlen($body) > 1048576) {
                throw new \RuntimeException('Feed exceeds 1 MiB');
            }
        } catch (Throwable $e) {
            error_log('Public feed ' . $id . ': ' . $e->getMessage());
            return $error(503, 'unavailable');
        }
        $etag = '"' . hash('sha256', $body) . '"';
        $headers['ETag'] = $etag;
        $headers['Cache-Control'] = 'public, max-age=0, must-revalidate';
        $condition = $requestHeaders['if-none-match'] ?? '';
        $matches = strlen($condition) <= 4096 && ($condition === '*' || in_array($etag, array_map(static fn(string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '', explode(',', $condition)), true));
        return new Response($matches ? 304 : 200, $headers, $matches || $method === 'HEAD' ? '' : $body);
    }
}
