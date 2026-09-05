<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/**
 * One setting a kind of upload takes: its type, whether it has to be there,
 * and what it is when it is not. The configuration reader checks a file
 * against these, so that `check-config` names a missing station id before
 * a tick finds out.
 */
final class OptionSpec
{
    public const TEXT = 'text';
    public const SECRET = 'secret';
    public const BOOL = 'bool';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const LIST = 'list';
    public const CHOICE = 'choice';

    /**
     * @param string|int|float|bool|list<string>|null $default
     * @param list<string> $choices
     */
    private function __construct(
        public readonly string $type,
        public readonly bool $required,
        public readonly string|int|float|bool|array|null $default,
        public readonly array $choices,
    ) {}

    public static function text(bool $required = false, string $default = ''): self
    {
        return new self(self::TEXT, $required, $default, []);
    }

    /** A password, a key, a token: never printed, masked in a log. */
    public static function secret(bool $required = false, string $default = ''): self
    {
        return new self(self::SECRET, $required, $default, []);
    }

    public static function flag(bool $default): self
    {
        return new self(self::BOOL, false, $default, []);
    }

    public static function int(?int $default): self
    {
        return new self(self::INT, false, $default, []);
    }

    public static function float(?float $default = null): self
    {
        return new self(self::FLOAT, false, $default, []);
    }

    /** @param list<string> $default */
    public static function list(array $default): self
    {
        return new self(self::LIST, false, $default, []);
    }

    /** @param list<string> $choices */
    public static function choice(array $choices, string $default): self
    {
        return new self(self::CHOICE, false, $default, $choices);
    }
}
