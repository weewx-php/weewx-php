<?php

declare(strict_types=1);

namespace WeewxPhp\Time;

/**
 * Where the time comes from. Injected, so a test can decide what "now" is
 * and how long something took.
 */
interface Clock
{
    /** The current time as a unix timestamp. */
    public function now(): int;

    /**
     * Seconds from an arbitrary but fixed origin, unaffected by clock
     * changes. For measuring how long a run has been going.
     */
    public function monotonic(): float;
}
