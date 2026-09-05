<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use InvalidArgumentException;

/**
 * The three unit systems WeeWX records in. The values are what the
 * `usUnits` column holds.
 */
enum UnitSystem: int
{
    case US = 1;
    case METRIC = 16;
    case METRICWX = 17;

    /**
     * From the name a configuration file uses, in any case.
     *
     * @throws InvalidArgumentException If it is none of the three.
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $system) {
            if (strcasecmp($system->name, $name) === 0) {
                return $system;
            }
        }
        throw new InvalidArgumentException(sprintf('Unknown unit system %s', var_export($name, true)));
    }

    /**
     * From a `usUnits` value.
     *
     * @throws InvalidArgumentException If it is none of the three.
     */
    public static function fromValue(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException(sprintf('Unknown unit system %d', $value));
    }
}
