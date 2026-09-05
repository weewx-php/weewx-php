<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Db\Sqlite;

/** Bounded reception metadata; no uploaded credentials or measurement history. */
final class Diagnostics
{
    public function __construct(
        public readonly int $since,
        public readonly int $received,
        public readonly int $stored,
        public readonly int $duplicates,
        public readonly int $discarded,
        public readonly int $pending,
        public readonly ?int $lastReceived,
        public readonly ?int $lastValid,
        public readonly ?int $lastInterval,
        public readonly ?int $clockOffset,
        public readonly ?string $timeSource,
        public readonly ?string $timeReason,
        public readonly ?string $lastError,
        public readonly ?int $lastErrorAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) Sqlite::text($row['since']),
            (int) Sqlite::text($row['received']),
            (int) Sqlite::text($row['stored']),
            (int) Sqlite::text($row['duplicates']),
            (int) Sqlite::text($row['discarded']),
            (int) Sqlite::text($row['pending']),
            self::integer($row['last_received']),
            self::integer($row['last_valid']),
            self::integer($row['last_interval']),
            self::integer($row['clock_offset']),
            $row['time_source'] === null ? null : Sqlite::text($row['time_source']),
            $row['time_reason'] === null ? null : Sqlite::text($row['time_reason']),
            $row['last_error'] === null ? null : Sqlite::text($row['last_error']),
            self::integer($row['last_error_at']),
        );
    }

    private static function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) Sqlite::text($value);
    }
}
