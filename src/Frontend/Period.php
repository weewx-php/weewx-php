<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Generator;

/** Calendar scope. All observation names are discovered from the selected archive. */
final class Period
{
    private readonly Spec $spec;

    public function __construct(private readonly Weather $weather, Spec $spec)
    {
        $this->spec = $weather->context($spec);
    }

    public function aggregate(string $observation, string $aggregate, ?float $threshold = null, ?string $unit = null): Query
    {
        $spec = clone $this->spec;
        $spec->observation = $observation;
        $spec->aggregate = $aggregate;
        $spec->threshold = $threshold;
        $spec->thresholdUnit = $unit;
        return new Query($this->weather, $spec);
    }

    public function sum(string $observation): Query
    {
        return $this->aggregate($observation, 'sum');
    }
    public function avg(string $observation): Query
    {
        return $this->aggregate($observation, 'avg');
    }
    public function min(string $observation): Query
    {
        return $this->aggregate($observation, 'min');
    }
    public function max(string $observation): Query
    {
        return $this->aggregate($observation, 'max');
    }
    public function count(string $observation): Query
    {
        return $this->aggregate($observation, 'count');
    }

    /** Same catalogue as aggregate(), convenient for meanmax(), historical_max(), avg_gt(), etc.
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): Query
    {
        Catalog::check($name);
        $observation = $arguments[0] ?? null;
        $threshold = $arguments[1] ?? null;
        $unit = $arguments[2] ?? null;
        if (!is_string($observation) || ($threshold !== null && !is_int($threshold) && !is_float($threshold)) || ($unit !== null && !is_string($unit)) || count($arguments) > 3) {
            throw new QueryError('Use aggregate(observation, name, threshold, unit)');
        }
        return $this->aggregate($observation, $name, $threshold === null ? null : (float) $threshold, $unit);
    }

    public function series(string $observation, int|string $every = 'hour', ?string $aggregate = null): Query
    {
        return $this->aggregate($observation, $aggregate ?? $this->weather->defaultAggregate($observation))->every($every);
    }

    public function records(string $observation): Query
    {
        return $this->aggregate($observation, 'avg')->every('archive');
    }

    public function span(): Span
    {
        return $this->weather->resolve($this->spec);
    }

    public function start(): Value
    {
        return $this->weather->time($this->span()->start);
    }
    public function end(): Value
    {
        return $this->weather->time($this->span()->end);
    }
    public function length(): Value
    {
        return new Value($this->span()->length(), 'second', 'group_deltatime');
    }

    /** @return Generator<int, self> */
    public function periods(int|string $every): Generator
    {
        foreach ($this->span()->buckets($every, $this->weather->configuration()->timezone, weekStart: $this->spec->weekStart) as $span) {
            yield new self($this->weather, new Spec(period: 'between', start: $span->start, end: $span->end, weekStart: $this->spec->weekStart, rainStart: $this->spec->rainStart));
        }
    }
}
