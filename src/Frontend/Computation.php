<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\Units;

/** A page or background worker advances the same computation by bounded pages of input. */
final class Computation
{
    private int $index = 0;
    private ?int $cursor = null;
    private ?int $measuredAt = null;
    private Accumulator $stats;
    /** @var list<array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}> */
    private array $points = [];
    /** @var list<array<string, mixed>> */
    private array $excluded = [];
    /** @var list<Span> */
    private readonly array $spans;
    public readonly Span $span;
    public readonly ?string $unit;
    public readonly ?string $group;

    public function __construct(
        public readonly Spec $spec,
        private readonly ArchiveReader $reader,
        public readonly int $asOf,
    ) {
        $spec->validate();
        if ($spec->period !== 'almanac' && !$reader->has($spec->observation) && $spec->aggregate !== 'exists' && $spec->aggregate !== 'has_data') {
            throw new QueryError('Unknown observation: ' . $spec->observation);
        }
        $zone = $reader->config->timezone;
        $at = $asOf;
        $calendarAt = $spec->reference === 'clock' || $spec->reference === 'fixed' ? $at + 1 : $at;
        $resolved = match ($spec->period) {
            'almanac' => new Span($spec->start ?? $spec->end ?? $at, $spec->end ?? $at),
            'current', 'latest' => new Span($spec->end ?? $at, $spec->end ?? $at),
            'between' => new Span($spec->start ?? $at, $spec->end ?? $at),
            'last' => new Span($at - $spec->duration, $at),
            'alltime' => new Span($reader->first === null ? $at : Intervals::startOfArchiveDay($reader->first, $zone), max($reader->first ?? $at, $at)),
            default => Span::calendar($spec->period, $calendarAt, $zone, $spec->ago, $spec->weekStart, $spec->rainStart, $reader->config->latitude ?? 0),
        };
        if ($spec->calendarDays > 0) {
            $day = Span::date($calendarAt - 1, $zone)->setTime(0, 0);
            $resolved = new Span($day->modify('-' . ($spec->calendarDays - 1) . ' days')->getTimestamp(), $day->modify('+1 day')->getTimestamp());
        }
        if ($spec->analysis === 'compare_month') {
            $date = Span::date($asOf, $zone);
            if ($date->format('m-d') === '02-29') {
                $firstYear = (int) Span::date($reader->first ?? $asOf, $zone)->format('Y');
                if ((int) $date->format('Y') - $firstYear > 10000) {
                    throw new QueryError('Comparison exceeds 10000 years');
                }
                for ($year = $firstYear; $year < (int) $date->format('Y'); ++$year) {
                    if (!checkdate(2, 29, $year)) {
                        $this->excluded[] = ['year' => $year, 'reason' => 'invalid-leap-date'];
                    }
                }
            }
        }
        $comparison = $spec->analysis === 'compare_month' ? Analysis::months($spec, $reader, $asOf) : null;
        $this->span = $comparison === null || $comparison === [] ? $resolved : new Span($comparison[0]->start, $comparison[count($comparison) - 1]->end);
        $spans = $comparison ?? ($spec->every !== null && $spec->every !== 'archive' ? $this->span->buckets($spec->every, $zone, limit: $spec->pointLimit, weekStart: $spec->weekStart) : [$this->span]);
        if ($spec->completed) {
            $kept = [];
            foreach ($spans as $span) {
                $complete = $span->end <= $at;
                if ($spec->every !== null && $spec->every !== 'archive') {
                    if (is_string($spec->every) && in_array($spec->every, ['hour', 'day', 'week', 'month', 'season', 'year'], true)) {
                        $full = Span::calendar($spec->every, $span->start + 1, $zone, weekStart: $spec->weekStart);
                        $complete = $complete && $span->start === $full->start && $span->end === $full->end;
                    } else {
                        $complete = $complete && $span->length() === Span::seconds($spec->every);
                    }
                }
                if ($complete) {
                    $kept[] = $span;
                } else {
                    $this->excluded[] = ['start' => $span->start, 'end' => $span->end, 'coverage' => null, 'reason' => 'incomplete-interval'];
                }
            }
            $spans = $kept;
        }
        $this->spans = $spans;
        if ($spec->period === 'almanac') {
            [$this->unit, $this->group] = Astronomy::units($spec->observation);
            $this->stats = new Accumulator();
            return;
        }
        $group = Catalog::group($spec->aggregate, Units::groupOf($spec->observation === 'wind' ? 'windSpeed' : $spec->observation, $reader->config->groups()));
        $unit = $group === null ? null : Units::standardUnit($reader->units, $group);
        if ($spec->aggregate === 'tderiv') {
            [$input] = Units::unitOf($reader->units, $spec->observation, $reader->config->groups());
            $unit = $input === 'kilowatt_hour' ? 'kilowatt' : 'watt';
            $group = 'group_power';
        }
        $this->unit = $unit;
        $this->group = $group;
        $this->stats = new Accumulator();
    }

