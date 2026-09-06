<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Config\Config;

/** Declarative fields; no package code or arbitrary regular expressions. */
final class Setting
{
    /** @param array<string, string> $labels
     * @param string|list<string> $default */
    private function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly array $labels,
        public readonly string|array $default,
        public readonly int $min,
        public readonly int $max,
        public readonly string $format,
    ) {}

    public static function from(mixed $data, int $now): self
    {
        if (!is_array($data) || !is_string($data['key'] ?? null) || !is_string($data['type'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $data['key']) !== 1
            || !in_array($data['type'], ['text', 'integer', 'boolean', 'secret', 'archives'], true)) {
            throw new \InvalidArgumentException('Invalid setting field');
        }
        $labels = $data['label'] ?? null;
        if (!is_array($labels) || !isset($labels['en']) || count($labels) > 20) {
            throw new \InvalidArgumentException('Invalid setting labels');
        }
        foreach ($labels as $locale => $text) {
            if (!is_string($locale) || preg_match('/^[a-z]{2}(?:-[A-Za-z0-9]{2,8})?$/D', $locale) !== 1 || !is_string($text) || strlen($text) > 200) {
                throw new \InvalidArgumentException('Invalid setting label');
            }
        }
        $default = $data['default'] ?? '';
        if (is_bool($default)) {
            $default = $default ? 'true' : 'false';
        } elseif (is_int($default)) {
            $default = (string) $default;
        }
        if (is_array($default)) {
            if (!array_is_list($default)) {
                throw new \InvalidArgumentException('Invalid setting default');
            }
            $items = [];
            foreach ($default as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException('Invalid setting default');
                }
                $items[] = $item;
            }
            $default = $items;
        } elseif (!is_string($default)) {
            throw new \InvalidArgumentException('Invalid setting default');
        }
        $min = $data['min'] ?? 0;
        $max = $data['max'] ?? 1000000;
        $max = $max === 'previous_year' ? (int) gmdate('Y', $now) - 1 : $max;
        $format = $data['format'] ?? '';
        if (!is_int($min) || !is_int($max) || $min > $max || !in_array($format, ['', 'api_key'], true)) {
            throw new \InvalidArgumentException('Invalid setting limits');
        }
        return new self($data['key'], $data['type'], $labels, $default, $min, $max, $format);
    }

    public function label(string $language): string
    {
        return $this->labels[$language] ?? $this->labels['en'];
    }

    /** @return string|list<string> */
    public function validate(mixed $value, Config $config): string|array
    {
        if ($this->type === 'archives') {
            if (!is_array($value) || !array_is_list($value) || count($value) > 2000) {
                throw new \InvalidArgumentException('Invalid archive selection');
            }
            $result = [];
            foreach ($value as $id) {
                if (!is_string($id) || ($id !== '' && $config->archive($id) === null)) {
                    throw new \InvalidArgumentException('Unknown archive');
                }
                if ($id !== '') {
                    $result[] = $id;
                }
            }
            return array_values(array_unique($result));
        }
        if (!is_string($value) || strlen($value) > 1024 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid setting value');
        }
        if (($this->type === 'boolean' && !in_array($value, ['true', 'false'], true))
            || ($this->type === 'integer' && (preg_match('/^-?\d{1,9}$/D', $value) !== 1 || (int) $value < $this->min || (int) $value > $this->max))
            || ($this->format === 'api_key' && $value !== '' && preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $value) !== 1)) {
            throw new \InvalidArgumentException('Setting outside allowed range');
        }
        return $value;
    }
}
