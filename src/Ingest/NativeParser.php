<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use JsonException;
use stdClass;
use WeewxPhp\Config\Settings;
use WeewxPhp\Weewx\UnitSystem;

/** Version 1 accepts individual WeeWX LOOP observations, never extracted averages. */
final class NativeParser
{
    public const MAX_BYTES = 262144;
    public const MAX_PACKETS = 128;
    public const MAX_FIELDS = 256;
    public const MAX_AGE = 7 * 86400;
    public const FUTURE_SKEW = 60;
    public const RECEIPT_RETENTION = 30 * 86400;

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new Rejected('invalid_id');
        }
        return $value;
    }

    /** Reserve two days of input for daily extrema and calculation run-up. */
    public static function maxAge(Settings $settings): int
    {
        return max(0, min(self::MAX_AGE, $settings->liveRetention - 2 * 86400));
    }

    /** @return array{collector: string, events: list<NativeEvent>} */
    public static function parse(string $body): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new Rejected('payload_too_large', 413);
        }
        try {
            $root = json_decode($body, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Rejected('invalid_json');
        }
        if (!$root instanceof stdClass) {
            throw new Rejected('invalid_envelope');
        }
        self::uniqueKeys($body);
        self::keys($root, ['version', 'collector_id', 'packets']);
        if (($root->version ?? null) !== 1) {
            throw new Rejected('unsupported_version');
        }
        $collector = self::uuid($root->collector_id ?? null);
        $packets = $root->packets ?? null;
        if (!is_array($packets) || !array_is_list($packets) || count($packets) < 1 || count($packets) > self::MAX_PACKETS) {
            throw new Rejected('invalid_packet_count');
        }
        $events = [];
        $seen = [];
        foreach ($packets as $packet) {
            $event = self::event($packet);
            if (isset($seen[$event->id])) {
                throw new Rejected('duplicate_event_id');
            }
            $seen[$event->id] = true;
            $events[] = $event;
        }
        return ['collector' => $collector, 'events' => $events];
    }

    private static function event(mixed $packet): NativeEvent
    {
        if (!$packet instanceof stdClass) {
            throw new Rejected('invalid_packet');
        }
        self::keys($packet, ['station_id', 'event_id', 'driver_module', 'kind', 'dateTime', 'usUnits', 'data']);
        $station = self::uuid($packet->station_id ?? null);
        $id = self::uuid($packet->event_id ?? null);
        if (($packet->kind ?? null) !== 'loop') {
            throw new Rejected('unsupported_kind');
        }
        $module = $packet->driver_module ?? null;
        if (!is_string($module) || strlen($module) > 160
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+$/D', $module) !== 1) {
            throw new Rejected('invalid_driver_module');
        }
        $timestamp = $packet->dateTime ?? null;
        if (!is_int($timestamp) || $timestamp < 1 || $timestamp > 253402300799) {
            throw new Rejected('invalid_timestamp');
        }
        $code = $packet->usUnits ?? null;
        $units = is_int($code) ? UnitSystem::tryFrom($code) : null;
        if ($units === null) {
            throw new Rejected('invalid_units');
        }
        $input = $packet->data ?? null;
        if (!$input instanceof stdClass || count(get_object_vars($input)) < 1 || count(get_object_vars($input)) > self::MAX_FIELDS) {
            throw new Rejected('invalid_measurements');
        }
        $data = [];
        foreach (get_object_vars($input) as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) !== 1
                || in_array(strtolower($name), ['datetime', 'usunits', 'interval', 'source', 'sender', 'identity', 'driver', 'kind'], true)) {
                throw new Rejected('invalid_field');
            }
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value))) {
                throw new Rejected('invalid_value');
            }
            $data[$name] = $value;
        }
        return new NativeEvent($station, $id, $module, $timestamp, $units, $data);
    }

    /** @param list<string> $allowed */
    private static function keys(stdClass $object, array $allowed): void
    {
        if (array_diff(array_keys(get_object_vars($object)), $allowed) !== []) {
            throw new Rejected('unknown_property');
        }
    }

    /** json_decode accepts duplicate members; v1 refuses that ambiguous representation. */
    private static function uniqueKeys(string $body): void
    {
        if (preg_match_all('/"(?:[^"\\\\]++|\\\\.)*"|[{}\[\]:,]/s', $body, $matches) === false) {
            throw new Rejected('invalid_json');
        }
        $tokens = $matches[0];
        $stack = [];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (($tokens[$index + 1] ?? '') === ':') {
                $key = json_decode($token, false, 2, JSON_THROW_ON_ERROR);
                if (!is_string($key)) {
                    throw new Rejected('invalid_json');
                }
                $level = count($stack) - 1;
                if (isset($stack[$level][$key])) {
                    throw new Rejected('duplicate_property');
                }
                $stack[$level][$key] = true;
            }
        }
    }
}
