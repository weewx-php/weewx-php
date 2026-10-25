<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Upload\Rejected;

/** Where a socket comes from: the network, or a test's script. */
interface SocketFactory
{
    /**
     * @param bool $tls Whether to wrap the connection in TLS.
     * @param bool $verify Whether the far end's certificate has to check out; off for a broker
     *     on the local network with a self-signed one.
     * @param int|float $timeout Seconds for the connection, including opening, reads and writes.
     *
     * @throws Rejected When no connection could be made. Explicitly unknown hosts are permanent.
     */
    public function open(string $host, int $port, bool $tls, bool $verify, int|float $timeout): Connection;
}
