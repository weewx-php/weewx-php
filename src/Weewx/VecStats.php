<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * Statistics for wind: the scalar statistics plus the vector sums.
 *
 * `xsum` and `ysum` accumulate the east and north components, which is how
 * a mean direction survives averaging; adding degrees would not.
 * `dirsumtime` counts only the time a direction was known, so a stretch of
 * missing directions does not drag the mean towards north.
 *
 * A value is a 2-way tuple (speed, direction).
 */
final class VecStats implements Stats
{
    public ?float $min = null;
    public int|float|null $mintime = null;
    public ?float $max = null;
    public int|float|null $maxtime = null;
    public float $sum = 0.0;
    public int $count = 0;
    public float $wsum = 0.0;
    public int|float $sumtime = 0;
    public ?float $maxDir = null;
    public float $xsum = 0.0;
    public float $ysum = 0.0;
    public int|float $dirsumtime = 0;
    public float $squaresum = 0.0;
    public float $wsquaresum = 0.0;

    /** @var array{0: float|null, 1: float|null} The last (speed, direction) seen. */
    public array $last = [null, null];
    public int|float|null $lasttime = null;

    public function setStats(?array $tuple): void
    {
        [$this->min, $this->mintime, $this->max, $this->maxtime, $sum, $count, $wsum, $sumtime,
            $this->maxDir, $xsum, $ysum, $dirsumtime, $squaresum, $wsquaresum]
            = $tuple ?? [null, null, null, null, 0.0, 0, 0.0, 0, null, 0.0, 0.0, 0, 0.0, 0.0];
        $this->sum = (float) $sum;
        $this->count = (int) $count;
        $this->wsum = (float) $wsum;
        $this->sumtime = $sumtime ?? 0;
        $this->xsum = (float) $xsum;
        $this->ysum = (float) $ysum;
        $this->dirsumtime = $dirsumtime ?? 0;
        $this->squaresum = (float) $squaresum;
        $this->wsquaresum = (float) $wsquaresum;
    }

    public function statsTuple(): array
    {
        return [$this->min, $this->mintime, $this->max, $this->maxtime,
            $this->sum, $this->count, $this->wsum, $this->sumtime,
            $this->maxDir, $this->xsum, $this->ysum, $this->dirsumtime,
            $this->squaresum, $this->wsquaresum];
    }

    public function mergeHilo(Stats $other): void
    {
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
            $this->maxDir = $other->maxDir;
        }
        if ($other->lasttime !== null && ($this->lasttime === null || $other->lasttime >= $this->lasttime)) {
            $this->lasttime = $other->lasttime;
            $this->last = $other->last;
        }
    }

    /** @param mixed $value A 2-way tuple (speed, direction); anything else counts as no speed. */
    public function addHilo(mixed $value, int|float $timestamp): void
    {
        [$speed, $direction] = self::pair($value);
        if ($speed === null) {
            return;
        }
        if ($this->min === null || $speed < $this->min) {
            $this->min = $speed;
            $this->mintime = $timestamp;
        }
        if ($this->max === null || $speed > $this->max) {
            $this->max = $speed;
            $this->maxtime = $timestamp;
            $this->maxDir = $direction;
        }
        if ($this->lasttime === null || $timestamp >= $this->lasttime) {
            $this->last = [$speed, $direction];
            $this->lasttime = $timestamp;
        }
    }

    /** @param mixed $value A 2-way tuple (speed, direction); anything else counts as no speed. */
    public function addSum(mixed $value, int|float $weight = 1): void
    {
        [$speed, $direction] = self::pair($value);
        if ($speed === null) {
            return;
        }
        $this->sum += $speed;
        $this->count++;
        $this->wsum += $weight * $speed;
        $this->sumtime += $weight;
        $this->squaresum += $speed ** 2;
        $this->wsquaresum += $weight * $speed ** 2;
        if ($direction !== null) {
            $this->xsum += $weight * $speed * cos(Units::radians(90.0 - $direction));
            $this->ysum += $weight * $speed * sin(Units::radians(90.0 - $direction));
        }
        // A missing direction is fine as long as there was no wind to point.
        if ($direction !== null || $speed === 0.0) {
            $this->dirsumtime += $weight;
        }
    }

    public function avg(): ?float
    {
        return $this->count > 0 ? $this->wsum / $this->sumtime : null;
    }

    /**
     * @return array{0: float|null, 1: float|null} The usable speed and direction of a (speed, direction) tuple.
     */
    private static function pair(mixed $value): array
    {
        if (!is_array($value) || !array_key_exists(0, $value) || !array_key_exists(1, $value)) {
            return [null, null];
        }
        return [FirstLastStats::usable($value[0]), FirstLastStats::usable($value[1])];
    }

    public function rms(): ?float
    {
        return $this->count > 0 ? sqrt($this->wsquaresum / $this->sumtime) : null;
    }

    public function vecAvg(): ?float
    {
        return $this->count > 0 ? sqrt(($this->xsum ** 2 + $this->ysum ** 2) / $this->sumtime ** 2) : null;
    }

    /**
     * The mean direction, or the last one seen when the vector sum is zero:
     * with nothing to average there is no mean, and reporting north would
     * be a claim.
     */
    public function vecDir(): ?float
    {
        if ((float) $this->dirsumtime !== 0.0 && ($this->ysum !== 0.0 || $this->xsum !== 0.0)) {
            $result = 90.0 - Units::degrees(atan2($this->ysum, $this->xsum));
            if ($result < 0.0) {
                $result += 360.0;
            }
            return $result;
        }
        return $this->last[1];
    }
}