    /** True when complete; otherwise the state is safe to persist and resume. */
    public function step(ReadBudget $budget, ?Cache $cache = null): bool
    {
        if ($this->index >= count($this->spans)) {
            return true;
        }
        $spec = $this->spec;
        $span = $this->spans[$this->index];
        if ($spec->period === 'almanac') {
            $budget->row();
            $this->point($span, (new Astronomy($spec, $this->reader->config))->value($span->end), null);
            return $this->index >= count($this->spans);
        }
        if ($spec->aggregate === 'exists' || (!$this->reader->has($spec->observation) && $spec->aggregate === 'has_data')) {
            $this->point($span, $this->reader->has($spec->observation), null);
            return $this->index >= count($this->spans);
        }
        if ($spec->period === 'latest' && $spec->aggregate === 'value') {
            $rows = 0;
            foreach ($this->reader->rows('SELECT * FROM archive WHERE dateTime < ? ORDER BY dateTime DESC LIMIT 512', [$this->cursor ?? $span->end + 1], $budget) as $record) {
                ++$rows;
                $this->cursor = Cache::integer($record['dateTime']);
                $record = $this->reader->derive($record, $spec->observation);
                $value = Accumulator::number($record[$spec->observation] ?? null);
                if ($value !== null) {
                    $this->measuredAt = $this->cursor;
                    $this->point($span, $value, null);
                    return true;
                }
            }
            if ($rows === 512) {
                return false;
            }
            $this->point($span, null, null);
            return true;
        }
        if ($spec->aggregate === 'value' || $spec->aggregate === 'trend') {
            $row = $this->nearest($span->end, $spec->maxDelta, $budget);
            if ($row !== null) {
                $row = $this->reader->derive($row, $spec->observation);
            }
            $value = $spec->observation === 'dateTime' ? ($row['dateTime'] ?? null) : ($row[$spec->observation] ?? null);
            $value = Accumulator::number($value);
            if ($spec->aggregate === 'trend') {
                $before = $this->nearest($span->end - $spec->duration, $spec->maxDelta, $budget);
                if ($before !== null) {
                    $before = $this->reader->derive($before, $spec->observation);
                }
                $previous = Accumulator::number($before[$spec->observation] ?? null);
                $value = $previous === null || $value === null ? null : $value - $previous;
            }
            if (in_array($spec->observation, ['windvec', 'windgustvec'], true)) {
                $value = $this->vector($row ?? []);
            }
            $this->measuredAt = is_int($row['dateTime'] ?? null) ? $row['dateTime'] : null;
            $this->point($span, $value, null);
            return true;
        }
        $historical = str_starts_with($spec->aggregate, 'historical_');
        $degrees = in_array($spec->observation, ['heatdeg', 'cooldeg', 'growdeg'], true);
        $obs = $degrees ? 'outTemp' : ($spec->observation === 'windvec' && in_array($spec->aggregate, ['avg', 'not_null', 'has_data'], true) ? 'wind' : $spec->observation);
        $daily = $this->usesDaily($span, $obs);
        if ((in_array($spec->aggregate, Catalog::DAILY, true) || $historical || $degrees) && !$daily) {
            throw new QueryError('This aggregate needs daily summaries and calendar-day boundaries');
        }
        if (in_array($spec->aggregate, ['vecavg', 'vecdir', 'gustdir', 'rms'], true) && $obs !== 'wind') {
            throw new QueryError('Use wind for vector, gust direction and RMS aggregates');
        }
        if ($historical && (!$span->wholeDays($this->reader->config->timezone)
            || Span::date($span->start, $this->reader->config->timezone)->modify('+1 day')->getTimestamp() !== $span->end)) {
            throw new QueryError('Historical aggregates need exactly one calendar day');
        }
        $chunkKey = hash('sha256', json_encode([Cache::VERSION, $this->reader->config->id, $spec->observation,
            $spec->aggregate === 'cumulative' ? 'sum' : $spec->aggregate, $span->start, $span->end,
            $daily, $spec->threshold, $spec->thresholdUnit, $spec->coverage], JSON_THROW_ON_ERROR));
        if ($this->cursor === null && $cache !== null && $spec->every !== null && $spec->every !== 'archive' && !$historical) {
            $saved = $cache->chunk($chunkKey);
            if ($saved !== null) {
                $budget->source('series_chunk');
                $this->point($span, $saved->raw, $saved->coverage);
                return $this->index >= count($this->spans);
            }
        }
        $shareable = in_array($spec->aggregate, ['sum', 'count', 'avg', 'weighted_avg', 'min', 'max', 'mintime', 'maxtime'], true)
            && !$degrees && !in_array($obs, ['wind', 'windvec', 'windgustvec'], true) && $spec->every !== 'archive';
        $scope = hash('sha256', json_encode([Cache::VERSION, $this->reader->config->id, $obs, $daily], JSON_THROW_ON_ERROR));
        $reused = false;
        if ($shareable && $cache !== null && $this->cursor === null && ($spec->period === 'between' || $span->end <= $this->asOf)) {
            $state = $cache->state($scope, $span, $budget);
            if ($state !== null) {
                $this->stats = $state;
                $reused = true;
            }
        }
        $start = $historical ? Intervals::startOfDay($this->reader->first ?? $span->start, $this->reader->config->timezone) : $span->start;
        $end = $historical ? ($this->reader->last ?? $span->end) + 1 : $span->end;
        if (!$historical && $spec->period !== 'between' && in_array($spec->reference, ['clock', 'fixed'], true)) {
            $end = min($end, $this->asOf);
        }
        $threshold = $this->threshold($degrees);
        $cursor = $this->cursor ?? ($daily || in_array($spec->aggregate, ['diff', 'tderiv'], true) ? $start - 1 : $start);
        $table = $daily ? $this->reader->dailyTable($obs, $budget) : 'archive';
        $sql = 'SELECT * FROM ' . $table . ' WHERE dateTime > ? AND dateTime ' . ($daily ? '<' : '<=') . ' ? ORDER BY dateTime LIMIT 512';
        $rows = 0;
        foreach ($reused ? [] : $this->reader->rows($sql, [$cursor, $end], $budget) as $row) {
            $stamp = $row['dateTime'] ?? null;
            if (!is_int($stamp)) {
                throw new QueryError('Invalid archive timestamp');
            }
            if (!$daily && ($row['usUnits'] ?? null) !== $this->reader->units->value) {
                throw new QueryError('Mixed archive units need normalization before aggregation');
            }
            ++$rows;
            if (!$daily) {
                $row = $this->reader->derive($row, $spec->observation);
            }
            $this->cursor = $stamp;
            if ($historical && Span::date($stamp, $this->reader->config->timezone)->format('m-d') !== Span::date($span->start, $this->reader->config->timezone)->format('m-d')) {
                continue;
            }
            if ($spec->every === 'archive') {
                if (count($this->points) >= 2048) {
                    throw new QueryError('Too many archive points; choose an aggregation interval');
                }
                $value = in_array($spec->observation, ['windvec', 'windgustvec'], true) ? $this->vector($row) : Accumulator::number($row[$obs] ?? null);
                $this->points[] = ['start' => $stamp - (int) ((Accumulator::number($row['interval'] ?? null) ?? 0) * 60), 'end' => $stamp, 'value' => $value, 'coverage' => $value === null ? 0.0 : 1.0];
            } else {
                $this->stats->add($row, $daily, $spec, $threshold);
            }
        }
        if ($rows === 512) {
            return false;
        }
        if ($spec->every === 'archive') {
            ++$this->index;
            return true;
        }
        if ($shareable && $cache !== null && $span->end <= min($this->asOf, $this->reader->last ?? 0)) {
            $cache->storeState($scope, $this->reader->config->id, $span, $this->stats);
        }
        $value = $this->stats->finish($spec, $daily);
        if ($spec->aggregate === 'tderiv' && is_float($value)) {
            [$input] = Units::unitOf($this->reader->units, $spec->observation);
            if ($input === 'watt_hour' || $input === 'kilowatt_hour') {
                $value *= 3600;
            }
        }
        $coverage = $historical ? null : ($span->length() > 0 ? min(1.0, $this->stats->weight / $span->length()) : null);
        if ($coverage !== null && $coverage < $spec->coverage) {
            $value = null;
        }
        if ($cache !== null && $spec->every !== null && !$historical && $span->end <= min($this->asOf, $this->reader->last ?? 0)) {
            $cache->storeChunk($chunkKey, $this->reader->config->id, $span, new Value($value, $this->unit, $this->group, coverage: $coverage));
        }
        $this->point($span, $value, $coverage);
        return $this->index >= count($this->spans);
    }

