<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\Settings;
use WeewxPhp\Db\Json;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;

/** Explicit, authenticated place searches against a fixed provider endpoint. */
final class Geocoding
{
    public function __construct(private readonly Settings $settings, private readonly HttpClient $http, private readonly int $now) {}

    /** @return array<string, mixed> */
    public function search(string $query, string $language): array
    {
        $query = trim($query);
        if (strlen($query) < 2 || strlen($query) > 160 || preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
            throw new Problem('error.location_query');
        }
        $language = preg_match('/^[a-z]{2}$/D', $language) === 1 ? $language : 'en';
        $key = hash('sha256', $language . ':' . $query);
        $db = Changes::store($this->settings);
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS admin_geocoding_cache(id TEXT PRIMARY KEY, body TEXT NOT NULL, queried INTEGER NOT NULL)');
            $cached = $db->one('SELECT body FROM admin_geocoding_cache WHERE id = ? AND queried > ? AND body != ?', [$key, $this->now - 86400, '']);
            if ($cached !== null && is_string($cached['body'])) {
                return Json::object($cached['body']);
            }
            $db->transaction(function () use ($db, $key): void {
                $last = $db->scalar('SELECT MAX(queried) FROM admin_geocoding_cache');
                if (is_int($last) && $last > $this->now - 2) {
                    throw new Problem('error.busy', status: 429);
                }
                $db->exec('INSERT OR REPLACE INTO admin_geocoding_cache VALUES (?, ?, ?)', [$key, '', $this->now]);
                $db->exec('DELETE FROM admin_geocoding_cache WHERE id NOT IN (SELECT id FROM admin_geocoding_cache ORDER BY queried DESC LIMIT 100)');
            });
            $response = $this->http->send(new HttpRequest('GET', 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
                'name' => $query, 'count' => 8, 'language' => $language, 'format' => 'json',
            ], '', '&', PHP_QUERY_RFC3986), timeout: 5, maxResponseBytes: 131072));
            if ($response->status !== 200) {
                throw new Problem('error.location_search');
            }
            $data = Json::object($response->body);
            $results = [];
            foreach (is_array($data['results'] ?? null) ? array_slice($data['results'], 0, 8) : [] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $lat = $row['latitude'] ?? null;
                $lon = $row['longitude'] ?? null;
                if (!self::number($lat, -90, 90) || !self::number($lon, -180, 180)) {
                    continue;
                }
                $label = [];
                foreach (['name', 'admin1', 'country'] as $field) {
                    if (is_string($row[$field] ?? null) && strlen($row[$field]) <= 160) {
                        $label[] = $row[$field];
                    }
                }
                $zone = $row['timezone'] ?? null;
                $results[] = ['name' => $row['name'], 'label' => implode(' · ', array_unique($label)), 'latitude' => $lat, 'longitude' => $lon,
                    'elevation' => self::number($row['elevation'] ?? null, -500, 10000) ? $row['elevation'] : null,
                    'timezone' => is_string($zone) && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : null];
            }
            $result = ['results' => $results];
            $db->exec('UPDATE admin_geocoding_cache SET body = ? WHERE id = ?', [json_encode($result, JSON_THROW_ON_ERROR), $key]);
            return $result;
        } finally {
            $db->close();
        }
    }

    private static function number(mixed $value, int $min, int $max): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $min && $value <= $max;
    }
}
