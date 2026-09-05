<?php

declare(strict_types=1);

namespace WeewxPhp\Time;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }
}
