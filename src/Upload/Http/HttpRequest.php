<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

/** One request to make: what every client here takes and every test can look at. */
final class HttpRequest
{
    /**
     * @param string $method `GET` or `POST`.
     * @param string|null $body What goes with a POST, already encoded.
     * @param array<string, string> $headers By name.
     * @param int $timeout Seconds the whole exchange may take.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly int $timeout = 10,
    ) {}

    /** The same request with another timeout. */
    public function withTimeout(int $timeout): self
    {
        return new self($this->method, $this->url, $this->body, $this->headers, $timeout);
    }

    /** The URL with its query string's secrets masked, for a log line. */
    public function masked(): string
    {
        return preg_replace('/((?:PASSWORD|siteAuthenticationKey|key|token|p)=)[^&]*/i', '$1***', $this->url) ?? $this->url;
    }
}
