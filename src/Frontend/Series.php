<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use ArrayIterator;
use DateTimeZone;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}> */
final class Series implements IteratorAggregate, JsonSerializable
{
    /** @param list<array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}> $points
     * @param list<array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}> $fallback Whole original hardware spans, not additional bucket values.
     */
    public function __construct(
        public readonly array $points,
        public readonly ?string $unit = null,
        public readonly ?string $group = null,
        public readonly string $status = 'ready',
        public readonly ?int $asOf = null,
        public readonly ?int $computedAt = null,
        public readonly ?Output $output = null,
        public readonly string $observation = '',
        public readonly bool $delta = false,
        public readonly array $fallback = [],
    ) {}

    public function to(string $unit): self
    {
        $probe = new Value(null, $this->unit, $this->group);
        $probe->to($unit);
        $points = [];
        foreach ($this->points as $point) {
            $point['value'] = (new Value($point['value'], $this->unit, $this->group, delta: $this->delta))->to($unit)->raw;
            $points[] = $point;
        }
        $fallback = [];
        foreach ($this->fallback as $point) {
            $point['value'] = (new Value($point['value'], $this->unit, $this->group))->to($unit)->raw;
            $fallback[] = $point;
        }
        return new self($points, $unit, $this->group, $this->status, $this->asOf, $this->computedAt, $this->output, $this->observation, $this->delta, $fallback);
    }

    public function withOutput(Output $output, string $observation = ''): self
    {
        return new self($this->points, $this->unit, $this->group, $this->status, $this->asOf, $this->computedAt, $output, $observation, $this->delta, $this->fallback);
    }

    /** @return list<array{start: int, end: int, value: string, coverage: float|null}> */
    public function formatted(): array
    {
        return array_map(fn(array $point): array => ['start' => $point['start'], 'end' => $point['end'],
            'value' => (new Value($point['value'], $this->unit, $this->group, output: $this->output, observation: $this->observation, delta: $this->delta))->format(),
            'coverage' => $point['coverage']], $this->points);
    }

    public function value(int $index): Value
    {
        $point = $this->points[$index] ?? null;
        return new Value(
            $point['value'] ?? null,
            $this->unit,
            $this->group,
            $this->status,
            $this->asOf,
            $this->computedAt,
            $point['coverage'] ?? null,
            output: $this->output,
            observation: $this->observation,
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->points);
    }

    /**
     * Chart coordinates retain gaps as null. Milliseconds are opt-in.
     * @return list<array{int, int|float|bool|string|Vector|null}>
     */
    public function pairs(string $time = 'end', bool $milliseconds = false): array
    {
        if (!in_array($time, ['start', 'end', 'both'], true)) {
            throw new QueryError('Series time must be start, end or both');
        }
        if ($time === 'both') {
            throw new QueryError('Use points for both interval boundaries');
        }
        return array_map(static fn(array $point): array => [$point[$time] * ($milliseconds ? 1000 : 1), $point['value']], $this->points);
    }

