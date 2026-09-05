<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** Per-theme presentation. Never changes process locale or stored cache values. */
final class Output
{
    /** @param array<string, string> $units Observation or unit group => target unit.
     * @param array<string, int> $decimals Observation, group or unit => decimal places.
     * @param array<string, string> $labels Unit => suffix, including spacing.
     */
    public function __construct(
        public readonly string $language = 'en',
        public readonly array $units = [],
        public readonly array $decimals = [],
        public readonly string $missing = '—',
        public readonly ?string $dateFormat = null,
        public readonly array $labels = [],
    ) {
        if (!in_array($language, ['en', 'de'], true)) {
            throw new QueryError('Supported output languages: en, de');
        }
        foreach ($decimals as $places) {
            if ($places < 0 || $places > 12) {
                throw new QueryError('Decimal places must be 0..12');
            }
        }
    }

    public function apply(Value|Series|Report $result, string $observation = ''): Value|Series|Report
    {
        if ($result instanceof Report) {
            return $result->withOutput($this, $observation);
        }
        $unit = $this->units[$observation] ?? $this->units[$result->group ?? ''] ?? null;
        if ($unit !== null) {
            $result = $result->to($unit);
        }
        return $result->withOutput($this, $observation);
    }

    public function number(float $value, ?string $group, ?string $unit, string $observation = ''): string
    {
        $places = $this->decimals[$observation] ?? $this->decimals[$unit ?? ''] ?? $this->decimals[$group ?? '']
            ?? (in_array($group, ['group_count', 'group_boolean'], true) ? 0 : 1);
        return number_format($value, $places, $this->language === 'de' ? ',' : '.', $this->language === 'de' ? '.' : ',');
    }
}
