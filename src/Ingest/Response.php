<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = 'text/plain',
    ) {}

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        if ($this->status === 429) {
            header('Retry-After: 60');
        }
        echo $this->body;
    }
}
