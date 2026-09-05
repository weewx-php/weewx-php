<?php

declare(strict_types=1);

namespace WeewxPhp\State;

/** What the application remembers about one archive between ticks. */
final class ArchiveState
{
    /**
     * @param int|null $dbCreatedAt When this application created the database file, or null for
     *     a file it found.
     * @param int|null $caughtUpAt When the journal was last walked in full for this archive.
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $createdByApp,
        public readonly ?int $dbCreatedAt,
        public readonly ?int $caughtUpAt,
        public readonly ?int $lastRunAt,
        public readonly ?int $lastRecordAt,
        public readonly int $recordsTotal,
        public readonly ?string $lastError,
        public readonly ?int $lastErrorAt,
    ) {}
}
