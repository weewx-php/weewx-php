<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use RuntimeException;

/**
 * An upload that cannot be built as configured: a setting missing or
 * contradictory, a place without coordinates for a service that is a
 * position report. Distinct from {@see Rejected}, which is the service
 * saying no.
 */
final class UploadError extends RuntimeException {}
