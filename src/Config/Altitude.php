<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use InvalidArgumentException;
use WeewxPhp\Weewx\Units;

/**
 * A height above sea level as it was written down: the number and its
 * unit, kept apart. WeeWX keeps its station altitude the same way and
 * converts on use, so a value given in feet reaches the US formulas as
 * the very number that was configured, not that number through metres
 * and back.
 */
final class Altitude
{
    public const UNITS = ['meter', 'foot'];

    public function __construct(
        public readonly float $value,
        public readonly string $unit,
    ) {
        if (!in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException(sprintf('an altitude is in meter or foot, not %s', $unit));
        }
    }

    /** The height in a unit, converted the way WeeWX converts it; the same unit hands the number back untouched. */
    public function in(string $unit): float
    {
        $converted = Units::convert($this->value, $this->unit, $unit);
        return $converted === null ? $this->value : (float) $converted;
    }
}
