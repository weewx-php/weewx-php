<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** Mergeable state for a resumable aggregate. No archive rows are kept in memory. */
final class Accumulator
{
    public float $count = 0;
    public float $sum = 0;
    public float $wsum = 0;
    public float $weight = 0;
    public float $square = 0;
    public float $x = 0;
    public float $y = 0;
    public ?float $min = null;
    public ?float $max = null;
    public ?float $mintime = null;
    public ?float $maxtime = null;
    public ?float $gustdir = null;
    public ?float $first = null;
    public ?float $last = null;
    public ?float $firsttime = null;
    public ?float $lasttime = null;
    public ?float $endpointFirst = null;
    public ?float $endpointLast = null;
    public ?float $endpointFirstTime = null;
    public ?float $endpointLastTime = null;
    public float $minTotal = 0;
    public float $maxTotal = 0;
    public float $minCount = 0;
    public float $maxCount = 0;
    public ?float $maxmin = null;
    public ?float $maxmintime = null;
    public ?float $minmax = null;
    public ?float $minmaxtime = null;
    public ?float $minsum = null;
    public ?float $minsumtime = null;
    public ?float $maxsum = null;
    public ?float $maxsumtime = null;
    public ?float $matches = null;
    public float $days = 0;
    public float $directionWeight = 0;
    public float $vectorCount = 0;
    public ?float $firstdir = null;
    public ?float $lastdir = null;
    public ?float $mindir = null;
    public ?float $maxdir = null;

    /** @param array<string, mixed> $row */
    public function add(array $row, bool $daily, Spec $spec, ?float $threshold): void
    {
        $time = self::number($row['dateTime'] ?? null);
        if ($time === null) {
            return;
        }
        if ($daily) {
            $count = self::number($row['count'] ?? null) ?? 0;
            $sum = self::number($row['sum'] ?? null) ?? 0;
            $weight = self::number($row['sumtime'] ?? null) ?? 0;
            $wsum = self::number($row['wsum'] ?? null) ?? 0;
            $min = self::number($row['min'] ?? null);
            $max = self::number($row['max'] ?? null);
            $mintime = self::number($row['mintime'] ?? null);
            $maxtime = self::number($row['maxtime'] ?? null);
            $this->x += self::number($row['xsum'] ?? null) ?? 0;
            $this->y += self::number($row['ysum'] ?? null) ?? 0;
            $this->square += self::number($row['wsquaresum'] ?? null) ?? 0;
            $this->directionWeight += self::number($row['dirsumtime'] ?? null) ?? 0;
            $gustdir = self::number($row['max_dir'] ?? null);
            ++$this->days;
            if ($min !== null) {
                $this->minTotal += $min;
                ++$this->minCount;
                $this->extreme($min, $mintime, $this->maxmin, $this->maxmintime, false);
            }
            if ($max !== null) {
                $this->maxTotal += $max;
                ++$this->maxCount;
                $this->extreme($max, $maxtime, $this->minmax, $this->minmaxtime, true);
            }
            $this->extreme($sum, $time, $this->minsum, $this->minsumtime, true);
            $this->extreme($sum, $time, $this->maxsum, $this->maxsumtime, false);
            if ($threshold !== null && preg_match('/^(avg|min|max|sum)_(ge|gt|le|lt)$/D', $spec->aggregate, $match) === 1) {
                $value = match ($match[1]) {
                    'avg' => $weight > 0 ? $wsum / $weight : null, 'min' => $min, 'max' => $max, default => $sum
                };
                // WeeWX avg threshold queries omit days without valid time weights.
                if ($value !== null || $match[1] !== 'avg') {
                    $this->matches ??= 0;
                    if ($value !== null && match ($match[2]) {
                        'ge' => $value >= $threshold, 'gt' => $value > $threshold, 'le' => $value <= $threshold, default => $value < $threshold
                    }) {
                        ++$this->matches;
                    }
                }
            }
            if (in_array($spec->observation, ['heatdeg', 'cooldeg', 'growdeg'], true)) {
                if ($weight > 0 && $threshold !== null) {
                    $degrees = max(0, $spec->observation === 'heatdeg' ? $threshold - $wsum / $weight : $wsum / $weight - $threshold);
                    $this->sum += $degrees;
                    ++$this->count;
                    $this->weight += $weight;
                }
                return;
            }
        } else {
            $vector = in_array($spec->observation, ['windvec', 'windgustvec'], true);
            $obs = match ($spec->observation) {
                'wind', 'windvec' => 'windSpeed', 'windgustvec' => 'windGust', default => $spec->observation
            };
            $direction = self::number($row[$spec->observation === 'windgustvec' ? 'windGustDir' : 'windDir'] ?? null);
            $value = self::number($row[$obs] ?? null);
            if ($this->endpointFirstTime === null) {
                $this->endpointFirstTime = $time;
                $this->endpointFirst = $value;
            }
            $this->endpointLast = $value;
            $this->endpointLastTime = $time;
            if ($value !== null) {
                if ($this->firsttime === null) {
                    $this->first = $value;
                    $this->firsttime = $time;
                    $this->firstdir = $direction;
                }
                $this->last = $value;
                $this->lasttime = $time;
                $this->lastdir = $direction;
            }
            $count = $value === null ? 0 : 1;
            $sum = $value ?? 0;
            $weight = $value === null ? 0 : max(0, (self::number($row['interval'] ?? null) ?? 0) * 60);
            $wsum = ($value ?? 0) * $weight;
            $min = $value;
            $max = $spec->observation === 'wind' ? self::number($row['windGust'] ?? null) : $value;
            $mintime = $maxtime = $time;
            $gustdir = self::number($row['windGustDir'] ?? null);
            $this->square += ($value ?? 0) ** 2 * $weight;
            if ($vector && $value !== null && ($value === 0.0 || $direction !== null)) {
                ++$this->vectorCount;
            }
            if ($direction !== null && $value !== null) {
                $this->x += $value * ($vector ? 1 : $weight) * cos(deg2rad(90 - $direction));
                $this->y += $value * ($vector ? 1 : $weight) * sin(deg2rad(90 - $direction));
            }
            if ($value !== null && ($this->min === null || $value < $this->min)) {
                $this->mindir = $direction;
            }
            if ($value !== null && ($this->max === null || $value > $this->max)) {
                $this->maxdir = $direction;
            }
        }
        $this->count += $count;
        $this->sum += $sum;
        $this->wsum += $wsum;
        $this->weight += $weight;
        $oldMax = $this->max;
        $oldTime = $this->maxtime;
        $this->extreme($min, $mintime, $this->min, $this->mintime, true);
        $this->extreme($max, $maxtime, $this->max, $this->maxtime, false);
        if ($this->max !== $oldMax || $this->maxtime !== $oldTime) {
            $this->gustdir = $gustdir;
        }
    }

