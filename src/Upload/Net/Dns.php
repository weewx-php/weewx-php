<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

final class Dns
{
    public static function missing(string $message): bool
    {
        return preg_match('/Name or service not known|nodename nor servname provided|No such host|NXDOMAIN/i', $message) === 1;
    }
}
