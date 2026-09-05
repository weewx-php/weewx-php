<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * The range a reading has to be in to reach the archive: one line of
 * `[[[qc]]]`, as WeeWX's `[StdQC][[MinMax]]` writes it.
 */
final class QcRule
{
    /**
     * @param string|null $unit The unit the limits are written in, or null for the unit the
     *     section's `unit_system` implies for this observation.
     */
    public function __construct(
        public readonly string $obsType,
        public readonly float $minimum,
        public readonly float $maximum,
        public readonly ?string $unit,
    ) {}
}
