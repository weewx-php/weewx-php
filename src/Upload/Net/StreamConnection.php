<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Upload\Rejected;

/** A socket from `stream_socket_client`. */
final class StreamConnection implements Connection
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    public function __construct($stream, private readonly string $peer)
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
}
