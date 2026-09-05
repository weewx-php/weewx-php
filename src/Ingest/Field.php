<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** A known wire field, with its actual input unit and sensor range. */
final class Field
{
    public function __construct(
        public readonly string $observation,
        public readonly string $unit,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly bool $integer = false,
    ) {}

    public function value(float $value, UnitSystem $system): ?float
    {
        if (!is_finite($value) || ($this->min !== null && $value < $this->min)
            || ($this->max !== null && $value > $this->max) || ($this->integer && floor($value) !== $value)) {
            return null;
        }
        $target = Units::unitOf($system, $this->observation)[0];
        if ($target === null) {
            throw new \LogicException('Wire field has no standard unit: ' . $this->observation);
        }
        $result = (float) Units::convert($value, $this->unit, $target);
        return is_finite($result) ? $result : null;
    }
}
