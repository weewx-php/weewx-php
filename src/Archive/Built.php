<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Weewx\Accum;

/** One archive interval, worked out and not yet written. */
final class Built
{
    /**
     * @param array<string, mixed> $record The archive record, in the archive's unit system.
     * @param Accum $accumulator The interval's LOOP statistics, which sharpen the day's extremes.
     * @param int $packets How many LOOP packets went in.
     * @param bool $fromHardware Whether a console's own archive record is the basis.
     * @param array<string, int> $dropped Readings quality control refused, by name and count.
     */
    public function __construct(
        public readonly int $stop,
        public readonly int $seconds,
        public readonly array $record,
        public readonly Accum $accumulator,
        public readonly int $packets,
        public readonly bool $fromHardware,
        public readonly array $dropped,
    ) {}
}
