<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/** How another accumulator's extremes are folded in: WeeWX's `merger` nickname. */
enum Merger: string
{
    case MinMax = 'minmax';
    case Avg = 'avg';
}
