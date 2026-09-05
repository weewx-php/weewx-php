<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use RuntimeException;

/**
 * Anything wrong with a configuration file: a line that does not parse, a
 * value of the wrong kind, a key that is missing. The message names the
 * path and, for a parse error, the line.
 */
final class ConfigError extends RuntimeException
{
    public static function atLine(int $line, string $problem): self
    {
        return new self(sprintf('line %d: %s', $line, $problem));
    }

    public static function at(string $path, string $problem): self
    {
        return new self($path === '' ? $problem : sprintf('%s: %s', $path, $problem));
    }
}
