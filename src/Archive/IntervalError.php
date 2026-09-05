<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use RuntimeException;

/**
 * A record whose `interval` cannot be turned into a weight. WeeWX logs it,
 * keeps the record, and leaves it out of the daily summaries.
 */
final class IntervalError extends RuntimeException {}
