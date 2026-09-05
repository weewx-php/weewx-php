<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * One sender, as `[Stations]` announces it. The id is what packets carry in
 * their `sender` column and what archives select on; everything else here
 * serves the display and the watch on the station's silence.
 */
final class StationConfig
{
    /**
     * @param int|null $expectedInterval Seconds between two packets, or null when nobody said
     *     and the station's silence is therefore not judged.
     * @param int $staleAfter After how many expected intervals of silence the station is stale.
     * @param int $downAfter After how many it is down.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?int $expectedInterval,
        public readonly int $staleAfter,
        public readonly int $downAfter,
    ) {}
}
