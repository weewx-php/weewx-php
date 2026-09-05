<?php

declare(strict_types=1);

namespace WeewxPhp\Log;

/**
 * The four convenience methods of {@see Logger}, expressed through log().
 */
trait LogLevelMethods
{
    abstract public function log(LogLevel $level, string $message): void;

    public function debug(string $message): void
    {
        $this->log(LogLevel::Debug, $message);
    }

    public function info(string $message): void
    {
        $this->log(LogLevel::Info, $message);
    }

    public function warning(string $message): void
    {
        $this->log(LogLevel::Warning, $message);
    }

    public function error(string $message): void
    {
        $this->log(LogLevel::Error, $message);
    }
}
