<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $token = null,
        public readonly ?string $location = null,
        public readonly string $contentType = 'text/html; charset=utf-8',
    ) {}
}
