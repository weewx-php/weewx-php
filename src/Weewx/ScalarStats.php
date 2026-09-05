<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * Statistics for a scalar observation: min, max and a weighted mean.
 *
 * `wsum` and `sumtime` are what make an average over an arbitrary period
 * possible later: the sum is weighted by how long each value stood, so
 * records at different archive intervals still average correctly.
 */
final class ScalarStats extends FirstLastStats
{
    public ?float $min = null;
    public int|float|null $mintime = null;
    public ?float $max = null;
    public int|float|null $maxtime = null;
    public float $sum = 0.0;
    public int $count = 0;
    public float $wsum = 0.0;
    public int|float $sumtime = 0;

    public function setStats(?array $tuple): void
    {
        [$this->min, $this->mintime, $this->max, $this->maxtime, $sum, $count, $wsum, $sumtime]
            = $tuple ?? [null, null, null, null, 0.0, 0, 0.0, 0];
        $this->sum = (float) $sum;
        $this->count = (int) $count;
        $this->wsum = (float) $wsum;
        $this->sumtime = $sumtime ?? 0;
    }

    public function statsTuple(): array
    {
        return [$this->min, $this->mintime, $this->max, $this->maxtime,
            $this->sum, $this->count, $this->wsum, $this->sumtime];
    }

    public function mergeHilo(Stats $other): void
    {
        parent::mergeHilo($other);
        if (!$other instanceof self) {
            return;
        }
        if ($other->min !== null && ($this->min === null || $other->min < $this->min)) {
            $this->min = $other->min;
            $this->mintime = $other->mintime;
        }
        if ($other->max !== null && ($this->max === null || $other->max > $this->max)) {
            $this->max = $other->max;
            $this->maxtime = $other->maxtime;
        }
    }

    public function addHilo(mixed $value, int|float $timestamp): void
    {
        parent::addHilo($value, $timestamp);
        $number = self::usable($value);
        if ($number === null) {
            return;
        }
        if ($this->min === null || $number < $this->min) {
            $this->min = $number;
            $this->mintime = $timestamp;
        }
        if ($this->max === null || $number > $this->max) {
            $this->max = $number;
            $this->maxtime = $timestamp;
        }
    }

    public function addSum(mixed $value, int|float $weight = 1): void
    {
        $number = self::usable($value);
        if ($number === null) {
            return;
        }
        $this->sum += $number;
        $this->count++;
        $this->wsum += $number * $weight;
        $this->sumtime += $weight;
    }

    public function avg(): ?float
    {
        return $this->count > 0 ? $this->wsum / $this->sumtime : null;
    }
}
