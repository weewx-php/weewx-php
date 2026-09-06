<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Config\ConfigError;
use WeewxPhp\Config\Section;

/** Explicitly enabled, trusted local PHP packages. Configuration parsing never executes code. */
final class Definition
{
    /** @param array<string, Section> $archiveOptions */
    public function __construct(public readonly string $id, public readonly string $entry, public readonly Section $options, public readonly array $archiveOptions = []) {}

    /** @return array<string, self> */
    public static function read(?Section $section, string $base): array
    {
        $result = [];
        foreach ($section?->sections() ?? [] as $id => $one) {
            if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $id) !== 1) {
                throw new ConfigError('Invalid extension id');
            }
            if (!($one->optional('enabled')?->bool() ?? false)) {
                continue;
            }
            $entry = $one->value('entry')->string();
            if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '://') || str_starts_with($entry, '//') || str_starts_with($entry, '\\\\')
                || strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
                throw new ConfigError('Extension entry must be a local PHP file');
            }
            $absolute = str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $entry) === 1;
            // A missing optional package must not prevent archive recovery or core startup.
            $path = $absolute ? $entry : $base . '/' . $entry;
            $result[$id] = new self($id, $path, $one->optionalSection('options') ?? new Section('options', 3), $one->optionalSection('archive_options')?->sections() ?? []);
        }
        return $result;
    }

    public function optionsFor(string $archive): Section
    {
        $result = new Section('options', 3);
        foreach ([$this->options, $this->archiveOptions[$archive] ?? null] as $section) {
            foreach ($section?->values() ?? [] as $key => $value) {
                $result->set($key, $value->raw());
            }
        }
        return $result;
    }
}
