<?php

declare(strict_types=1);

namespace WeewxPhp\Db;

use RuntimeException;

/** SQLite said no, or a file could not be opened. The message carries SQLite's own reason. */
final class DbError extends RuntimeException {}
