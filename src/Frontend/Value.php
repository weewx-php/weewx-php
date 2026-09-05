<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use DateTimeZone;
use JsonSerializable;
use Stringable;
use WeewxPhp\Weewx\Units;

/** A missing observation is null; pending and stale refer to calculation, not measurement. */
final class Value implements JsonSerializable, Stringable
{
    public function __construct(
        public readonly int|float|bool|string|Vector|null $raw,
        public readonly ?string $unit = null,
        public readonly ?string $group = null,
        public readonly string $status = 'ready',
        public readonly ?int $asOf = null,
        public readonly ?int $computedAt = null,
        public readonly ?float $coverage = null,
        public readonly string $timezone = 'UTC',
        public readonly ?Output $output = null,
        public readonly string $observation = '',
        public readonly bool $delta = false,
    ) {}

    public function to(string $unit): self
    {
        if ($this->raw === null && $this->unit === null) {
            return new self(null, $unit, $this->group, $this->status, $this->asOf, $this->computedAt, $this->coverage, $this->timezone, $this->output, $this->observation, $this->delta);
        }
        if (is_string($this->raw) || $this->unit === null || !Units::canConvert($this->unit, $unit)) {
            throw new QueryError('Incompatible unit: ' . $unit);
        }
        $converted = $this->raw instanceof Vector
            ? new Vector((float) Units::convert($this->raw->real, $this->unit, $unit), (float) Units::convert($this->raw->imag, $this->unit, $unit))
            : Units::convert(is_bool($this->raw) ? (int) $this->raw : $this->raw, $this->unit, $unit);
        if ($this->delta && (is_int($converted) || is_float($converted))) {
            $converted -= (float) Units::convert(0, $this->unit, $unit);
        }
        return new self($converted, $unit, $this->group, $this->status, $this->asOf, $this->computedAt, $this->coverage, $this->timezone, $this->output, $this->observation, $this->delta);
    }

    public function format(?string $format = null, bool $label = true, ?string $missing = null): string
    {
        if ($this->raw === null) {
            return $missing ?? $this->output->missing ?? '—';
        }
        if (is_string($this->raw)) {
            return $this->raw;
        }
        if ($this->raw instanceof Vector) {
            return (new self($this->raw->magnitude(), $this->unit, $this->group, output: $this->output, observation: $this->observation))->format($format, $label, $missing)
                . ($this->raw->direction() === null ? '' : sprintf(' @ %.0f°', $this->raw->direction()));
        }
        if ($this->group === 'group_time') {
            $timestamp = $this->unit === null ? $this->raw : Units::convert((float) $this->raw, $this->unit, 'unix_epoch');
            return Span::date((int) round((float) $timestamp), new DateTimeZone($this->timezone))->format($format ?? $this->output->dateFormat ?? ($this->output?->language === 'de' ? 'd.m.Y H:i' : 'Y-m-d H:i'));
        }
        if ($format === null && $this->output !== null) {
            return $this->output->number((float) $this->raw, $this->group, $this->unit, $this->observation)
                . ($label ? ($this->output->labels[$this->unit ?? ''] ?? self::label($this->unit)) : '');
        }
        $pattern = $format ?? ($this->group === 'group_count' || $this->group === 'group_boolean' ? '%.0f' : '%.1f');
        if (preg_match('/^[^%]*%[+ -]?\d{0,2}(?:\.\d{1,2})?[fFgGdeE][^%]*$/D', $pattern) !== 1) {
            throw new QueryError('Use one numeric format, for example %.1f');
        }
        return sprintf($pattern, $this->raw) . ($label ? self::label($this->unit) : '');
    }

    /** HTML output, including custom unit labels and missing-value text. */
    public function html(?string $format = null, bool $label = true, ?string $missing = null): string
    {
        return htmlspecialchars($this->format($format, $label, $missing), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function withOutput(Output $output, string $observation = ''): self
    {
        return new self($this->raw, $this->unit, $this->group, $this->status, $this->asOf, $this->computedAt, $this->coverage, $this->timezone, $output, $observation, $this->delta);
    }

    public function __toString(): string
    {
        return $this->html();
    }

    public static function label(?string $unit): string
    {
        return match ($unit) {
            null, 'count', 'boolean' => '',
            'degree_C' => ' °C', 'degree_F' => ' °F', 'degree_K' => ' K',
            'degree_compass', 'degree_angle' => '°', 'percent' => '%',
            'mile_per_hour' => ' mph', 'km_per_hour' => ' km/h', 'meter_per_second' => ' m/s',
            'inch' => ' in', 'inHg' => ' inHg', 'mbar', 'hPa' => ' hPa',
            'watt_per_meter_squared' => ' W/m²', 'uv_index' => '',
            'mm_per_hour' => ' mm/h', 'cm_per_hour' => ' cm/h', 'inch_per_hour' => ' in/h',
            'foot' => ' ft', 'meter' => ' m', 'second' => ' s',
            'microsiemens_per_centimeter' => ' µS/cm', 'volt' => ' V',
            default => ' ' . $unit,
        };
    }

    /** @return array<string, int|float|bool|string|Vector|null> */
    public function jsonSerialize(): array
    {
        return ['value' => $this->raw, 'unit' => $this->unit, 'group' => $this->group,
            'status' => $this->status, 'asOf' => $this->asOf, 'computedAt' => $this->computedAt, 'coverage' => $this->coverage, 'delta' => $this->delta];
    }
}
