<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use RuntimeException;

/** Messages are fixed reason codes, never fragments of an upload. */
final class Rejected extends RuntimeException
{
    public function __construct(string $reason, public readonly int $status = 400)
    {
        parent::__construct($reason);
    }
}
