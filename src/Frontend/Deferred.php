<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use RuntimeException;

/** A read exhausted its budget. The same work can continue in the background. */
final class Deferred extends RuntimeException {}
