<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

final class Input
{
    /** @param array<string, mixed> $values */
    public static function text(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;
        if (!is_string($value) || strlen($value) > 65536 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new Problem('error.input', $key);
        }
        return $value;
    }

    /** @param array<string, mixed> $values
     *
     * @return list<string> */
    public static function strings(array $values, string $key): array
    {
        $items = $values[$key] ?? [];
        if (!is_array($items) || count($items) > 2000) {
            throw new Problem('error.input', $key);
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) || strlen($item) > 128) {
                throw new Problem('error.input', $key);
            }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    public static function id(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new Problem('error.identifier');
        }
        return $value;
    }

    public static function label(string $value): string
    {
        if (trim($value) === '' || strlen($value) > 160 || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new Problem('error.label', 'name');
        }
        return trim($value);
    }
}
