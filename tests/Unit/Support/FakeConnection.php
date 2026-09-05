<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use WeewxPhp\Upload\Net\Connection;
use WeewxPhp\Upload\Rejected;

/**
 * A socket that answers from a script: whatever bytes were queued come back
 * on reading, in order, and everything written is kept for looking at.
 */
final class FakeConnection implements Connection
{
    public string $written = '';

    public bool $closed = false;

    private string $incoming;

    public function __construct(string $incoming = '')
    {
        $this->incoming = $incoming;
    }

    public function write(string $bytes): void
    {
        if ($this->closed) {
            throw new Rejected('fake: written after close');
        }
        $this->written .= $bytes;
    }

    public function read(int $length): string
    {
        if (strlen($this->incoming) < $length) {
            $this->closed = true;
            throw new Rejected('fake: the connection closed');
        }
        $chunk = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);
        return $chunk;
    }

    public function readSome(int $max): string
    {
        $chunk = substr($this->incoming, 0, $max);
        $this->incoming = substr($this->incoming, strlen($chunk));
        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
