<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * One archive record, readable in any unit.
 *
 * Every one of these services specifies its units and none of them agree:
 * Weather Underground wants Fahrenheit and inches of mercury, Windy Celsius
 * and metres per second, APRS Fahrenheit and hundredths of an inch. The
 * archive holds whatever the station wrote. So the conversion happens here,
 * once, against the same table everything else uses, rather than in each
 * service with its own quiet arithmetic.
 *
 * A reading that is not there comes back as null, never as a zero. A
 * station with no rain gauge that posts `rain=0` every five minutes is
 * indistinguishable from one in a drought, and the service keeps it forever.
 */
final class Readings
{
    private readonly UnitSystem $system;

    /** @param array<string, mixed> $record */
    public function __construct(private readonly array $record)
    {
        $units = $record['usUnits'] ?? null;
        $this->system = (is_int($units) ? UnitSystem::tryFrom($units) : null) ?? UnitSystem::US;
    }

    public function timestamp(): int
    {
        $value = $this->record['dateTime'] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_float($value) ? (int) $value : 0;
    }

    public function system(): UnitSystem
    {
        return $this->system;
    }

    /** Whether the record holds a number for a reading. */
    public function has(string $obs): bool
    {
        $value = $this->record[$obs] ?? null;
        return is_int($value) || is_float($value);
    }

    /**
     * A reading, converted into a unit; null when the station did not
     * report it. Without a unit, as the archive holds it.
     */
    public function get(string $obs, ?string $unit = null): ?float
    {
        $value = $this->record[$obs] ?? null;
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;
        if ($unit === null) {
            return $value;
        }
        [$stored] = Units::unitOf($this->system, $obs);
        if ($stored === null || $stored === $unit) {
            return $value;
        }
        $converted = Units::convert($value, $stored, $unit);
        return $converted === null ? null : (float) $converted;
    }

    /**
     * A reading in the unit system a service works in: WeeWX's `to_US`
     * applied to one field. A reading WeeWX knows no unit group for is
     * handed back as it is.
     */
    public function in(string $obs, UnitSystem $system): ?float
    {
        [$stored, $group] = Units::unitOf($this->system, $obs);
        if ($stored === null || $group === null) {
            return $this->get($obs);
        }
        return $this->get($obs, Units::standardUnit($system, $group));
    }

    /**
     * A reading as the text that goes in a query, or null.
     *
     * `$format` is a printf conversion, not a number of decimals, because
     * the width is part of these protocols: Weather Underground writes
     * humidity as `061` and WeeWX writes it with `%03.0f`. Zero-padding
     * looks like decoration and is not; it is what the field is defined
     * as. PHP's `sprintf` rounds a tie to even on the value itself, the way
     * C's and Python's do, so the digits are WeeWX's digits.
     */
    public function text(string $obs, ?string $unit, string $format): ?string
    {
        $value = $this->get($obs, $unit);
        return $value === null ? null : sprintf($format, $value);
    }
}
