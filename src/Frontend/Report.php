<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use JsonSerializable;

/** Prepared analysis, including its comparison population and exclusions. */
final class Report implements JsonSerializable
{
    /** @param array<string, Value> $values
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly Series $periods,
        public readonly array $values,
        public readonly array $meta,
        public readonly string $status = 'ready',
        public readonly ?Output $output = null,
    ) {}

    public function value(string $name): Value
    {
        return $this->values[$name] ?? new Value(null, status: $this->status, output: $this->output);
    }

    /** @return list<int> */
    public function referenceYears(): array
    {
        $years = $this->meta['referenceYears'] ?? [];
        return is_array($years) ? array_values(array_filter($years, 'is_int')) : [];
    }

    public function withOutput(Output $output, string $observation = ''): self
    {
        $values = [];
        foreach ($this->values as $name => $value) {
            $presented = $output->apply($value, $value->group === $this->periods->group ? $observation : '');
            if ($presented instanceof Value) {
                $values[$name] = $presented;
            }
        }
        $periods = $output->apply($this->periods, $observation);
        return new self($periods instanceof Series ? $periods : $this->periods, $values, $this->meta, $this->status, $output);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => 'report', 'periods' => $this->periods, 'values' => $this->values, 'meta' => $this->meta, 'status' => $this->status];
    }
}
