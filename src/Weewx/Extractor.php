<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/** How a record value is taken out of the accumulator: WeeWX's `extractor` nickname. */
enum Extractor: string
{
    case Avg = 'avg';
    case Sum = 'sum';
    case First = 'first';
    case Last = 'last';
    case Min = 'min';
    case Max = 'max';
    case Count = 'count';
    case Wind = 'wind';
    case Noop = 'noop';
}
