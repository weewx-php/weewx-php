<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * One value out of a configuration file, with the conversions a setting
 * needs. A file holds strings and lists of strings and nothing else; what a
 * string means is decided here, once, with an error that names the setting.
 */
final class Value
{
    /**
     * @param string $path Where the value stands, for error messages,
     *     e.g. '[Archives][[kirchdorf]] archive_interval'.
     * @param string|list<string> $raw The value as the file holds it.
     */
    public function __construct(
        private readonly string $path,
        private readonly string|array $raw,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /** @return string|list<string> */
    public function raw(): string|array
    {
        return $this->raw;
    }

    public function isList(): bool
    {
        return is_array($this->raw);
    }

    public function string(): string
    {
        if (is_array($this->raw)) {
            throw ConfigError::at($this->path, 'expected one value, got a list');
        }
        return $this->raw;
    }

    /**
     * The value as a list. A single value is a list of one, which is how
     * ConfigObj reads it too: 'senders = *' and 'senders = *,' mean the same.
     *
     * @return list<string>
     */
    public function list(): array
    {
        return is_array($this->raw) ? $this->raw : [$this->raw];
    }

    public function int(): int
    {
        $text = trim($this->string());
        if (preg_match('/^[+-]?\d+$/', $text) !== 1) {
            throw ConfigError::at($this->path, sprintf('expected an integer, got %s', var_export($text, true)));
        }
        return (int) $text;
    }

    public function float(): float
    {
        $text = trim($this->string());
        if (!is_numeric($text)) {
            throw ConfigError::at($this->path, sprintf('expected a number, got %s', var_export($text, true)));
        }
        return (float) $text;
    }

    public function bool(): bool
    {
        $text = strtolower(trim($this->string()));
        return match ($text) {
            'true', 'yes', 'on', '1' => true,
            'false', 'no', 'off', '0' => false,
            default => throw ConfigError::at($this->path, sprintf('expected true or false, got %s', var_export($text, true))),
        };
    }

    /**
     * A span of time in seconds, written the way people say it: '90',
     * '5m', '2h', '7d'.
     */
    public function duration(): int
    {
        $text = trim($this->string());
        if (preg_match('/^(\d+)\s*([smhd]?)$/i', $text, $found) !== 1) {
            throw ConfigError::at($this->path, sprintf('expected a duration such as 300, 5m, 2h or 7d, got %s', var_export($text, true)));
        }
        $factors = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
        return ((int) $found[1]) * $factors[strtolower($found[2])];
    }

    /**
     * @param list<string> $allowed
     */
    public function oneOf(array $allowed): string
    {
        $text = $this->string();
        if (!in_array($text, $allowed, true)) {
            throw ConfigError::at($this->path, sprintf('expected one of %s, got %s', implode(', ', $allowed), var_export($text, true)));
        }
        return $text;
    }
}