    private function usesDaily(Span $span, string $obs): bool
    {
        if ($this->spec->period !== 'between' && in_array($this->spec->reference, ['clock', 'fixed'], true) && $this->asOf < min($span->end, $this->reader->last ?? 0)) {
            return false;
        }
        if (!in_array($obs, $this->reader->daily, true) || $this->spec->every === 'archive'
            || $this->reader->dailyThrough < min($span->end, $this->reader->last ?? 0)
            || in_array($this->spec->aggregate, ['first', 'last', 'firsttime', 'lasttime', 'diff', 'tderiv'], true)) {
            return false;
        }
        $zone = $this->reader->config->timezone;
        return (Intervals::startOfDay($span->start, $zone) === $span->start || $span->start === $this->reader->first)
            && (Intervals::startOfDay($span->end, $zone) === $span->end || $span->end === $this->reader->last);
    }

    /** @param array<string, mixed> $row */
    private function vector(array $row): ?Vector
    {
        $gust = $this->spec->observation === 'windgustvec';
        return Vector::polar(Accumulator::number($row[$gust ? 'windGust' : 'windSpeed'] ?? null), Accumulator::number($row[$gust ? 'windGustDir' : 'windDir'] ?? null));
    }

    private function threshold(bool $degrees): ?float
    {
        $value = $this->spec->threshold;
        $unit = $this->spec->thresholdUnit;
        if ($degrees && $value === null) {
            $value = $this->spec->observation === 'growdeg' ? 50.0 : 65.0;
            $unit = 'degree_F';
        }
        if ($value === null || $unit === null) {
            return $value;
        }
        [$stored] = Units::unitOf($this->reader->units, $degrees ? 'outTemp' : $this->spec->observation, $this->reader->config->groups());
        if ($stored === null || !Units::canConvert($unit, $stored)) {
            throw new QueryError('Threshold unit does not match the observation');
        }
        $converted = Units::convert($value, $unit, $stored);
        return $converted === null ? null : (float) $converted;
    }

