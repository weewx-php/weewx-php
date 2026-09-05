<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

/**
 * Where a derived reading comes from: WeeWX's `[StdWXCalculate]` directives.
 */
enum How: string
{
    /** The station's value if it sent one, otherwise ours. */
    case PreferHardware = 'prefer_hardware';

    /** Only the station's; never computed. */
    case Hardware = 'hardware';

    /** Always ours, even when the station sent something. */
    case Software = 'software';
}
