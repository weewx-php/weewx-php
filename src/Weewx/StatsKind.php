<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/** Which statistics an observation type keeps: WeeWX's `accumulator` nickname. */
enum StatsKind: string
{
    case Scalar = 'scalar';
    case Vector = 'vector';
    case FirstLast = 'firstlast';
}
