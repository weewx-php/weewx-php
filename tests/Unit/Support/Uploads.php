<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Trigger;

/** An upload's configuration for a test, with the kind's defaults filled in the way the reader fills them. */
final class Uploads
{
    private function __construct() {}

    /** @param array<string, string|int|float|bool|list<string>|null> $options */
    public static function config(
        Kind $kind,
        array $options = [],
        string $id = 'test',
        string $archive = 'kirchdorf',
        ?Trigger $trigger = null,
        ?int $every = null,
        ?int $catchUp = null,
        int $timeout = 10,
        ?int $stale = null,
    ): UploadConfig {
        $filled = [];
        foreach ($kind->spec() as $key => $spec) {
            $filled[$key] = array_key_exists($key, $options) ? $options[$key] : $spec->default;
        }
        return new UploadConfig(
            $id,
            $kind,
            $archive,
            $trigger ?? $kind->defaultTrigger(),
            $every ?? $kind->defaultEvery(),
            $catchUp ?? $kind->defaultCatchUp(),
            $timeout,
            $stale ?? $kind->defaultStale(),
            $filled,
        );
    }

    /**
     * A record in METRICWX with the readings the services ask for.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function metricRecord(array $changes = []): array
    {
        // 2026-08-26 08:50:00 UTC.
        return array_replace([
            'dateTime' => 1_787_734_200, 'usUnits' => 17, 'interval' => 5,
            'outTemp' => 20.0, 'outHumidity' => 61.0, 'dewpoint' => 12.2, 'barometer' => 1013.25, 'altimeter' => 1013.6,
            'windSpeed' => 5.0, 'windDir' => 180.0, 'windGust' => 8.0, 'windGustDir' => 190.0,
            'rain' => 0.2, 'hourRain' => 0.4, 'rain24' => 1.2, 'dayRain' => 1.2, 'rainRate' => 0.6,
            'radiation' => 300.5, 'UV' => 4.0, 'inTemp' => 22.0, 'inHumidity' => 44.0,
        ], $changes);
    }
}
