<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** One body's positions, events and sampled paths. */
final class CelestialBody
{
    public function __construct(private readonly Weather $weather, private readonly Spec $spec) {}
    public function center(bool $use = true): self
    {
        $spec = clone $this->spec;
        $spec->useCenter = $use;
        return new self($this->weather, $spec);
    }
    public function tag(string $field): Query
    {
        $spec = clone $this->spec;
        $spec->observation = Almanac::name($field);
        return new Query($this->weather, $spec);
    }
    public function rise(): Query
    {
        return $this->tag('rise');
    }
    public function set(): Query
    {
        return $this->tag('set');
    }
    public function transit(): Query
    {
        return $this->tag('transit');
    }
    public function altitude(): Query
    {
        return $this->tag('altitude');
    }
    public function azimuth(): Query
    {
        return $this->tag('azimuth');
    }
    public function visible(): Query
    {
        return $this->tag('visible');
    }
    public function visibleChange(int $daysAgo = 1): Query
    {
        $spec = clone $this->spec;
        $spec->observation = 'visible_change';
        $spec->daysAgo = $daysAgo;
        return new Query($this->weather, $spec);
    }
    public function series(string $field, int|string $start, int|string $end, int|string $every = '1h'): Query
    {
        $spec = clone $this->spec;
        $spec->observation = Almanac::name($field);
        $spec->start = Span::timestamp($start, $this->weather->configuration()->timezone);
        $spec->end = Span::timestamp($end, $this->weather->configuration()->timezone);
        $spec->every = $every;
        return new Query($this->weather, $spec);
    }
    public function __get(string $name): Query
    {
        return $this->tag($name);
    }
    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): Query
    {
        if ($arguments !== []) {
            throw new QueryError('This celestial tag takes no arguments');
        }
        return $this->tag($name);
    }
}
