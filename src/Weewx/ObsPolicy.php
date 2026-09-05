<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/** What to do with one observation type. */
final class ObsPolicy
{
    public function __construct(
        public readonly StatsKind $accumulator = StatsKind::Scalar,
        public readonly Adder $adder = Adder::Add,
        public readonly Merger $merger = Merger::MinMax,
        public readonly Extractor $extractor = Extractor::Avg,
    ) {}

    public function withExtractor(Extractor $extractor): self
    {
        return new self($this->accumulator, $this->adder, $this->merger, $extractor);
    }
}
