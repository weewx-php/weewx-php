<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Db\Sqlite;

final class Sender
{
    public function __construct(
        public readonly string $id,
        public readonly Protocol $protocol,
        public readonly string $identity,
        public readonly string $name,
        public readonly string $state,
        public readonly string $model,
        public readonly string $peer,
        public readonly int $firstSeen,
        public readonly int $lastSeen,
        public readonly int $received,
        public readonly int $stored,
        public readonly string $transport,
        public readonly ?string $sample,
        public readonly ?int $adoptedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            Sqlite::text($row['id']),
            Protocol::from(Sqlite::text($row['protocol'])),
            Sqlite::text($row['identity']),
            Sqlite::text($row['name']),
            Sqlite::text($row['state']),
            Sqlite::text($row['model']),
            Sqlite::text($row['peer']),
            (int) Sqlite::text($row['first_seen']),
            (int) Sqlite::text($row['last_seen']),
            (int) Sqlite::text($row['received']),
            (int) Sqlite::text($row['stored']),
            Sqlite::text($row['transport']),
            $row['sample'] === null ? null : Sqlite::text($row['sample']),
            $row['adopted_at'] === null ? null : (int) Sqlite::text($row['adopted_at']),
        );
    }
}
