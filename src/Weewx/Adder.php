<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/** How a value enters the accumulator: WeeWX's `adder` nickname. */
enum Adder: string
{
    case Add = 'add';
    case AddWind = 'add_wind';
    case CheckUnits = 'check_units';
    case Noop = 'noop';
}
