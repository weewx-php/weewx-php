<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Astronomy\Sky;

/** Lazy almanac. PHP calls and WeeWX-style property names use the same recipes. */
final class Almanac
{
    public readonly bool $hasExtras;
    public function __construct(private readonly Weather $weather, private readonly Spec $spec)
    {
        $this->hasExtras = true;
    }

    public function body(string $name): CelestialBody
    {
        $spec = clone $this->spec;
        $spec->body = Sky::body($name);
        return new CelestialBody($this->weather, $spec);
    }
    public function sun(): CelestialBody
    {
        return $this->body('sun');
    }
    public function moon(): CelestialBody
    {
        return $this->body('moon');
    }

    public function observer(float $horizon = 0, float $temperature = 15, float $pressure = 1010, ?float $latitude = null, ?float $longitude = null, ?float $elevation = null): self
    {
        $spec = clone $this->spec;
        $spec->horizon = $horizon;
        $spec->temperature = $temperature;
        $spec->pressure = $pressure;
        $spec->latitude = $latitude;
        $spec->longitude = $longitude;
        $spec->elevation = $elevation;
        return new self($this->weather, $spec);
    }

    public function tag(string $name): Query
    {
        $spec = clone $this->spec;
        $spec->observation = self::name($name);
        if ($spec->observation === 'sunrise' || $spec->observation === 'sunset') {
            return $this->sun()->tag($spec->observation === 'sunrise' ? 'rise' : 'set');
        }
        return new Query($this->weather, $spec);
    }

    public function separation(string $first, string $second): Query
    {
        $spec = clone $this->spec;
        $spec->body = Sky::body($first);
        $spec->otherBody = Sky::body($second);
        $spec->observation = 'separation';
        return new Query($this->weather, $spec);
    }

    public function __get(string $name): Query|CelestialBody
    {
        return in_array(strtolower($name), Sky::bodies(), true) ? $this->body($name) : $this->tag($name);
    }
    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): Query|CelestialBody
    {
        if ($arguments !== []) {
            throw new QueryError('Use observer() or body() for almanac options');
        }
        return $this->__get($name);
    }
    public static function name(string $name): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name);
    }
}