    /** @return array<string, mixed>|null */
    private function nearest(int $at, int $delta, ReadBudget $budget): ?array
    {
        $before = $this->reader->one('SELECT * FROM archive WHERE dateTime >= ? AND dateTime <= ? ORDER BY dateTime DESC LIMIT 1', [$at - $delta, $at], $budget);
        $after = $this->reader->one('SELECT * FROM archive WHERE dateTime > ? AND dateTime <= ? ORDER BY dateTime ASC LIMIT 1', [$at, $at + $delta], $budget);
        $beforeTime = $before['dateTime'] ?? null;
        $afterTime = $after['dateTime'] ?? null;
        if (!is_int($beforeTime)) {
            return $after;
        }
        return is_int($afterTime) && $afterTime - $at < $at - $beforeTime ? $after : $before;
    }

    private function point(Span $span, int|float|bool|string|Vector|null $value, ?float $coverage): void
    {
        $this->points[] = ['start' => $span->start, 'end' => $span->end, 'value' => $value, 'coverage' => $coverage];
        ++$this->index;
        $this->cursor = null;
        $this->stats = new Accumulator();
    }

    public function result(int $computedAt, string $status = 'ready'): Value|Series|Report
    {
        if ($this->spec->every !== null) {
            $points = $this->points;
            if ($this->spec->aggregate === 'cumulative') {
                $total = 0.0;
                foreach ($points as &$point) {
                    if (is_int($point['value']) || is_float($point['value'])) {
                        $total += $point['value'];
                    }
                    $point['value'] = $total;
                }
                unset($point);
            }
            $series = new Series($points, $this->unit, $this->group, $status, $this->asOf, $computedAt, delta: in_array($this->spec->aggregate, ['diff', 'trend'], true));
            if ($this->spec->analysis !== '') {
                $spec = clone $this->spec;
                $spec->threshold = $this->threshold(false);
                return Analysis::report($spec, $series, $this->reader->config->timezone, $this->excluded);
            }
            return $this->spec->month > 0 ? $series->calendarMonth($this->spec->month, $this->reader->config->timezone->getName()) : $series;
        }
        return new Value($this->points[0]['value'] ?? null, $this->unit, $this->group, $status, $this->measuredAt ?? $this->asOf, $computedAt, $this->points[0]['coverage'] ?? null, $this->reader->config->timezone->getName(), delta: in_array($this->spec->aggregate, ['diff', 'trend'], true));
    }

    public function save(): string
    {
        return json_encode(['measuredAt' => $this->measuredAt, 'asOf' => $this->asOf, 'index' => $this->index, 'cursor' => $this->cursor, 'stats' => $this->stats->save(), 'points' => $this->points], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function restore(string $json, Spec $spec, ArchiveReader $reader): self
    {
        $state = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_int($state['asOf'] ?? null) || !is_int($state['index'] ?? null) || !is_array($state['stats'] ?? null) || !is_array($state['points'] ?? null)) {
            throw new QueryError('Invalid saved computation');
        }
        $work = new self($spec, $reader, $state['asOf']);
        $work->measuredAt = is_int($state['measuredAt'] ?? null) ? $state['measuredAt'] : null;
        $work->index = $state['index'];
        $work->cursor = is_int($state['cursor'] ?? null) ? $state['cursor'] : null;
        $work->stats = Accumulator::restore($state['stats']);
        $work->points = ResultCodec::points($state['points']);
        return $work;
    }
}
