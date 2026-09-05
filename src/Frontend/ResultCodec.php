<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

final class ResultCodec
{
    /**
     * @param array<mixed> $rows
     * @return list<array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}>
     */
    public static function points(array $rows): array
    {
        $points = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['start'] ?? null) || !is_int($row['end'] ?? null)) {
                throw new QueryError('Invalid saved series');
            }
            $value = self::raw($row['value'] ?? null);
            $points[] = ['start' => $row['start'], 'end' => $row['end'], 'value' => $value, 'coverage' => Accumulator::number($row['coverage'] ?? null)];
        }
        return $points;
    }

    public static function encode(Value|Series|Report $result): string
    {
        return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public static function decode(string $json, string $timezone, string $status = 'ready'): Value|Series|Report
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new QueryError('Invalid saved result');
        }
        if (($data['type'] ?? null) === 'report') {
            $periods = self::decode(json_encode($data['periods'] ?? null, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $timezone, $status);
            if (!$periods instanceof Series || !is_array($data['values'] ?? null) || !is_array($data['meta'] ?? null)) {
                throw new QueryError('Invalid saved report');
            }
            $values = [];
            foreach ($data['values'] as $name => $item) {
                $value = self::decode(json_encode($item, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $timezone, $status);
                if (!is_string($name) || !$value instanceof Value) {
                    throw new QueryError('Invalid saved report field');
                }
                $values[$name] = $value;
            }
            $meta = [];
            foreach ($data['meta'] as $key => $item) {
                if (!is_string($key)) {
                    throw new QueryError('Invalid report metadata key');
                }
                $meta[$key] = $item;
            }
            return new Report($periods, $values, $meta, $status);
        }
        $unit = is_string($data['unit'] ?? null) ? $data['unit'] : null;
        $group = is_string($data['group'] ?? null) ? $data['group'] : null;
        $asOf = is_int($data['asOf'] ?? null) ? $data['asOf'] : null;
        $computedAt = is_int($data['computedAt'] ?? null) ? $data['computedAt'] : null;
        if (is_array($data['points'] ?? null)) {
            return new Series(
                self::points($data['points']),
                $unit,
                $group,
                $status,
                $asOf,
                $computedAt,
                delta: ($data['delta'] ?? false) === true,
                fallback: is_array($data['fallback'] ?? null) ? self::points($data['fallback']) : [],
            );
        }
        $value = self::raw($data['value'] ?? null);
        return new Value($value, $unit, $group, $status, $asOf, $computedAt, Accumulator::number($data['coverage'] ?? null), $timezone, delta: ($data['delta'] ?? false) === true);
    }

    private static function raw(mixed $value): int|float|bool|string|Vector|null
    {
        if (is_array($value)) {
            $real = Accumulator::number($value['real'] ?? null);
            $imag = Accumulator::number($value['imag'] ?? null);
            if ($real !== null && $imag !== null) {
                return new Vector($real, $imag);
            }
        }
        if (is_int($value) || is_float($value) || is_bool($value) || is_string($value) || $value === null) {
            return $value;
        }
        throw new QueryError('Invalid saved value');
    }
}
