<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\Config;
use WeewxPhp\Db\Json;

/** Themes declare settings; core code renders and validates them. */
final class ThemeRegistry
{
    public function __construct(private readonly string $directory) {}

    /**
     * @return list<string> */
    public function themes(): array
    {
        $found = [];
        $paths = glob($this->directory . '/*/settings.json');
        foreach ($paths === false ? [] : $paths as $path) {
            try {
                $found[] = Input::id(basename(dirname($path)));
            } catch (Problem) {
                continue;
            }
        }
        return $found;
    }

    /**
     * @return array<string, mixed> */
    public function definition(string $id): array
    {
        Input::id($id);
        $path = $this->directory . '/' . $id . '/settings.json';
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false || strlen($text) > 65536) {
            throw new Problem('error.theme');
        }
        $definition = Json::object($text);
        if (($definition['theme'] ?? null) !== $id || ($definition['schema_version'] ?? null) !== 1 || !is_array($definition['fields'] ?? null)) {
            throw new Problem('error.theme');
        }
        $keys = [];
        foreach ($this->fields($definition) as $field) {
            $key = Input::id(Input::text($field, 'key'));
            if (isset($keys[$key]) || in_array($key, ['action', 'csrf', 'revision', 'theme', 'activate', 'schema_version'], true)
                || !in_array(Input::text($field, 'type'), ['text', 'boolean', 'integer', 'number', 'select', 'color', 'archive', 'archive_field'], true)) {
                throw new Problem('error.theme');
            }
            $keys[$key] = true;
        }
        if (count($keys) > 100) {
            throw new Problem('error.theme');
        }
        return $definition;
    }

    /** @param array<string, mixed> $definition
     * @return list<array<string, mixed>> */
    public function fields(array $definition): array
    {
        $result = [];
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields)) {
            throw new Problem('error.theme');
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new Problem('error.theme');
            }
            $result[] = Json::object(json_encode($field, JSON_THROW_ON_ERROR));
        }
        return $result;
    }

    /** @return array<string, int|float|bool|string|null> */
    public function settings(string $id, \WeewxPhp\Config\ConfFile $file, Config $config): array
    {
        $section = $file->root()->optionalSection('Themes')?->optionalSection($id);
        $input = [];
        foreach ($section?->keys() ?? [] as $key) {
            $input[$key] = $section?->optional($key)?->string() ?? '';
        }
        $values = $this->validate($id, $input, $config);
        $result = [];
        foreach ($this->fields($this->definition($id)) as $field) {
            $key = Input::text($field, 'key');
            $value = $values[$key];
            $result[$key] = match (Input::text($field, 'type')) {
                'boolean' => $value === 'true',
                'integer' => (int) $value,
                'number' => (float) $value,
                default => $value,
            };
        }
        return $result;
    }

    /** @param array<string, mixed> $input
     * @return array<string, string> */
    public function validate(string $id, array $input, Config $config): array
    {
        $result = [];
        foreach ($this->fields($this->definition($id)) as $field) {
            $key = Input::text($field, 'key');
            $type = Input::text($field, 'type');
            $default = $field['default'] ?? '';
            $default = is_bool($default) ? ($default ? 'true' : 'false') : (is_scalar($default) ? (string) $default : '');
            $value = Input::text($input, $key, $default);
            $valid = match ($type) {
                'boolean' => in_array($value, ['true', 'false'], true),
                'integer' => preg_match('/^-?\d{1,12}$/D', $value) === 1,
                'number' => is_numeric($value) && is_finite((float) $value),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1,
                'select' => is_array($field['options'] ?? null) && array_key_exists($value, $field['options']),
                'archive' => $config->archive($value) !== null,
                'archive_field' => $this->archiveField($value, $config),
                default => strlen($value) <= 500 && !str_contains($value, "\n") && !str_contains($value, "\r"),
            };
            if (in_array($type, ['integer', 'number'], true)) {
                $min = $field['min'] ?? -PHP_FLOAT_MAX;
                $max = $field['max'] ?? PHP_FLOAT_MAX;
                $valid = $valid && (is_int($min) || is_float($min)) && (is_int($max) || is_float($max)) && (float) $value >= $min && (float) $value <= $max;
            }
            if (!$valid) {
                throw new Problem('error.input', $key);
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function archiveField(string $value, Config $config): bool
    {
        $parts = explode(':', $value, 2);
        $archive = $config->archive($parts[0]);
        return $archive !== null && isset($parts[1]) && ReadModel::archive($archive)['schema']->hasColumn($parts[1]);
    }

    public function label(string $id, string $key, string $language): string
    {
        Input::id($id);
        foreach (array_unique([$language, 'en']) as $locale) {
            if (preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
                continue;
            }
            $path = $this->directory . '/' . $id . '/locales/' . $locale . '.json';
            $text = is_file($path) ? file_get_contents($path) : false;
            if ($text !== false && strlen($text) <= 65536) {
                $messages = Json::object($text);
                if (is_string($messages[$key] ?? null)) {
                    return $messages[$key];
                }
            }
        }
        return $key;
    }
}
