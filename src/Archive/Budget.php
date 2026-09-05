<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Time\Clock;

/**
 * How much one run may do: a deadline on the clock and a ceiling on the
 * intervals built. A tick on a web host has seconds, not a process; what
 * it cannot finish stays marked and the next tick takes it up.
 *
 * The deadline is shared by every archive of a tick, the count is not:
 * {@see share()} hands each archive the same clock and a fresh ceiling.
 */
final class Budget
{
    private int $intervals = 0;

    /**
     * @param float $deadline A moment on the clock's monotonic scale.
     * @param int $maxIntervals How many intervals may be built before this says no.
     */
    private function __construct(
        private readonly Clock $clock,
        private readonly float $deadline,
        private readonly int $maxIntervals,
    ) {}

    /** So many seconds from now, and so many intervals. */
    public static function of(Clock $clock, float $seconds, int $maxIntervals): self
    {
        return new self($clock, $clock->monotonic() + $seconds, $maxIntervals);
    }

    /** No limit at all: a command line that was asked to finish. */
    public static function unlimited(Clock $clock): self
    {
        return new self($clock, INF, PHP_INT_MAX);
    }

    /** The same deadline with its own count: one archive's share of a tick. */
    public function share(int $maxIntervals): self
    {
        return new self($this->clock, $this->deadline, $maxIntervals);
    }

    /** Whether one more interval may be built. */
    public function allows(): bool
    {
        return $this->intervals < $this->maxIntervals && $this->clock->monotonic() < $this->deadline;
    }

    /** Count one interval built. */
    public function spent(): void
    {
        ++$this->intervals;
    }

    public function intervals(): int
    {
        return $this->intervals;
    }

    /** Seconds to the deadline; negative once it has passed. */
    public function timeLeft(): float
    {
        return $this->deadline - $this->clock->monotonic();
    }
}
