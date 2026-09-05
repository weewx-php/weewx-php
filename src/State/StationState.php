<?php

declare(strict_types=1);

namespace WeewxPhp\State;

/** What the application remembers about one station between ticks. */
final class StationState
{
    public function __construct(
        public readonly string $id,
        public readonly ?int $lastSeen,
        public readonly StationStatus $status,
        public readonly ?int $statusSince,
    ) {}
}
