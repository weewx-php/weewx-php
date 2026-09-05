<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use stdClass;

/** Receiver-scoped physical sensor identity. Values never select a file, class or credential. */
final class SensorSource
{
    public const MODULES = [
        'rtl_433' => 'weewx_php_ingest.sdr',
        'gw1000' => 'weewx_php_ingest.gw1000',
        'weatherflow_udp' => 'weewx_php_ingest.weatherflow',
    ];
    /** @return array<string, string> */
    public static function parse(mixed $value): array
    {
        if (!$value instanceof stdClass) {
            throw new Rejected('invalid_sensor_source');
        }
        $keys = ['type', 'receiver_id', 'model', 'sensor_id', 'channel'];
        if (count(get_object_vars($value)) !== count($keys)
            || array_diff(array_keys(get_object_vars($value)), $keys) !== []
            || !is_string($value->type ?? null) || !isset(self::MODULES[$value->type])) {
            throw new Rejected('invalid_sensor_source');
        }
        $result = ['type' => $value->type, 'receiver_id' => NativeParser::uuid($value->receiver_id ?? null)];
        foreach (['model', 'sensor_id', 'channel'] as $key) {
            $text = $value->$key ?? null;
            if (!is_string($text) || ($text === '' ? $key !== 'channel' : preg_match('/^[\x20-\x7e]{1,64}$/D', $text) !== 1)) {
                throw new Rejected('invalid_sensor_source');
            }
            $result[$key] = $text;
        }
        return $result;
    }

    /** @param array<string, string> $source */
    public static function stationId(array $source): string
    {
        $namespace = hex2bin(str_replace('-', '', $source['receiver_id']));
        $name = $source['type'] . ':' . json_encode([$source['model'], $source['sensor_id'], $source['channel']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $bytes = sha1($namespace . $name, true);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 80);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex(substr($bytes, 0, 16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /** @param array<string, string> $source */
    public static function label(array $source): string
    {
        return substr($source['model'] . ' ' . $source['sensor_id'] . ($source['channel'] === '' ? '' : ' / ' . $source['channel']), 0, 160);
    }
}
