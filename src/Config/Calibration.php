<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/** A correction applied to one sender's reading before anything else sees it: `value * scale + offset`. */
final class Calibration
{
    public function __construct(
        public readonly float $offset,
        public readonly float $scale,
    ) {}
}
