<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Upload\Rejected;

/**
 * Sockets from PHP's stream layer, which needs no extension: plain TCP,
 * or TLS through the `tls://` wrapper with OpenSSL behind it.
 */
final class StreamSocketFactory implements SocketFactory
{
    public function open(string $host, int $port, bool $tls, bool $verify, int $timeout): Connection
    {
        $deadline = (hrtime(true) / 1_000_000_000) + $timeout;
        $address = sprintf('%s://%s:%d', $tls ? 'tls' : 'tcp', $host, $port);
        $context = stream_context_create($tls ? ['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
        ]] : []);
        $errorCode = 0;
        $errorMessage = '';
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $reason = $errorMessage ?? '';
            if ($reason === '') {
                $reason = sprintf('error %d', $errorCode);
            }
            throw new Rejected(
                sprintf('could not reach %s:%d: %s', $host, $port, $reason),
                permanent: Dns::missing($reason),
            );
        }
        stream_set_timeout($stream, $timeout);
        return new StreamConnection($stream, sprintf('%s:%d', $host, $port), $deadline);
    }
}
