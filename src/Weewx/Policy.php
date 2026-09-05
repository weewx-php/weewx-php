<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * How each observation type is aggregated.
 *
 * WeeWX keeps this in a global that modules change at import time
 * (`weewx.accum.accum_dict`). Here it is an object that is passed around,
 * so two of them can exist at once -- which is what lets a test hold the
 * defaults beside an installation's overrides.
 *
 * The defaults are WeeWX 5.5's `DEFAULTS_INI` verbatim. Changing one changes
 * recorded history, so they are transcribed, not reasoned about.
 */
final class Policy
{
    /** @var array<string, ObsPolicy> */
    private array $types;

    /**
     * @param array<string, Extractor> $extractors Observation types whose
     *     extractor differs from the default: the `[[[extractors]]]` section,
     *     or WeeWX's `[Accumulator]`.
     * @param array<string, string> $kinds Additional measurement semantics.
     */
    public function __construct(array $extractors = [], array $kinds = [])
    {
        $this->types = self::defaults();
        foreach ($kinds as $name => $kind) {
            if (\WeewxPhp\Measurement\Catalog::lastKind($kind)) {
                $this->types[$name] = new ObsPolicy(extractor: Extractor::Last);
            }
        }
        foreach ($extractors as $obsType => $extractor) {
            $this->types[$obsType] = ($this->types[$obsType] ?? new ObsPolicy())->withExtractor($extractor);
        }
    }

    public function of(string $obsType): ObsPolicy
    {
        if (isset($this->types[$obsType])) {
            return $this->types[$obsType];
        }
        return new ObsPolicy(extractor: \WeewxPhp\Measurement\Catalog::lastKind(\WeewxPhp\Measurement\Catalog::extensionKind($obsType))
            || in_array($obsType, ['weekRain', 'eventRain', 'lightning_last_time'], true) ? Extractor::Last : Extractor::Avg);
    }

    /** @return array<string, ObsPolicy> */
    private static function defaults(): array
    {
        $last = new ObsPolicy(extractor: Extractor::Last);
        $sum = new ObsPolicy(extractor: Extractor::Sum);
        $noop = new ObsPolicy(extractor: Extractor::Noop);
        return [
            'consBatteryVoltage' => $last,
            'dateTime' => new ObsPolicy(adder: Adder::Noop),
            'dayET' => $last,
            'dayRain' => $last,
            'ET' => $sum,
            'hourRain' => $last,
            'rain' => $sum,
            'rain24' => $last,
            'monthET' => $last,
            'monthRain' => $last,
            'stormRain' => $last,
            'totalRain' => $last,
            'txBatteryStatus' => $last,
            'usUnits' => new ObsPolicy(adder: Adder::CheckUnits),
            'wind' => new ObsPolicy(StatsKind::Vector, Adder::Add, Merger::MinMax, Extractor::Wind),
            'windDir' => $noop,
            'windGust' => $noop,
            'windGustDir' => $noop,
            'windGust10' => $last,
            'windGustDir10' => $last,
            'windrun' => $sum,
            'windSpeed' => new ObsPolicy(StatsKind::Scalar, Adder::AddWind, Merger::Avg, Extractor::Noop),
            'windSpeed2' => $last,
            'windSpeed10' => $last,
            'yearET' => $last,
            'yearRain' => $last,
            'lightning_strike_count' => $sum,
        ];
    }
}
