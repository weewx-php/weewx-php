<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** The render-context tags belong to the theme, not to the weather database. */
final class Theme
{
    /**
     * @param array<string, string> $texts
     * @param array<string, string> $labels
     * @param array<string, int|float|bool|string|null> $extras
     * @param array<string, list<string>> $summaries Dates of pages actually rendered.
     */
    public function __construct(
        public readonly string $name = '',
        public readonly string $version = '',
        public readonly string $language = 'en',
        public readonly string $filename = '',
        public readonly string $page = '',
        public readonly array $texts = [],
        public readonly array $labels = [],
        public readonly array $extras = [],
        public readonly array $summaries = [],
        public readonly UnitPreferences $units = new UnitPreferences(),
    ) {}

    /** Load registered settings as typed Extras without executing theme code. */
    public static function configured(string $path, ?string $id = null, ?string $language = null, ?string $directory = null, ?UnitPreferences $units = null): self
    {
        $file = \WeewxPhp\Config\ConfFile::read($path);
        $resolved = realpath($path);
        $config = \WeewxPhp\Config\ConfigReader::read($file, dirname($resolved === false ? $path : $resolved));
        $id ??= $file->root()->optionalSection('Themes')?->optional('active')?->string() ?? 'basic';
        $registry = \WeewxPhp\Admin\ThemeRegistry::configured($path, $directory, $file);
        $extras = $registry->settings($id, $file, $config);
        $themes = $file->root()->optionalSection('Themes');
        $selected = $themes?->optionalSection($id)?->optional('language')?->string()
            ?? $themes?->optional('language')?->string() ?? $extras['language'] ?? 'en';
        $language = $registry->language($id, $language ?? (is_string($selected) ? $selected : 'en'));
        $units ??= new UnitPreferences($themes?->optional('units')?->string() ?? 'metric');
        return new self(name: $id, language: $language, texts: $registry->texts($id, $language), extras: $extras, units: $units);
    }

    public function output(?Output $format = null): Output
    {
        return $this->units->output($format ?? new Output($this->language === 'de' ? 'de' : 'en'));
    }

    /** @return array<string, string> Selection ID => localized label, with English fallback. */
    public function unitOptions(): array
    {
        return array_map($this->text(...), ['station' => 'Station default'] + UnitPreferences::PROFILES);
    }

    public function text(string $message, ?string $context = null): string
    {
        return $this->texts[$context === null ? $message : $context . "\004" . $message] ?? $message;
    }

    public function html(string $message, ?string $context = null): string
    {
        return htmlspecialchars($this->text($message, $context), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function label(string $observation): string
    {
        return $this->labels[$observation] ?? $observation;
    }

    /** @return array<string, mixed> */
    public function tags(?int $at = null, string $timezone = 'UTC'): array
    {
        $date = $at === null ? null : Span::date($at, new \DateTimeZone($timezone));
        return ['encoding' => 'utf-8', 'filename' => $this->filename, 'lang' => $this->language,
            'page' => $this->page, 'skin' => $this->name, 'SKIN_NAME' => $this->name, 'SKIN_VERSION' => $this->version,
            'month_name' => $date?->format('F'), 'year_name' => $date?->format('Y'),
            'SummaryByDay' => $this->summaries['day'] ?? [], 'SummaryByMonth' => $this->summaries['month'] ?? [],
            'SummaryByYear' => $this->summaries['year'] ?? [], 'Extras' => $this->extras];
    }
}
