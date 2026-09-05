<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

/** Optional notification around archive transactions, including historical corrections. */
interface Changes
{
    public function before(int $start, int $end): void;
    public function after(int $start, int $end): void;
}
