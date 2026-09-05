<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Upload\Rejected;

/** One open socket, as CWOP and MQTT use it: bytes out, bytes in, close. */
interface Connection
{
    /** @throws Rejected When the far end has gone. */
    public function write(string $bytes): void;

    /**
     * Exactly `length` bytes, never fewer: a short read is normal for a
     * socket and is the bug that makes a hand-written protocol client work
     * in testing and fail under load, once.
     *
     * @throws Rejected When the connection closes or the timeout passes first.
     */
    public function read(int $length): string;

    /**
     * Whatever arrives, up to `max` bytes; empty when nothing did before
     * the timeout. For a banner nobody promised a length for.
     */
    public function readSome(int $max): string;

    public function close(): void;
}
