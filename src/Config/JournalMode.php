<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * SQLite's journal mode for the databases this application opens. WAL lets
 * a reader and the tick share a file without waiting on each other; on a
 * network file system it is unsafe, and `delete` is the way out.
 */
enum JournalMode: string
{
    case Wal = 'wal';
    case Delete = 'delete';
}
