<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Stringable;

/** Immutable, lazy query. Formatting and unit conversion never create a second calculation. */
final class Query implements Stringable
{
    private readonly Spec $spec;

    public function __construct(private readonly Weather $weather, Spec $spec)
    {
        $this->spec = $weather->context($spec);
    }

    public function spec(): Spec
    {
        return clone $this->spec;
    }

    /** Read a prepared result even when this recipe was built with an inline context. */
    public function prepared(): self
    {
        return new self($this->weather->cacheOnly(), $this->spec());
    }

    public function every(int|string $interval): self
    {
        $spec = $this->spec();
        $spec->every = $interval;
        return new self($this->weather, $spec);
    }

    public function refresh(string|Refresh $rule): self
    {
        $spec = $this->spec();
        $spec->refresh = $rule instanceof Refresh ? $rule->rule : (new Refresh($rule))->rule;
        return new self($this->weather, $spec);
    }

    public function nightly(string $time = '03:00'): self
    {
        return $this->refresh(Refresh::nightly($time));
    }

    public function completed(float $minimumCoverage = 1.0): self
    {
        $spec = $this->spec();
        $spec->completed = true;
        $spec->coverage = $minimumCoverage;
        return new self($this->weather, $spec);
    }

    public function coverage(float $minimum): self
    {
        $spec = $this->spec();
        $spec->coverage = $minimum;
        return new self($this->weather, $spec);
    }

    public function threshold(float $value, ?string $unit = null): self
    {
        $spec = $this->spec();
        $spec->threshold = $value;
        $spec->thresholdUnit = $unit;
        return new self($this->weather, $spec);
    }

    public function priority(int $priority): self
    {
        $spec = $this->spec();
        $spec->priority = $priority;
        $spec->validate();
        return new self($this->weather, $spec);
    }

    public function rank(int $limit = 10, bool $ascending = false): self
    {
        $spec = $this->spec();
        $spec->analysis = 'rank';
        $spec->limit = $limit;
        $spec->ascending = $ascending;
        $spec->pointLimit = 50000;
        return new self($this->weather, $spec);
    }

    public function calendarMonth(int $month): self
    {
        $spec = $this->spec();
        $spec->month = $month;
        return new self($this->weather, $spec);
    }

    public function compareYears(float $minimumCoverage = 0.95): self
    {
        $spec = $this->spec();
        if ($spec->period !== 'month' || $spec->ago !== 0) {
            throw new QueryError('compareYears requires the reference month: month()->sum(...)->compareYears()');
        }
        $spec->analysis = 'compare_month';
        $spec->completed = false;
        $spec->every = 'month';
        $spec->coverage = $minimumCoverage;
        $spec->refresh = 'daily@03:00';
        return new self($this->weather, $spec);
    }

    public function longestSpell(float $threshold = 0, string $operator = 'le', ?string $unit = null): self
    {
        $spec = $this->spec();
        $spec->analysis = 'spell';
        $spec->threshold = $threshold;
        $spec->thresholdUnit = $unit;
        $spec->operator = $operator;
        $spec->coverage = 1.0;
        $spec->completed = true;
        $spec->pointLimit = 50000;
        return new self($this->weather, $spec);
    }

    public function lastEvent(float $threshold = 0, string $operator = 'gt', ?string $unit = null): self
    {
        $spec = $this->longestSpell($threshold, $operator, $unit)->spec();
        $spec->analysis = 'last_event';
        return new self($this->weather, $spec);
    }

    /** Exact R7 quantile of the explicitly selected interval aggregates. */
    public function quantile(float $p): self
    {
        $spec = $this->spec();
        $spec->analysis = 'quantile';
        $spec->quantile = $p;
        $spec->pointLimit = 50000;
        return new self($this->weather, $spec);
    }

    public function report(): Report
    {
        $result = $this->get();
        return $result instanceof Report ? $result : throw new QueryError('This query is not an analysis');
    }

    /** @return array{archive: string, spec: Spec} */
    public function definition(): array
    {
        return ['archive' => $this->weather->configuration()->id, 'spec' => $this->spec()];
    }

    public function get(): Value|Series|Report
    {
        return $this->weather->present($this->weather->execute($this->spec), $this->spec->observation);
    }

    public function value(): Value
    {
        $result = $this->get();
        return $result instanceof Value ? $result : throw new QueryError('Use get() or json() for a series');
    }

    public function series(): Series
    {
        $result = $this->get();
        return $result instanceof Series ? $result : throw new QueryError('This query is not a series');
    }

    public function raw(): int|float|bool|string|Vector|null
    {
        return $this->value()->raw;
    }

    public function to(string $unit): Value|Series
    {
        $result = $this->get();
        return $result instanceof Report ? throw new QueryError('Convert report fields individually or use an output profile') : $result->to($unit);
    }

    public function format(?string $format = null, bool $label = true, ?string $missing = null): string
    {
        return $this->value()->format($format, $label, $missing);
    }

    public function html(?string $format = null, bool $label = true, ?string $missing = null): string
    {
        return $this->value()->html($format, $label, $missing);
    }

    public function json(string $time = 'end', bool $milliseconds = false): string
    {
        $result = $this->get();
        return $result instanceof Series ? $result->json($time, $milliseconds) : ResultCodec::encode($result);
    }

    public function __toString(): string
    {
        return $this->html();
    }

    public function register(): string
    {
        return $this->weather->register($this->spec);
    }
}