    public function json(string $time = 'end', bool $milliseconds = false): string
    {
        return json_encode($this->pairs($time, $milliseconds), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** Rank measured intervals; missing measurements never compete with a dry interval. */
    public function rank(int $limit = 10, bool $ascending = false): self
    {
        if ($limit < 1 || $limit > 2048) {
            throw new QueryError('Rank limit must be 1..2048');
        }
        $points = array_values(array_filter($this->points, static fn(array $point): bool => is_int($point['value']) || is_float($point['value'])));
        usort($points, static function (array $a, array $b) use ($ascending): int {
            $order = $ascending ? $a['value'] <=> $b['value'] : $b['value'] <=> $a['value'];
            return $order === 0 ? $a['start'] <=> $b['start'] : $order;
        });
        return new self(array_slice($points, 0, $limit), $this->unit, $this->group, $this->status, $this->asOf, $this->computedAt, delta: $this->delta);
    }

    /** Select a month across years from a series of complete calendar-month intervals. */
    public function calendarMonth(int $month, string $timezone = 'UTC'): self
    {
        if ($month < 1 || $month > 12) {
            throw new QueryError('Month must be 1..12');
        }
        $zone = new DateTimeZone($timezone);
        $points = [];
        foreach ($this->points as $point) {
            $start = Span::date($point['start'], $zone);
            if ($start->format('d H:i:s') !== '01 00:00:00' || $start->modify('+1 month')->getTimestamp() !== $point['end']) {
                throw new QueryError('calendarMonth needs complete monthly buckets');
            }
            if ((int) $start->format('n') === $month) {
                $points[] = $point;
            }
        }
        return new self($points, $this->unit, $this->group, $this->status, $this->asOf, $this->computedAt, delta: $this->delta);
    }

    /** Calendar overlay keyed by month/day, never by position in an incomplete year.
     * Feb 29 retains its own key and is absent in ordinary years.
     * @return array<string, array<int, int|float|bool|string|Vector|null>>
     */
    public function overlay(string $timezone = 'UTC'): array
    {
        $zone = new DateTimeZone($timezone);
        $grid = [];
        $years = [];
        foreach ($this->points as $point) {
            $date = Span::date($point['start'], $zone);
            $key = $date->format('m-d H:i:s');
            $year = (int) $date->format('Y');
            $years[$year] = true;
            if (array_key_exists($year, $grid[$key] ?? [])) {
                throw new QueryError('Ambiguous local time in calendar overlay');
            }
            $grid[$key][$year] = $point['value'];
        }
        ksort($grid);
        foreach ($grid as &$row) {
            foreach (array_keys($years) as $year) {
                $row[$year] ??= null;
            }
            ksort($row);
        }
        unset($row);
        return $grid;
    }

    /** Linear interpolation (R7); only measured scalar values enter the distribution. */
    public function quantile(float $p): Value
    {
        if (!is_finite($p) || $p < 0 || $p > 1) {
            throw new QueryError('Quantile must be 0..1');
        }
        $numbers = $this->numbers();
        sort($numbers, SORT_NUMERIC);
        $index = $p * max(0, count($numbers) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $value = $numbers === [] ? null : $numbers[$lower] + ($numbers[$upper] - $numbers[$lower]) * ($index - $lower);
        return new Value($value, $this->unit, $this->group, $this->status, $this->asOf, $this->computedAt, delta: $this->delta);
    }

    /**
     * Compare a measurement with this distribution. Percentile uses the midrank of ties.
     * @return array{count: int, mean: Value, difference: Value, percentOfMean: Value, percentile: Value}
     */
    public function compare(Value $value): array
    {
        $value = $this->unit === null || $value->unit === $this->unit ? $value : $value->to($this->unit);
        $numbers = $this->numbers();
        $mean = $numbers === [] ? null : array_sum($numbers) / count($numbers);
        $raw = Accumulator::number($value->raw);
        $less = 0;
        $equal = 0;
        foreach ($numbers as $number) {
            if ($raw !== null && $number < $raw) {
                ++$less;
            } elseif ($number === $raw) {
                ++$equal;
            }
        }
        $status = $this->status === 'pending' || $value->status === 'pending' ? 'pending' : ($this->status === 'stale' || $value->status === 'stale' ? 'stale' : 'ready');
        $make = fn(?float $number, ?string $unit, ?string $group): Value => new Value($number, $unit, $group, $status, $this->asOf, $this->computedAt);
        return ['count' => count($numbers), 'mean' => $make($mean, $this->unit, $this->group),
            'difference' => new Value($raw === null || $mean === null ? null : $raw - $mean, $this->unit, $this->group, $status, $this->asOf, $this->computedAt, delta: true),
            'percentOfMean' => $make($raw === null || $mean === null || $mean === 0.0 ? null : $raw / $mean * 100, 'percent', 'group_percent'),
            'percentile' => $make($raw === null || $numbers === [] ? null : ($less + $equal / 2) / count($numbers) * 100, 'percent', 'group_percent')];
    }

    /** @return list<float> */
    private function numbers(): array
    {
        $numbers = [];
        foreach ($this->points as $point) {
            $value = Accumulator::number($point['value']);
            if ($value !== null) {
                $numbers[] = $value;
            }
        }
        return $numbers;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['points' => $this->points, 'unit' => $this->unit, 'group' => $this->group,
            'unitLabel' => $this->value(0)->unitLabel(), 'decimals' => ($this->output ?? new Output())->places($this->group, $this->unit, $this->observation),
            'status' => $this->status, 'asOf' => $this->asOf, 'computedAt' => $this->computedAt, 'delta' => $this->delta]
            + ($this->fallback === [] ? [] : ['fallback' => $this->fallback, 'fallbackSource' => 'hardware']);
    }
}
