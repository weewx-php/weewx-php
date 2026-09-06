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
    /** @var list<array{start: int, end: int, value: int|float|bool|string|Vector|null, coverage: float|null}> */
    private array $fallback = [];
    private ?int $fallbackCursor = null;
    private bool $fallbackDone = false;
    private ?int $dailyCursor = null;
    private ?Accumulator $dailyStats = null;
    private ?int $rainCoveredThrough = null;
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
            return $this->fallbackStep($budget);
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
        $daily = $this->usesDaily($span, $obs, $budget);
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
        $chunkKey = hash('sha256', CacheJson::encode([Cache::VERSION, $this->reader->config->id, $spec->observation,
            $spec->aggregate === 'cumulative' ? 'sum' : $spec->aggregate, $span->start, $span->end,
            $daily, $spec->threshold, $spec->thresholdUnit, $spec->coverage, $spec->analysis]));
        if ($this->cursor === null && $cache !== null && $spec->every !== null && $spec->every !== 'archive' && !$historical) {
            $saved = $cache->chunk($chunkKey);
            if ($saved !== null) {
                $budget->source('series_chunk');
                $this->point($span, $saved->raw, $saved->coverage);
                return $this->index >= count($this->spans) && $this->fallbackStep($budget);
            }
        }
        $shareable = !$this->reader->hardware && in_array($spec->aggregate, ['sum', 'count', 'avg', 'weighted_avg', 'min', 'max', 'mintime', 'maxtime'], true)
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
        $history = $this->reader->hardware ? new HardwareHistory($this->reader) : null;
        $input = $history === null ? $this->reader->rows($sql, [$cursor, $end], $budget)
            : ($daily ? iterator_to_array($history->days($obs, $table, $cursor, $end, $budget, 1), false) : $history->rows($obs, $start, $end, $cursor, $budget));
        foreach ($reused ? [] : $input as $row) {
            $stamp = $row['dateTime'] ?? null;
            if (!is_int($stamp)) {
                throw new QueryError('Invalid archive timestamp');
            }
            if ($daily && $history !== null) {
                $resolved = $this->hardwareDay($history, $obs, $table, $stamp, $budget);
                if ($resolved === null) {
                    return false;
                }
                $row = $resolved;
                if (($row['_empty'] ?? false) === true) {
                    ++$rows;
                    $this->cursor = $stamp;
                    continue;
                }
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
        if ($daily && $history !== null && $rows > 0) {
            if (iterator_to_array($history->days($obs, $table, $this->cursor ?? $cursor, $end, $budget, 1), false) !== []) {
                return false;
            }
        } elseif ($rows === 512) {
            return false;
        }
        if ($spec->every === 'archive') {
            ++$this->index;
            return true;
        }
        if ($shareable && $cache !== null && $span->end <= min($this->asOf, $this->reader->last ?? 0)) {
            $cache->storeState($scope, $this->reader->config->id, $span, $this->stats);
        }
        $finish = clone $spec;
        if ($history !== null && !$daily && $finish->aggregate === 'avg' && !in_array($obs, ['windvec', 'windgustvec'], true)) {
            $finish->aggregate = 'weighted_avg';
        }
        $value = $this->stats->finish($finish, $daily);
        if ($spec->aggregate === 'tderiv' && is_float($value)) {
            [$input] = Units::unitOf($this->reader->units, $spec->observation);
            if ($input === 'watt_hour' || $input === 'kilowatt_hour') {
                $value *= 3600;
            }
        }
        $coverage = $historical ? null : ($span->length() > 0 ? min(1.0, $this->stats->weight / $span->length()) : null);
        if ($spec->observation === 'rain' && (in_array($spec->aggregate, ['sum', 'cumulative'], true) || $spec->analysis === 'spell')
            && $spec->every === 'day' && ($value === null || $value === 0.0 || $value === 0) && ($coverage ?? 0) < 1) {
            // An ongoing day only needs evidence up to the latest measurement.
            // Its unfinished remainder still cannot count towards a completed dry spell.
            $observedEnd = min($span->end, $this->asOf, $this->reader->last ?? $span->start);
            $observed = new Span($span->start, max($span->start, $observedEnd));
            if ($observed->length() > 0 && ($this->stats->weight >= $observed->length()
                || RainCoverage::dry($this->reader, $observed, $budget, $this->rainCoveredThrough))) {
                $value = 0.0;
                $coverage = $observed->length() / $span->length();
            } else {
                $value = null;
            }
        }
        if ($spec->observation === 'rain' && (in_array($spec->aggregate, ['sum', 'cumulative'], true) || $spec->analysis === 'spell')
            && RainCoverage::uncertain($this->reader, $span, $budget)) {
            $value = null;
            $coverage = 0.0;
        }
        if ($coverage !== null && $coverage < $spec->coverage) {
            $value = null;
        }
        if ($cache !== null && $spec->every !== null && !$historical && $span->end <= min($this->asOf, $this->reader->last ?? 0)) {
            $cache->storeChunk($chunkKey, $this->reader->config->id, $span, new Value($value, $this->unit, $this->group, coverage: $coverage));
        }
        $this->point($span, $value, $coverage);
        return $this->index >= count($this->spans) && $this->fallbackStep($budget);
    }

    /** A day's replacement statistics are resumable, just like the outer query.
     * @return array<string, mixed>|null
     */
    private function hardwareDay(HardwareHistory $history, string $obs, string $table, int $day, ReadBudget $budget): ?array
    {
        $original = $this->reader->one("SELECT * FROM $table WHERE dateTime = ?", [$day], $budget);
        $has = $this->reader->one('SELECT 1 FROM weewx_hardware WHERE field = ? AND day = ? LIMIT 1', [HardwareHistory::field($obs), $day], $budget);
        if ($has === null) {
            return $original ?? ['dateTime' => $day];
        }
        $end = Intervals::endOfDay($day, $this->reader->config->timezone);
        $this->dailyStats ??= new Accumulator();
        $inner = clone $this->spec;
        if (in_array($inner->observation, ['heatdeg', 'cooldeg', 'growdeg'], true)) {
            $inner->observation = 'outTemp';
        }
        $count = 0;
        foreach ($history->rows($obs, $day, $end, $this->dailyCursor ?? $day, $budget) as $row) {
            $this->dailyStats->add($row, false, $inner, null);
            $this->dailyCursor = Cache::integer($row['dateTime']);
            ++$count;
        }
        if ($count === 512) {
            return null;
        }
        $stats = $this->dailyStats;
        $row = ['dateTime' => $day, '_empty' => $stats->count === 0.0, 'count' => $stats->count, 'sum' => $stats->sum, 'wsum' => $stats->wsum, 'sumtime' => $stats->weight,
            'min' => $stats->min, 'max' => $stats->max, 'mintime' => $stats->mintime, 'maxtime' => $stats->maxtime,
            'xsum' => $stats->x, 'ysum' => $stats->y, 'dirsumtime' => $stats->directionWeight,
            'wsquaresum' => $stats->square, 'max_dir' => $stats->gustdir];
        // Existing daily tables retain observed LOOP extremes which the logger averages cannot recover.
        foreach (['min', 'max'] as $key) {
            $value = Accumulator::number($original[$key] ?? null);
            if ($value !== null && ($row[$key] === null || ($key === 'min' ? $value < $row[$key] : $value > $row[$key]))) {
                $row[$key] = $value;
                $row[$key . 'time'] = $original[$key . 'time'] ?? null;
                if ($key === 'max') {
                    $row['max_dir'] = $original['max_dir'] ?? $row['max_dir'];
                }
            }
        }
        $this->dailyStats = null;
        $this->dailyCursor = null;
        return $row;
    }

    private function fallbackStep(ReadBudget $budget): bool
    {
        if ($this->fallbackDone || !$this->reader->hardware || $this->spec->every === null
            || $this->spec->every === 'archive' || $this->spec->analysis !== '' || $this->spec->period === 'almanac'
            || in_array($this->spec->observation, ['heatdeg', 'cooldeg', 'growdeg'], true)
            || !in_array($this->spec->aggregate, ['avg', 'weighted_avg', 'sum', 'cumulative', 'min', 'max', 'first', 'last'], true)) {
            return true;
        }
        $rows = 0;
        foreach ((new HardwareHistory($this->reader))->fallback($this->spec->observation, $this->span->start, $this->span->end, $this->fallbackCursor ?? $this->span->start, $budget) as $row) {
            $start = Cache::integer($row['start']);
            $end = Cache::integer($row['stop']);
            $this->fallbackCursor = $end;
            ++$rows;
            $fits = false;
            $missing = false;
            foreach ($this->points as $point) {
                $fits = $fits || ($start >= $point['start'] && $end <= $point['end']);
                $missing = $missing || ($point['start'] < $end && $point['end'] > $start && ($point['value'] === null || ($point['coverage'] ?? 0) < 1));
            }
            if (!$fits && $missing) {
                if (count($this->points) + count($this->fallback) >= 4096) {
                    throw new QueryError('Too many history intervals; reduce the requested period');
                }
                $value = Accumulator::number($row['value']);
                if (in_array($this->spec->observation, ['windvec', 'windgustvec'], true) && is_string($row['record'])) {
                    $value = $this->vector(\WeewxPhp\Db\Json::object($row['record']));
                }
                $this->fallback[] = ['start' => $start, 'end' => $end, 'value' => $value, 'coverage' => 1.0];
            }
        }
        $this->fallbackDone = $rows < 512;
        return $this->fallbackDone;
    }

    private function usesDaily(Span $span, string $obs, ReadBudget $budget): bool
    {
        if ($this->reader->hardware && !in_array($this->spec->aggregate, Catalog::DAILY, true)
            && !str_starts_with($this->spec->aggregate, 'historical_') && !in_array($this->spec->observation, ['heatdeg', 'cooldeg', 'growdeg'], true)
            && $this->reader->one('SELECT 1 FROM weewx_hardware WHERE field = ? AND stop > ? AND stop <= ? AND start < day LIMIT 1', [HardwareHistory::field($obs), $span->start, $span->end], $budget) !== null) {
            return false;
        }
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
        $this->rainCoveredThrough = null;
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
                $complete = true;
                foreach ($points as &$point) {
                    if ($this->reader->hardware && ($point['value'] === null || ($point['coverage'] ?? 0) < 1)) {
                        $complete = false;
                    }
                    if (is_int($point['value']) || is_float($point['value'])) {
                        $total += $point['value'];
                    }
                    $point['value'] = $complete ? $total : null;
                }
                unset($point);
            }
            $series = new Series($points, $this->unit, $this->group, $status, $this->asOf, $computedAt, delta: in_array($this->spec->aggregate, ['diff', 'trend'], true), fallback: $this->fallback);
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
        return json_encode(['measuredAt' => $this->measuredAt, 'asOf' => $this->asOf, 'index' => $this->index, 'cursor' => $this->cursor, 'stats' => $this->stats->save(), 'points' => $this->points,
            'fallback' => $this->fallback, 'fallbackCursor' => $this->fallbackCursor, 'fallbackDone' => $this->fallbackDone,
            'dailyCursor' => $this->dailyCursor, 'dailyStats' => $this->dailyStats?->save(),
            'rainCoveredThrough' => $this->rainCoveredThrough], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
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
        $work->fallback = is_array($state['fallback'] ?? null) ? ResultCodec::points($state['fallback']) : [];
        $work->fallbackCursor = is_int($state['fallbackCursor'] ?? null) ? $state['fallbackCursor'] : null;
        $work->fallbackDone = ($state['fallbackDone'] ?? false) === true;
        $work->dailyCursor = is_int($state['dailyCursor'] ?? null) ? $state['dailyCursor'] : null;
        $work->dailyStats = is_array($state['dailyStats'] ?? null) ? Accumulator::restore($state['dailyStats']) : null;
        $work->rainCoveredThrough = is_int($state['rainCoveredThrough'] ?? null) ? $state['rainCoveredThrough'] : null;
        return $work;
    }
}