    private function extreme(?float $value, ?float $time, ?float &$field, ?float &$timeField, bool $minimum): void
    {
        if ($value !== null && ($field === null || ($minimum ? $value < $field : $value > $field)
            || ($value === $field && $time !== null && ($timeField === null || $time < $timeField)))) {
            $field = $value;
            $timeField = $time;
        }
    }

    public function finish(Spec $spec, bool $daily): int|float|bool|Vector|null
    {
        $aggregate = $spec->aggregate === 'cumulative' ? 'sum' : $spec->aggregate;
        if (in_array($spec->observation, ['windvec', 'windgustvec'], true)) {
            $weight = $daily ? $this->directionWeight : $this->vectorCount;
            return match ($aggregate) {
                'avg' => $weight > 0 ? new Vector($this->x / $weight, $this->y / $weight) : null,
                'sum' => $weight > 0 ? new Vector($this->x, $this->y) : null,
                'count' => (int) $this->count,
                'has_data', 'not_null' => $this->count > 0,
                'first' => Vector::polar($this->first, $this->firstdir),
                'last' => Vector::polar($this->last, $this->lastdir),
                'min' => Vector::polar($this->min, $this->mindir),
                'max' => Vector::polar($this->max, $this->maxdir),
                default => throw new QueryError('Unsupported wind vector aggregate'),
            };
        }
        if (str_starts_with($aggregate, 'historical_')) {
            $aggregate = match ($aggregate) {
                'historical_min_avg' => 'meanmin', 'historical_max_avg' => 'meanmax', default => substr($aggregate, 11)
            };
        }
        if (preg_match('/_(ge|gt|le|lt)$/D', $aggregate) === 1) {
            return $this->matches === null ? null : (int) $this->matches;
        }
        if (in_array($spec->observation, ['heatdeg', 'cooldeg', 'growdeg'], true)) {
            return match ($aggregate) {
                'sum' => $this->sum, 'avg' => $this->count > 0 ? $this->sum / $this->count : null, 'has_data', 'not_null' => $this->count > 0, default => throw new QueryError('Degree days support sum, avg and has_data')
            };
        }
        return match ($aggregate) {
            'count' => $daily && $this->days === 0.0 ? null : (int) $this->count,
            'exists' => true,
            'has_data', 'not_null' => $this->count > 0,
            'sum' => $this->count > 0 || $this->days > 0 ? $this->sum : null,
            'weighted_avg' => $this->weight > 0 ? $this->wsum / $this->weight : null,
            'avg' => $daily ? ($this->weight > 0 ? $this->wsum / $this->weight : null) : ($this->count > 0 ? $this->sum / $this->count : null),
            'rms' => $this->weight > 0 ? sqrt($this->square / $this->weight) : null,
            'vecavg' => $this->weight > 0 ? hypot($this->x, $this->y) / $this->weight : null,
            'vecdir' => $this->x === 0.0 && $this->y === 0.0 ? null : fmod(450 - rad2deg(atan2($this->y, $this->x)), 360),
            'gustdir' => $this->gustdir,
            'meanmin' => $this->minCount > 0 ? $this->minTotal / $this->minCount : null,
            'meanmax' => $this->maxCount > 0 ? $this->maxTotal / $this->maxCount : null,
            'diff' => $this->endpointFirst === null || $this->endpointLast === null ? null : $this->endpointLast - $this->endpointFirst,
            'tderiv' => $this->endpointFirst === null || $this->endpointLast === null || $this->endpointLastTime === $this->endpointFirstTime ? null
                : ($this->endpointLast - $this->endpointFirst) / (($this->endpointLastTime ?? 0) - ($this->endpointFirstTime ?? 0)),
            'min' => $this->min,
            'max' => $this->max,
            'first' => $this->first,
            'last' => $this->last,
            'maxmin' => $this->maxmin,
            'minmax' => $this->minmax,
            'minsum' => $this->minsum,
            'maxsum' => $this->maxsum,
            'mintime' => $this->mintime === null ? null : (int) $this->mintime,
            'maxtime' => $this->maxtime === null ? null : (int) $this->maxtime,
            'firsttime' => $this->firsttime === null ? null : (int) $this->firsttime,
            'lasttime' => $this->lasttime === null ? null : (int) $this->lasttime,
            'maxmintime' => $this->maxmintime === null ? null : (int) $this->maxmintime,
            'minmaxtime' => $this->minmaxtime === null ? null : (int) $this->minmaxtime,
            'minsumtime' => $this->minsumtime === null ? null : (int) $this->minsumtime,
            'maxsumtime' => $this->maxsumtime === null ? null : (int) $this->maxsumtime,
            default => throw new QueryError('Unsupported aggregate: ' . $aggregate),
        };
    }

