<?php

declare(strict_types=1);

namespace WeewxPhp\Live;

/** What a packet is: a LOOP reading, or an archive record the console made itself. */
enum PacketKind: string
{
    case Loop = 'loop';
    case Archive = 'archive';
}
