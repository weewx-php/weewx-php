<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use RuntimeException;

final class Problem extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = '', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
