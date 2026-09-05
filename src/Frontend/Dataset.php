<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use JsonSerializable;

/** One named contract for PHP, charting and fixed-manifest JSON endpoints. */
final class Dataset implements JsonSerializable
{
    /** @param array<string, Query> $queries */
    public function __construct(private readonly array $queries)
    {
        if (count($queries) > 128) {
            throw new QueryError('A dataset supports at most 128 queries');
        }
    }

    /** @return array<string, Value|Series|Report> */
    public function get(): array
    {
        $results = [];
        foreach ($this->queries as $name => $query) {
            $results[$name] = $query->get();
        }
        return $results;
    }

    /** Exact shared grid; absent measurements retain null. Independently anchored archives
     * must first use the same fixed/clock reference and resolution.
     * @return list<array{start: int, end: int, values: array<string, int|float|bool|string|Vector|null>, coverage: array<string, float|null>}>
     */
    public function aligned(): array
    {
        $grid = [];
        $names = [];
        foreach ($this->get() as $name => $series) {
            if (!$series instanceof Series) {
                continue;
            }
            $names[] = $name;
            foreach ($series->points as $point) {
                $key = $point['start'] . ':' . $point['end'];
                $grid[$key] ??= ['start' => $point['start'], 'end' => $point['end'], 'values' => [], 'coverage' => []];
                $grid[$key]['values'][$name] = $point['value'];
                $grid[$key]['coverage'][$name] = $point['coverage'];
            }
        }
        uasort($grid, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $end = null;
        foreach ($grid as &$row) {
            if ($end !== null && $row['start'] < $end) {
                throw new QueryError('Dataset grids overlap; use the same period and resolution');
            }
            $end = $row['end'];
            foreach ($names as $name) {
                $row['values'][$name] ??= null;
                $row['coverage'][$name] ??= null;
            }
        }
        unset($row);
        return array_values($grid);
    }

    /** @return array<string, Value|Series|Report> */
    public function jsonSerialize(): array
    {
        return $this->get();
    }

    public function json(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
