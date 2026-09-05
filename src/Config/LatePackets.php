<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/** What to do with a packet that arrives after its interval was archived. */
enum LatePackets: string
{
    /** Leave the record as it is; the packet stays in the journal. */
    case Ignore = 'ignore';

    /** Build the interval again and replace the record. */
    case Rebuild = 'rebuild';
}
