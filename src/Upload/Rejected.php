<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use RuntimeException;
use Throwable;

/**
 * The service answered, and said no; or could not be reached at all.
 *
 * Worth its own type because the answer decides what happens next. A
 * wrong password is permanent, and retrying it every five minutes for a
 * year is how an account gets blocked; a 503 is Tuesday.
 */
final class Rejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
