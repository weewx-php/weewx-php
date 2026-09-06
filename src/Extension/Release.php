<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

/** An approved, immutable set of files. No URLs or executable metadata. */
final class Release
{
    public const API = 2;
    public const FILE_LIMIT = 524288;
    public const TOTAL_LIMIT = 4194304;

    /** @param array<string, string> $files
     * @param list<string> $requires
     * @param array<string, array<string, string>> $translations */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $repository,
        public readonly string $commit,
        public readonly string $entry,
        public readonly string $php,
        public readonly int $api,
        public readonly array $requires,
        public readonly array $files,
        public readonly string $reviewedAt,
        public readonly string $review,
        public readonly ?string $settings = null,
        public readonly array $translations = [],
    ) {}

    public static function from(mixed $row): self
    {
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Invalid extension release');
        }
        $string = static function (string $key, int $max = 160) use ($row): string {
            $value = $row[$key] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid release field');
            }
            return $value;
        };
        $id = $string('id', 48);
        self::id($id);
        $repository = $string('repository');
        $commit = $string('commit', 40);
        $version = $string('version', 32);
        $php = $string('php', 16);
        $date = $string('reviewed_at', 10);
        if (preg_match('#^weewx-php/[a-z0-9][a-z0-9-]{0,79}$#D', $repository) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1
            || preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $version) !== 1
            || preg_match('/^\d{1,2}\.\d{1,2}(?:\.\d{1,2})?$/D', $php) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
            || !is_int($row['api'] ?? null) || $row['api'] < 1) {
            throw new \InvalidArgumentException('Invalid release identity');
        }
        $files = $row['files'] ?? null;
        if (!is_array($files) || count($files) < 1 || count($files) > 64) {
            throw new \InvalidArgumentException('Invalid release files');
        }
        $checked = [];
        foreach ($files as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Invalid file checksum');
            }
            self::path($path);
            $key = strtolower($path);
            foreach (array_keys($checked) as $previous) {
                if ($previous === $key || str_starts_with($previous, $key . '/') || str_starts_with($key, $previous . '/')) {
                    throw new \InvalidArgumentException('Conflicting release paths');
                }
            }
            $checked[$key] = true;
        }
        $entry = $string('entry', 240);
        $review = $string('review', 240);
        self::path($entry);
        self::path($review);
        if (!isset($files[$entry]) || !str_ends_with($entry, '.php') || !isset($files[$review])) {
            throw new \InvalidArgumentException('Missing entry or review');
        }
        $requires = $row['requires'] ?? null;
        if (!is_array($requires) || !array_is_list($requires) || count($requires) > 16) {
            throw new \InvalidArgumentException('Invalid PHP requirements');
        }
        foreach ($requires as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension) !== 1) {
                throw new \InvalidArgumentException('Invalid PHP extension');
            }
        }
        ksort($files);
        $settings = isset($row['settings']) ? $string('settings', 240) : null;
        if ($settings !== null) {
            self::path($settings);
            if ($row['api'] < 2 || !isset($files[$settings]) || !str_ends_with($settings, '.json')) {
                throw new \InvalidArgumentException('Invalid settings definition');
            }
        }
        $translations = [];
        $locales = $row['translations'] ?? [];
        if (!is_array($locales) || count($locales) > 50) {
            throw new \InvalidArgumentException('Invalid release translations');
        }
        foreach ($locales as $language => $messages) {
            if (!is_string($language) || preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1 || !is_array($messages)) {
                throw new \InvalidArgumentException('Invalid release language');
            }
            foreach ($messages as $key => $value) {
                if (!in_array($key, ['name', 'description'], true) || !is_string($value) || $value === '' || strlen($value) > ($key === 'name' ? 160 : 320)
                    || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    throw new \InvalidArgumentException('Invalid release translation');
                }
                $translations[$language][$key] = $value;
            }
        }
        return new self($id, $string('name'), $string('description', 320), $version, $repository, $commit, $entry, $php, $row['api'], $requires, $files, $date, $review, $settings, $translations);
    }

    public static function id(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid extension id');
        }
        self::path($id);
    }

    public static function path(string $path): void
    {
        if (strlen($path) > 240 || substr_count($path, '/') > 7) {
            throw new \InvalidArgumentException('Invalid package path');
        }
        foreach (explode('/', $path) as $part) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $part) !== 1 || str_ends_with($part, '.')
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part) === 1) {
                throw new \InvalidArgumentException('Invalid package path');
            }
        }
    }

    public function compatible(): bool
    {
        return $this->api <= self::API && version_compare(PHP_VERSION, $this->php, '>=')
            && count(array_filter($this->requires, extension_loaded(...))) === count($this->requires);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->json());
    }

    public function json(): string
    {
        return json_encode(['id' => $this->id, 'name' => $this->name, 'description' => $this->description,
            'version' => $this->version, 'repository' => $this->repository, 'commit' => $this->commit,
            'entry' => $this->entry, 'php' => $this->php, 'api' => $this->api, 'requires' => $this->requires,
            'files' => $this->files, 'reviewed_at' => $this->reviewedAt, 'review' => $this->review]
            + ($this->settings === null ? [] : ['settings' => $this->settings])
            + ($this->translations === [] ? [] : ['translations' => $this->translations]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function text(string $key, string $language): string
    {
        return $this->translations[$language][$key] ?? ($key === 'name' ? $this->name : $this->description);
    }

    public function url(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw new \InvalidArgumentException('Unknown release file');
        }
        return 'https://raw.githubusercontent.com/' . $this->repository . '/' . $this->commit . '/' . $path;
    }
}
