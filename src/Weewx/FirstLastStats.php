<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * The minimal statistics: the first and the last value seen, with their
 * times. Suitable for strings, which is why it does no arithmetic at all,
 * and why nothing it holds survives a round through the database.
 */
class FirstLastStats implements Stats
{
    public mixed $first = null;
    public int|float|null $firsttime = null;
    public mixed $last = null;
    public int|float|null $lasttime = null;

    public function setStats(?array $tuple): void
    {
        // Nothing here is stored, so there is nothing to load.
    }

    public function statsTuple(): array
    {
        return [null, null, null, null, 0.0, 0, 0.0, 0];
    }

    public function mergeHilo(Stats $other): void
    {
        if (!$other instanceof self) {
            return;
        }
        if ($other->firsttime !== null && ($this->firsttime === null || $other->firsttime < $this->firsttime)) {
            $this->firsttime = $other->firsttime;
            $this->first = $other->first;
        }
        if ($other->lasttime !== null && ($this->lasttime === null || $other->lasttime >= $this->lasttime)) {
            $this->lasttime = $other->lasttime;
            $this->last = $other->last;
        }
    }

    public function addHilo(mixed $value, int|float $timestamp): void
    {
        if ($value === null) {
            return;
        }
        if ($this->firsttime === null || $timestamp < $this->firsttime) {
            $this->first = $value;
            $this->firsttime = $timestamp;
        }
        if ($this->lasttime === null || $timestamp >= $this->lasttime) {
            $this->last = $value;
            $this->lasttime = $timestamp;
        }
    }

    public function addSum(mixed $value, int|float $weight = 1): void
    {
        // There is no sum to add to.
    }

    /**
     * WeeWX's `to_float`, then the checks its accumulators make: a value that
     * is missing, not a number, the string 'none', or NaN counts as absent.
     */
    public static function usable(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_bool($value)) {
            $number = $value ? 1.0 : 0.0;
        } elseif (is_string($value) && strtolower($value) !== 'none' && is_numeric($value)) {
            $number = (float) $value;
        } else {
            return null;
        }
        return is_nan($number) ? null : $number;
    }
}
