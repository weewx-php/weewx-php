<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Upload\Rejected;

/** A socket from `stream_socket_client`. */
final class StreamConnection implements Connection
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    public function __construct($stream, private readonly string $peer, private readonly ?float $deadline = null)
    {
        $this->stream = $stream;
    }

    public function write(string $bytes): void
    {
        $stream = $this->stream;
        if ($stream === null) {
            throw new Rejected(sprintf('%s: not connected', $this->peer));
        }
        $total = strlen($bytes);
        $sent = 0;
        while ($sent < $total) {
            $this->timeout($stream);
            $written = @fwrite($stream, substr($bytes, $sent));
            if ($written === false || $written === 0) {
                $this->close();
                throw new Rejected(sprintf('%s: the connection went away while sending', $this->peer));
            }
            $sent += $written;
        }
    }

    public function read(int $length): string
    {
        $stream = $this->stream;
        if ($stream === null) {
            throw new Rejected(sprintf('%s: not connected', $this->peer));
        }
        $chunks = '';
        while (strlen($chunks) < $length) {
            $this->timeout($stream);
            $chunk = @fread($stream, max(1, $length - strlen($chunks)));
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw new Rejected(sprintf('%s: the connection closed, or did not answer in time', $this->peer));
            }
            $chunks .= $chunk;
        }
        return $chunks;
    }

    public function readSome(int $max): string
    {
        $stream = $this->stream;
        if ($stream === null) {
            return '';
        }
        $this->timeout($stream);
        $chunk = @fread($stream, max(1, $max));
        return $chunk === false ? '' : $chunk;
    }

    public function close(): void
    {
        $stream = $this->stream;
        $this->stream = null;
        if ($stream !== null) {
            @fclose($stream);
        }
    }

    /** @param resource $stream */
    private function timeout($stream): void
    {
        if ($this->deadline === null) {
            return;
        }
        $left = $this->deadline - (hrtime(true) / 1_000_000_000);
        if ($left <= 0) {
            $this->close();
            throw new Rejected(sprintf('%s: connection deadline reached', $this->peer));
        }
        $seconds = (int) floor($left);
        stream_set_timeout($stream, $seconds, max(1, (int) (($left - $seconds) * 1_000_000)));
    }
}
