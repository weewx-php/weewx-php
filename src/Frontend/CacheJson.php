<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** Stable cache identities across web and CLI PHP configurations. */
final class CacheJson
{
    public static function encode(mixed $value, int $flags = 0): string
    {
        // Keep every float round-trippable without depending on the host's php.ini.
        $previous = ini_set('serialize_precision', '-1');
        if ($previous === false) {
            throw new QueryError('Cannot set cache serialization precision');
        }
        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }
}
