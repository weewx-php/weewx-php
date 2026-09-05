<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use RuntimeException;

/** A database that is not a WeeWX archive, or one WeeWX has to repair first. */
final class SchemaError extends RuntimeException {}
