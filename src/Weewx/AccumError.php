<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use RuntimeException;

/**
 * A record that cannot be accumulated: outside the span, or in a different
 * unit system from what is already in. Both are bugs in the caller, not
 * conditions to paper over: a mixed-unit average is a wrong number that
 * looks right.
 */
final class AccumError extends RuntimeException {}
