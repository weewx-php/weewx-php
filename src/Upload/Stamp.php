<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/**
 * Timestamps the way the services write them. UTC throughout: the
 * protocols say so, and reading a field as local time is a station whose
 * readings arrive an hour early twice a year.
 */
final class Stamp
{
    private function __construct() {}

    /** `2020-10-19 21:43:18`, the Ambient protocol's `dateutc` and Windy's. Not ISO 8601, no `T`, no zone. */
    public static function ambient(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** `20201019`, Weathercloud's `date`. */
    public static function day(int $timestamp): string
    {
        return gmdate('Ymd', $timestamp);
    }

    /** `21:43`, Weathercloud's `time`. */
    public static function minute(int $timestamp): string
    {
        return gmdate('H:i', $timestamp);
    }

    /** `/191843z`, the APRS day-hour-minute, zulu. */
    public static function aprs(int $timestamp): string
    {
        return gmdate('dHi', $timestamp) . 'z';
    }
}
