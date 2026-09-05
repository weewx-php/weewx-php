<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use RuntimeException;

/** The archive cannot be written with the mapping as configured. The message says what to change. */
final class MappingError extends RuntimeException {}
