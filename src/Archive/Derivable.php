<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

/**
 * The readings this application can work out, and how WeeWX's default
 * configuration decides each.
 */
final class Derivable
{
    /**
     * In the order they are worked out, which is the order WeeWX's
     * default configuration lists them: `pressure` before `altimeter` and
     * `barometer`, which are computed from it. `rain` goes first because
     * the rain rate reads it.
     *
     * @var array<string, How>
     */
    public const DEFAULTS = [
        'rain' => How::PreferHardware,
        'pressure' => How::PreferHardware,
        'altimeter' => How::PreferHardware,
        'appTemp' => How::PreferHardware,
        'barometer' => How::PreferHardware,
        'cloudbase' => How::PreferHardware,
        'dewpoint' => How::PreferHardware,
        'ET' => How::PreferHardware,
        'heatindex' => How::PreferHardware,
        'humidex' => How::PreferHardware,
        'inDewpoint' => How::PreferHardware,
        'maxSolarRad' => How::PreferHardware,
        'rainRate' => How::PreferHardware,
        'windchill' => How::PreferHardware,
        'windrun' => How::PreferHardware,
        // Not a reading so much as a rule: a direction with no wind behind it
        // is cleared. WeeWX applies it in software whatever the hardware sent.
        'windDir' => How::Software,
        'windGustDir' => How::Software,
    ];

    private function __construct() {}

    public static function knows(string $name): bool
    {
        return array_key_exists($name, self::DEFAULTS);
    }

    /**
     * The defaults with an installation's choices laid over them, in the
     * defaults' order. A name the defaults do not know is left out: nothing
     * here could work it out.
     *
     * @param array<string, How> $overrides
     *
     * @return array<string, How>
     */
    public static function policy(array $overrides): array
    {
        $policy = self::DEFAULTS;
        foreach ($overrides as $name => $how) {
            if (array_key_exists($name, $policy)) {
                $policy[$name] = $how;
            }
        }
        return $policy;
    }
}
