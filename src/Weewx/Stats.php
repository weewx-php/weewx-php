<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * What every kind of statistics can do. The three kinds -- first/last only,
 * scalar, vector -- differ in what they keep and in the shape of the value
 * they take, which is why the value is untyped here.
 */
interface Stats
{
    /**
     * Load statistics straight from storage, bypassing the arithmetic. With
     * null the statistics are merely brought into existence, empty.
     *
     * @param list<int|float|null>|null $tuple The columns of a daily summary row, without dateTime.
     */
    public function setStats(?array $tuple): void;

    /**
     * The statistics as a daily summary row holds them, without dateTime.
     *
     * @return list<int|float|null>
     */
    public function statsTuple(): array;

    /** Fold another accumulator's extremes into these. */
    public function mergeHilo(self $other): void;

    /** Take a value into the highs and lows. */
    public function addHilo(mixed $value, int|float $timestamp): void;

    /**
     * Take a value into the running sums. The weight is 1 for a LOOP packet
     * and 60 * interval for an archive record folded into a day.
     */
    public function addSum(mixed $value, int|float $weight = 1): void;
}
