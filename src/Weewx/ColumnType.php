<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

use InvalidArgumentException;

/** The column types WeeWX creates: what `weectl database add-column` accepts. */
enum ColumnType: string
{
    case Real = 'REAL';
    case Integer = 'INTEGER';
    case Text = 'TEXT';

    /**
     * @throws InvalidArgumentException For any other type.
     */
    public static function fromName(string $name): self
    {
        return self::tryFrom(strtoupper(trim($name)))
            ?? throw new InvalidArgumentException(sprintf('Unknown column type %s; use REAL, INTEGER or TEXT', var_export($name, true)));
    }
}
