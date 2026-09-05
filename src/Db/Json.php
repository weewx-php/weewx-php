<?php

declare(strict_types=1);

namespace WeewxPhp\Db;

use JsonException;

/**
 * JSON columns read back with their shape checked. What this application
 * writes into them is an object keyed by name; a row holding anything else
 * was written by something else, and is reported rather than guessed at.
 */
final class Json
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws DbError When the text is not JSON, or not an object.
     */
    public static function object(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DbError('not JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new DbError('expected a JSON object, found ' . get_debug_type($decoded));
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }
        return $object;
    }
}
