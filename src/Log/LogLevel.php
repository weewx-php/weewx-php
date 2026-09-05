<?php

declare(strict_types=1);

namespace WeewxPhp\Log;

enum LogLevel: int
{
    case Debug = 10;
    case Info = 20;
    case Warning = 30;
    case Error = 40;

    /**
     * The level a configuration file names.
     *
     * @param string $name One of 'debug', 'info', 'warning', 'error', in any case.
     *
     * @throws \InvalidArgumentException If the name is none of them.
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $level) {
            if (strcasecmp($level->name, $name) === 0) {
                return $level;
            }
        }
        throw new \InvalidArgumentException(sprintf('Unknown log level %s', var_export($name, true)));
    }

    /** The fixed-width label a log line starts with, e.g. 'WARN '. */
    public function label(): string
    {
        return match ($this) {
            self::Debug => 'DEBUG',
            self::Info => 'INFO ',
            self::Warning => 'WARN ',
            self::Error => 'ERROR',
        };
    }
}
