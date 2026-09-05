<?php

declare(strict_types=1);

namespace WeewxPhp\Measurement;

final class Source
{
    public function __construct(
        public readonly string $observation,
        public readonly string $unit,
        public readonly string $kind,
    ) {
        Catalog::validate($observation, $kind);
        Catalog::validateUnit($kind, $unit);
    }
}