    public static function number(mixed $value): ?float
    {
        return (is_float($value) || is_int($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    /** Combine disjoint states with identical observation, units and weighting semantics. */
    public function merge(self $other): void
    {
        foreach (['count', 'sum', 'wsum', 'weight', 'square', 'x', 'y', 'directionWeight', 'vectorCount', 'days', 'minTotal', 'maxTotal', 'minCount', 'maxCount'] as $field) {
            $this->$field += $other->$field;
        }
        foreach (['min' => true, 'max' => false, 'maxmin' => false, 'minmax' => true, 'minsum' => true, 'maxsum' => false] as $field => $minimum) {
            $time = $field . 'time';
            $before = $this->$time;
            $this->extreme($other->$field, $other->$time, $this->$field, $this->$time, $minimum);
            if ($this->$time !== $before) {
                if ($field === 'min') {
                    $this->mindir = $other->mindir;
                }
                if ($field === 'max') {
                    $this->maxdir = $other->maxdir;
                    $this->gustdir = $other->gustdir;
                }
            }
        }
        foreach (['first' => true, 'last' => false, 'endpointFirst' => true, 'endpointLast' => false] as $field => $earliest) {
            $time = match ($field) {
                'first' => 'firsttime', 'last' => 'lasttime', 'endpointFirst' => 'endpointFirstTime', default => 'endpointLastTime'
            };
            if ($other->$time !== null && ($this->$time === null || ($earliest ? $other->$time < $this->$time : $other->$time > $this->$time))) {
                $this->$field = $other->$field;
                $this->$time = $other->$time;
                if ($field === 'first') {
                    $this->firstdir = $other->firstdir;
                }
                if ($field === 'last') {
                    $this->lastdir = $other->lastdir;
                }
            }
        }
        if ($other->matches !== null) {
            $this->matches = ($this->matches ?? 0) + $other->matches;
        }
    }

    /** @return array<string, float|null> */
    public function save(): array
    {
        return [
            'directionWeight' => $this->directionWeight, 'vectorCount' => $this->vectorCount,
            'firstdir' => $this->firstdir, 'lastdir' => $this->lastdir, 'mindir' => $this->mindir, 'maxdir' => $this->maxdir,
            'count' => $this->count,
            'sum' => $this->sum,
            'wsum' => $this->wsum,
            'weight' => $this->weight,
            'square' => $this->square,
            'x' => $this->x,
            'y' => $this->y,
            'min' => $this->min,
            'max' => $this->max,
            'mintime' => $this->mintime,
            'maxtime' => $this->maxtime,
            'gustdir' => $this->gustdir,
            'first' => $this->first,
            'last' => $this->last,
            'firsttime' => $this->firsttime,
            'lasttime' => $this->lasttime,
            'endpointFirst' => $this->endpointFirst,
            'endpointLast' => $this->endpointLast,
            'endpointFirstTime' => $this->endpointFirstTime,
            'endpointLastTime' => $this->endpointLastTime,
            'minTotal' => $this->minTotal,
            'maxTotal' => $this->maxTotal,
            'minCount' => $this->minCount,
            'maxCount' => $this->maxCount,
            'maxmin' => $this->maxmin,
            'maxmintime' => $this->maxmintime,
            'minmax' => $this->minmax,
            'minmaxtime' => $this->minmaxtime,
            'minsum' => $this->minsum,
            'minsumtime' => $this->minsumtime,
            'maxsum' => $this->maxsum,
            'maxsumtime' => $this->maxsumtime,
            'matches' => $this->matches,
            'days' => $this->days,
        ];
    }

    /** @param array<mixed> $data */
    public static function restore(array $data): self
    {
        $result = new self();
        $result->directionWeight = self::number($data['directionWeight'] ?? null) ?? 0;
        $result->vectorCount = self::number($data['vectorCount'] ?? null) ?? 0;
        $result->firstdir = self::number($data['firstdir'] ?? null);
        $result->lastdir = self::number($data['lastdir'] ?? null);
        $result->mindir = self::number($data['mindir'] ?? null);
        $result->maxdir = self::number($data['maxdir'] ?? null);
        $result->count = self::number($data['count'] ?? null) ?? 0.0;
        $result->sum = self::number($data['sum'] ?? null) ?? 0.0;
        $result->wsum = self::number($data['wsum'] ?? null) ?? 0.0;
        $result->weight = self::number($data['weight'] ?? null) ?? 0.0;
        $result->square = self::number($data['square'] ?? null) ?? 0.0;
        $result->x = self::number($data['x'] ?? null) ?? 0.0;
        $result->y = self::number($data['y'] ?? null) ?? 0.0;
        $result->min = self::number($data['min'] ?? null);
        $result->max = self::number($data['max'] ?? null);
        $result->mintime = self::number($data['mintime'] ?? null);
        $result->maxtime = self::number($data['maxtime'] ?? null);
        $result->gustdir = self::number($data['gustdir'] ?? null);
        $result->first = self::number($data['first'] ?? null);
        $result->last = self::number($data['last'] ?? null);
        $result->firsttime = self::number($data['firsttime'] ?? null);
        $result->lasttime = self::number($data['lasttime'] ?? null);
        $result->endpointFirst = self::number($data['endpointFirst'] ?? null);
        $result->endpointLast = self::number($data['endpointLast'] ?? null);
        $result->endpointFirstTime = self::number($data['endpointFirstTime'] ?? null);
        $result->endpointLastTime = self::number($data['endpointLastTime'] ?? null);
        $result->minTotal = self::number($data['minTotal'] ?? null) ?? 0.0;
        $result->maxTotal = self::number($data['maxTotal'] ?? null) ?? 0.0;
        $result->minCount = self::number($data['minCount'] ?? null) ?? 0.0;
        $result->maxCount = self::number($data['maxCount'] ?? null) ?? 0.0;
        $result->maxmin = self::number($data['maxmin'] ?? null);
        $result->maxmintime = self::number($data['maxmintime'] ?? null);
        $result->minmax = self::number($data['minmax'] ?? null);
        $result->minmaxtime = self::number($data['minmaxtime'] ?? null);
        $result->minsum = self::number($data['minsum'] ?? null);
        $result->minsumtime = self::number($data['minsumtime'] ?? null);
        $result->maxsum = self::number($data['maxsum'] ?? null);
        $result->maxsumtime = self::number($data['maxsumtime'] ?? null);
        $result->matches = self::number($data['matches'] ?? null);
        $result->days = self::number($data['days'] ?? null) ?? 0.0;
        return $result;
    }
}
