<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

/** The HTTP client this host can offer. */
final class Http
{
    private function __construct() {}

    public static function client(): HttpClient
    {
        return CurlClient::available() ? new CurlClient() : new StreamClient();
    }

    /**
     * A query string from fields, leaving out anything that is null.
     * Leaving out is the point: every one of these services treats a
     * parameter that is present and empty differently from one that is
     * absent, and several record the empty one as a zero.
     *
     * @param array<string, string|int|float|null> $fields In the order they should appear.
     */
    public static function query(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = rawurlencode($name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $parts);
    }
}
