<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Config\ArchiveConfig;

/** Read context; workers receive their transports and budget separately. */
final class Context
{
    public function __construct(public readonly ArchiveConfig $archive, public readonly string $directory, public readonly int $now, public readonly \WeewxPhp\Config\Section $options = new \WeewxPhp\Config\Section('options', 3)) {}
}
