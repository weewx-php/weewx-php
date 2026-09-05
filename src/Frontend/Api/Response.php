<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend\Api;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly string $body = '') {}

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
