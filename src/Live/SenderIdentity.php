<?php

declare(strict_types=1);

namespace WeewxPhp\Live;

/** One sender the journal has heard from, with what the ingest noted about it. */
final class SenderIdentity
{
    /**
     * @param int $firstSeen When the sender was first heard, or 0 for one that never was.
     */
    public function __construct(
        public readonly string $sender,
        public readonly string $driver,
        public readonly string $identity,
        public readonly string $label,
        public readonly int $firstSeen,
    ) {}
}
