<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use WeewxPhp\Upload\Net\Connection;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Rejected;

/** Hands out the connections a test queued, and remembers where each was asked to go. */
final class FakeSocketFactory implements SocketFactory
{
    /** @var list<array{host: string, port: int, tls: bool, verify: bool, timeout: int}> */
    public array $opened = [];

    /** @var list<FakeConnection|Rejected> */
    private array $queue = [];

    public function queue(FakeConnection|Rejected $next): self
    {
        $this->queue[] = $next;
        return $this;
    }

    public function open(string $host, int $port, bool $tls, bool $verify, int $timeout): Connection
    {
        $this->opened[] = ['host' => $host, 'port' => $port, 'tls' => $tls, 'verify' => $verify, 'timeout' => $timeout];
        $next = array_shift($this->queue) ?? new Rejected(sprintf('fake: nothing listens on %s:%d', $host, $port));
        if ($next instanceof Rejected) {
            throw $next;
        }
        return $next;
    }
}
