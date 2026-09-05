<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use DateTimeZone;

/** Analysis is part of the persisted recipe and executes in the same bounded worker. */
final class Analysis
{
    /** Equal month-to-date windows, down to the same local clock time. Invalid leap dates are skipped.
     * @return list<Span>
     */
    public static function months(Spec $spec, ArchiveReader $reader, int $asOf): array
    {
        $zone = $reader->config->timezone;
        $at = Span::date($asOf, $zone);
        $year = (int) $at->format('Y');
        $firstYear = (int) Span::date($reader->first ?? $asOf, $zone)->format('Y');
        $month = (int) $at->format('n');
        $day = (int) $at->format('j');
        $spans = [];
        if ($year - $firstYear > 10000) {
            throw new QueryError('Comparison exceeds 10000 years');
        }
        for ($y = $firstYear; $y <= $year; ++$y) {
            if (!checkdate($month, $day, $y)) {
                continue;
            }
            $start = $at->setDate($y, $month, 1)->setTime(0, 0)->getTimestamp();
            $end = $at->setDate($y, $month, $day)->getTimestamp();
            if ($end > $start) {
                $spans[] = new Span($start, $end);
            }
        }
        return $spans;
    }

    /** @param list<array<string, mixed>> $excluded */
    public static function report(Spec $spec, Series $series, DateTimeZone $zone, array $excluded = []): Report
    {
        $valid = [];
        foreach ($series->points as $point) {
            if ($spec->month > 0 && (int) Span::date($point['start'], $zone)->format('n') !== $spec->month) {
                continue;
            }
            if (Accumulator::number($point['value']) === null) {
                $excluded[] = ['start' => $point['start'], 'end' => $point['end'], 'coverage' => $point['coverage'],
                    'reason' => ($point['coverage'] ?? 0) < $spec->coverage ? 'coverage' : 'missing'];
            } else {
                $valid[] = $point;
            }
        }
        $population = new Series($valid, $series->unit, $series->group, $series->status, $series->asOf, $series->computedAt);
        $make = static fn(int|float|null $raw, ?string $unit = null, ?string $group = null): Value => new Value(
            $raw,
            $unit ?? $series->unit,
            $group ?? $series->group,
            $series->status,
            $series->asOf,
            $series->computedAt,
            timezone: $zone->getName(),
        );
        $meta = ['recipe' => $spec->analysis, 'minimumCoverage' => $spec->coverage, 'excluded' => $excluded,
            'referenceYears' => array_values(array_unique(array_map(static fn(array $p): int => (int) Span::date($p['start'], $zone)->format('Y'), $valid))),
            'reference' => $spec->reference, 'asOf' => $series->asOf, 'timezone' => $zone->getName()];
        if ($spec->analysis === 'quantile') {
            return new Report($population, ['quantile' => $population->quantile($spec->quantile)], $meta + ['method' => 'R7', 'population' => 'interval-aggregates', 'p' => $spec->quantile], $series->status);
        }
        if ($spec->analysis === 'rank') {
            $meta['populationCount'] = count($valid);
            return new Report($population->rank($spec->limit, $spec->ascending), [], $meta, $series->status);
        }
        if ($spec->analysis === 'compare_month') {
            $currentYear = (int) Span::date($series->asOf ?? 0, $zone)->format('Y');
            $reference = [];
            $current = null;
            foreach ($valid as $point) {
                if ((int) Span::date($point['start'], $zone)->format('Y') === $currentYear) {
                    $current = $point;
                } else {
                    $reference[] = $point;
                }
            }
            $base = new Series($reference, $series->unit, $series->group, $series->status, $series->asOf, $series->computedAt);
            $value = new Value($current['value'] ?? null, $series->unit, $series->group, $series->status, $series->asOf, $series->computedAt, $current['coverage'] ?? null);
            $comparison = $base->compare($value);
            $values = ['current' => $value];
            foreach ($comparison as $key => $item) {
                $values[$key] = $item instanceof Value ? $item : $make($item, 'count', 'group_count');
            }
            $meta['referenceYears'] = array_map(static fn(array $p): int => (int) Span::date($p['start'], $zone)->format('Y'), $reference);
            $meta['leapDayPolicy'] = 'skip-invalid-date';
            $meta['alignment'] = 'same-local-date-and-time';
            return new Report($population, $values, $meta, $series->status);
        }
        $run = null;
        $best = null;
        $last = null;
        $time = 0;
        foreach ($series->points as $point) {
            $number = Accumulator::number($point['value']);
            $matches = $number !== null && ($point['coverage'] ?? 0) >= $spec->coverage && match ($spec->operator) {
                'gt' => $number > ($spec->threshold ?? 0), 'ge' => $number >= ($spec->threshold ?? 0),
                'lt' => $number < ($spec->threshold ?? 0), default => $number <= ($spec->threshold ?? 0),
            };
            if (!$matches) {
                $run = null;
                continue;
            }
            $last = $point;
            $time += $point['end'] - $point['start'];
            if ($run === null || $run['end'] !== $point['start']) {
                $run = ['start' => $point['start'], 'end' => $point['end'], 'count' => 1];
            } else {
                $run['end'] = $point['end'];
                ++$run['count'];
            }
            if ($best === null || $run['end'] - $run['start'] > $best['end'] - $best['start']) {
                $best = $run;
            }
        }
        $meta['gapPolicy'] = 'break';
        $meta['condition'] = ['operator' => $spec->operator, 'threshold' => $spec->threshold, 'unit' => $series->unit];
        $meta['resolution'] = $spec->every;
        $meta['eventTime'] = 'end-of-matching-interval';
        $values = ['start' => $make($best['start'] ?? null, 'unix_epoch', 'group_time'),
            'end' => $make($best['end'] ?? null, 'unix_epoch', 'group_time'),
            'duration' => $make($best === null ? null : $best['end'] - $best['start'], 'second', 'group_deltatime'),
            'intervals' => $make($best['count'] ?? null, 'count', 'group_count'),
            'matchingDuration' => $make($time, 'second', 'group_deltatime'),
            'last' => $make($last['end'] ?? null, 'unix_epoch', 'group_time'),
            'since' => $make($last === null ? null : max(0, ($series->asOf ?? 0) - $last['end']), 'second', 'group_deltatime')];
        return new Report($population, $values, $meta, $series->status);
    }
}
