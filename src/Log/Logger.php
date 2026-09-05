<?php

declare(strict_types=1);

namespace WeewxPhp\Log;

interface Logger
{
    public function log(LogLevel $level, string $message): void;

    public function debug(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;

    public function error(string $message): void;
}
