<?php

declare(strict_types=1);

namespace WeewxPhp\State;

/** How a station's silence is judged against the interval it announced. */
enum StationStatus: string
{
    /** Heard within the expected interval, or no interval to judge by. */
    case Ok = 'ok';

    /** Overdue by a few intervals. */
    case Stale = 'stale';

    /** Overdue by many. */
    case Down = 'down';

    /** Never heard. Not silent: a station nobody has connected yet. */
    case Unknown = 'unknown';
}
