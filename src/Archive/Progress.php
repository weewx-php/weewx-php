<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

/** What a walk over the journal got done, and whether it got to the end. */
final class Progress
{
    /**
     * @param int $built How many records were written.
     * @param bool $finished Whether the walk reached the end of the journal; false when the
     *     budget ran out first.
     * @param int|null $stoppedBefore The interval end the walk did not get to, when it did not finish.
     */
    public function __construct(
        public readonly int $built,
        public readonly bool $finished,
        public readonly ?int $stoppedBefore,
    ) {}
}
