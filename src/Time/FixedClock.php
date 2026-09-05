<?php

declare(strict_types=1);

namespace WeewxPhp\Time;

/**
 * A clock a test sets by hand. Time passes only when advance() says so.
 */
final class FixedClock implements Clock
{
    private float $elapsed = 0.0;

    public function __construct(private int $now) {}

    public function now(): int
    {
        return $this->now;
    }

    public function monotonic(): float
    {
        return $this->elapsed;
    }

    /** Move both the wall clock and the monotonic clock forward. */
    public function advance(float $seconds): void
    {
        $this->now += (int) floor($seconds);
        $this->elapsed += $seconds;
    }

    /** Put the wall clock at a moment, forward or back; the monotonic clock only ever moves forward. */
    public function set(int $now): void
    {
        $this->elapsed += (float) max(0, $now - $this->now);
        $this->now = $now;
    }
}
