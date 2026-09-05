<?php

declare(strict_types=1);

namespace WeewxPhp\State;

/**
 * What the application remembers about one upload between ticks: above
 * all how far it has got, which is what lets a tick send what a service
 * missed rather than the current reading and a hole.
 */
final class UploadState
{
    /**
     * @param int $through The newest record timestamp the service accepted, or 0 for never.
     * @param int $failures How many runs in a row have failed.
     * @param string|null $blocked Why the service said no for good, while it is not retried.
     * @param int|null $announcedAt When the readings were last announced to Home Assistant.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $through,
        public readonly ?int $lastRunAt,
        public readonly ?int $lastSentAt,
        public readonly int $runs,
        public readonly int $sent,
        public readonly int $failures,
        public readonly ?string $blocked,
        public readonly ?int $blockedAt,
        public readonly ?string $lastSummary,
        public readonly ?int $announcedAt,
    ) {}
}
