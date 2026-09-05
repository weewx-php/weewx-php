<?php

declare(strict_types=1);

namespace WeewxPhp\Cli;

use DateTimeImmutable;
use DateTimeZone;

/** Where a command's words go: two streams, so a test can hand it a buffer. */
final class Console
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out Standard output unless given.
     * @param resource|null $err Standard error unless given.
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }

    /** A timestamp as a person reads it, in a zone. */
    public static function when(?int $timestamp, DateTimeZone $zone): string
    {
        if ($timestamp === null) {
            return '-';
        }
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d H:i:s');
    }
}
