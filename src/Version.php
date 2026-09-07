<?php

declare(strict_types=1);

namespace WeewxPhp;

/** What this application calls itself to the services it posts to. */
final class Version
{
    public const STRING = '0.2.0-beta.1';

    /** What goes into an HTTP `User-Agent` and a CWOP login line. */
    public const NAME = 'weewx-php';

    public const USER_AGENT = self::NAME . '/' . self::STRING;

    private function __construct() {}
}
