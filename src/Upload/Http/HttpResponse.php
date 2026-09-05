<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

/** What came back: the status and the body, trimmed. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {}

    /** The first part of the body, for a message. */
    public function excerpt(int $length = 120): string
    {
        $text = trim($this->body);
        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }
}
