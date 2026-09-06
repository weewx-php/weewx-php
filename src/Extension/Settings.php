<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Config\Config;
use WeewxPhp\Config\Section;

final class Settings
{
    /** @param array<string, Setting> $fields
     * @param list<array{from: string, to: string, min: int, max: int}> $rules */
    private function __construct(public readonly string $scope, public readonly array $fields, private readonly array $rules, public readonly string $hash) {}

    public static function load(Release $release, Installer $installer, int $now): ?self
    {
        if ($release->settings === null) {
            return null;
        }
        $text = Files::read($installer->directory($release) . '/' . $release->settings, 65536);
        if ($text === null || !hash_equals($release->files[$release->settings], hash('sha256', $text))) {
            throw new \RuntimeException('Settings definition verification failed');
        }
        return self::parse($text, $now);
    }

    public static function parse(string $text, int $now): self
    {
        if (strlen($text) > 65536) {
            throw new \InvalidArgumentException('Settings definition too large');
        }
        $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !in_array($data['scope'] ?? null, ['global', 'archive'], true)
            || !is_array($data['fields'] ?? null) || !array_is_list($data['fields']) || count($data['fields']) > 40) {
            throw new \InvalidArgumentException('Invalid settings schema');
        }
        $fields = [];
        foreach ($data['fields'] as $row) {
            $field = Setting::from($row, $now);
            if (isset($fields[$field->key])) {
                throw new \InvalidArgumentException('Duplicate setting');
            }
            $fields[$field->key] = $field;
        }
        $rules = $data['rules'] ?? [];
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 20) {
            throw new \InvalidArgumentException('Invalid settings rules');
        }
        $checked = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_string($rule['from'] ?? null) || !is_string($rule['to'] ?? null)
                || !is_int($rule['min'] ?? null) || !is_int($rule['max'] ?? null)
                || ($fields[$rule['from']]->type ?? '') !== 'integer' || ($fields[$rule['to']]->type ?? '') !== 'integer') {
                throw new \InvalidArgumentException('Invalid settings rule');
            }
            $checked[] = ['from' => $rule['from'], 'to' => $rule['to'], 'min' => $rule['min'], 'max' => $rule['max']];
        }
        return new self($data['scope'], $fields, $checked, hash('sha256', $text));
    }

    /** @param array<string, mixed> $input
     * @param array<string, mixed> $clear
     * @return array<string, string|list<string>> */
    public function validate(array $input, array $clear, Section $current, Config $config): array
    {
        if (array_diff(array_keys($input), array_keys($this->fields)) !== [] || array_diff(array_keys($clear), array_keys($this->fields)) !== []) {
            throw new \InvalidArgumentException('Unknown setting');
        }
        $result = [];
        foreach ($this->fields as $key => $field) {
            if (!array_key_exists($key, $input)) {
                throw new \InvalidArgumentException('Incomplete settings form');
            }
            $value = $input[$key];
            if ($field->type === 'secret' && $value === '' && ($clear[$key] ?? '') !== '1') {
                $value = $current->optional($key)?->string() ?? '';
            }
            $result[$key] = $field->validate($value, $config);
        }
        foreach ($this->rules as $rule) {
            $difference = (int) $result[$rule['to']] - (int) $result[$rule['from']];
            if ($difference < $rule['min'] || $difference > $rule['max']) {
                throw new \InvalidArgumentException('Invalid settings range');
            }
        }
        return $result;
    }

    public static function current(Section $package, string $archive): Section
    {
        $result = new Section('options', 3);
        foreach ([$package->optionalSection('options'), $archive === '' ? null : $package->optionalSection('archive_options')?->optionalSection($archive)] as $section) {
            foreach ($section?->values() ?? [] as $key => $value) {
                $result->set($key, $value->raw());
            }
        }
        return $result;
    }
}
