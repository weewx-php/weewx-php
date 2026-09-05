<?php

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
     * @param int $timeout Seconds to connect, and for each read afterwards.
     *
     * @throws Rejected When no connection could be made: permanent for a name that does not resolve.
     */
    public function open(string $host, int $port, bool $tls, bool $verify, int $timeout): Connection;
}
