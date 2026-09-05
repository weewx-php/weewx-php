<?php

declare(strict_types=1);

namespace WeewxPhp\Weewx;

/**
 * Statistics for a set of observation types over one span of time.
 *
 * A transcription of `weewx.accum.Accum`, step by step in the same order,
 * because what comes out of it lands in a database WeeWX keeps reading. Two
 * things differ from the original, both taken from weewx-evo: the policy is
 * an argument rather than a global, and the accumulator never talks to a
 * database, a log or a clock. It is fed, then read.
 *
 * The span is half-open at the start: a record stamped exactly at `start`
 * belongs to the previous span.
 */
final class Accum
{
    /** @var array<string, Stats> */
    private array $stats = [];

    private ?UnitSystem $unitSystem;

    public function __construct(
        private readonly int $start,
        private readonly int $stop,
        ?UnitSystem $unitSystem = null,
        private readonly Policy $policy = new Policy(),
    ) {
        $this->unitSystem = $unitSystem;
    }

    public function start(): int
    {
        return $this->start;
    }

    public function stop(): int
    {
        return $this->stop;
    }

    public function unitSystem(): ?UnitSystem
    {
        return $this->unitSystem;
    }

    public function includes(int|float $timestamp): bool
    {
        return $this->start < $timestamp && $timestamp <= $this->stop;
    }

    /** True until the first record came in. */
    public function isEmpty(): bool
    {
        return $this->unitSystem === null;
    }

    public function has(string $obsType): bool
    {
        return isset($this->stats[$obsType]);
    }

    /**
     * @throws AccumError If the type has not been seen.
     */
    public function get(string $obsType): Stats
    {
        return $this->stats[$obsType] ?? throw new AccumError(sprintf('No statistics for %s', $obsType));
    }

    /** @return list<string> The observation types seen, in the order they arrived. */
    public function types(): array
    {
        return array_keys($this->stats);
    }

    /**
     * Fold one record into the statistics. The record must carry `dateTime`
     * and `usUnits`.
     *
     * @param array<string, mixed> $record
     *
     * @throws AccumError If the record is outside the span or in another unit system.
     */
    public function addRecord(array $record, bool $addHilo = true, int|float $weight = 1): void
    {
        $timestamp = $record['dateTime'] ?? null;
        if (!is_int($timestamp) && !is_float($timestamp)) {
            throw new AccumError('Record has no dateTime');
        }
        if (!$this->includes($timestamp)) {
            throw new AccumError(sprintf('Record at %s is outside span (%d, %d]', (string) $timestamp, $this->start, $this->stop));
        }
        foreach ($record as $obsType => $value) {
            match ($this->policy->of($obsType)->adder) {
                Adder::Noop => null,
                Adder::CheckUnits => $this->checkUnits($value),
                Adder::AddWind => $this->addWind($record, $obsType, $timestamp, $addHilo, $weight),
                Adder::Add => $this->addValue($obsType, $value, $timestamp, $addHilo, $weight),
            };
        }
    }

    /**
     * Fold another accumulator's highs and lows into this one: WeeWX's
     * `updateHiLo`, which is how LOOP extremes reach a day.
     *
     * @throws AccumError If the other span is not inside this one or the unit systems differ.
     */
    public function mergeHilo(self $other): void
    {
        if ($other->start < $this->start || $other->stop > $this->stop) {
            throw new AccumError('The other accumulator spans more than this one');
        }
        $this->checkUnits($other->unitSystem?->value);
        foreach ($other->stats as $obsType => $theirs) {
            $this->initType($obsType);
            if ($this->policy->of($obsType)->merger === Merger::Avg) {
                $this->mergeAvg($other, $obsType);
            } else {
                $this->stats[$obsType]->mergeHilo($theirs);
            }
        }
    }

    /**
     * Extract an archive record, stamped with the end of the span.
     *
     * @return array<string, mixed>
     */
    public function record(): array
    {
        return $this->augment(['dateTime' => $this->stop, 'usUnits' => $this->unitSystem?->value]);
    }

    /**
     * Fill in whatever the record does not already carry. Values already
     * present win; that is how a console's own archive record keeps its
     * hardware-computed fields and gains the ones it omitted.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function augment(array $record): array
    {
        foreach ($this->stats as $obsType => $stats) {
            if (array_key_exists($obsType, $record)) {
                continue;
            }
            $this->extract($record, $obsType, $stats);
        }
        return $record;
    }

    /**
     * Load statistics straight from storage. With null the type is merely
     * brought into existence, empty: that is how a day gets a row for an
     * observation that stayed null all day.
     *
     * @param list<int|float|null>|null $tuple
     */
    public function setStats(string $obsType, ?array $tuple = null): void
    {
        $this->initType($obsType);
        $this->stats[$obsType]->setStats($tuple);
    }

    private function addValue(string $obsType, mixed $value, int|float $timestamp, bool $addHilo, int|float $weight): void
    {
        $this->initType($obsType);
        if ($addHilo) {
            $this->stats[$obsType]->addHilo($value, $timestamp);
        }
        $this->stats[$obsType]->addSum($value, $weight);
    }

    /**
     * Wind is accumulated twice: as plain `windSpeed`, and as the vector
     * `wind` that windDir, windGust and windGustDir are read back out of.
     *
     * @param array<string, mixed> $record
     */
    private function addWind(array $record, string $obsType, int|float $timestamp, bool $addHilo, int|float $weight): void
    {
        if (in_array($obsType, ['windDir', 'windGust', 'windGustDir'], true)) {
            return;
        }
        $this->addValue($obsType, $record[$obsType], $timestamp, $addHilo, $weight);

        $this->initType('wind');
        $wind = $this->stats['wind'];
        if ($addHilo) {
            // A station that reports no gust direction gets the plain wind
            // direction instead (WeeWX issue #320). The gust goes in first,
            // so that the last value entered is windSpeed: that is what
            // vecDir falls back on when the vector sum is zero.
            $gustDir = array_key_exists('windGustDir', $record) ? $record['windGustDir'] : ($record['windDir'] ?? null);
            $wind->addHilo([$record['windGust'] ?? null, $gustDir], $timestamp);
            $wind->addHilo([$record['windSpeed'] ?? null, $record['windDir'] ?? null], $timestamp);
        }
        $wind->addSum([$record['windSpeed'] ?? null, $record['windDir'] ?? null], $weight);
    }

    /**
     * For windSpeed, the other accumulator's mean is its high: the day's
     * high should be the highest sustained wind, not the highest instant
     * reading, which is the gust and is recorded separately.
     */
    private function mergeAvg(self $other, string $obsType): void
    {
        $mine = $this->stats[$obsType];
        $theirs = $other->stats[$obsType];
        if (!$mine instanceof ScalarStats || !$theirs instanceof ScalarStats) {
            return;
        }
        if ($theirs->min !== null && ($mine->min === null || $theirs->min < $mine->min)) {
            $mine->min = $theirs->min;
            $mine->mintime = $theirs->mintime;
        }
        $average = $theirs->avg();
        if ($average !== null && ($mine->max === null || $average > $mine->max)) {
            $mine->max = $average;
            $mine->maxtime = $other->stop;
        }
        if ($theirs->lasttime !== null && ($mine->lasttime === null || $theirs->lasttime >= $mine->lasttime)) {
            $mine->lasttime = $theirs->lasttime;
            $mine->last = $theirs->last;
        }
    }

    /** @param array<string, mixed> $record */
    private function extract(array &$record, string $obsType, Stats $stats): void
    {
        $extractor = $this->policy->of($obsType)->extractor;
        if ($extractor === Extractor::Noop) {
            return;
        }
        if ($extractor === Extractor::Wind) {
            if ($stats instanceof VecStats) {
                // The vector is flattened back into the four columns the schema has.
                $record['windSpeed'] ??= $stats->avg();
                $record['windDir'] ??= $stats->vecDir();
                $record['windGust'] ??= $stats->max;
                $record['windGustDir'] ??= $stats->maxDir;
            }
            return;
        }
        $record[$obsType] = match ($extractor) {
            Extractor::Sum => $stats instanceof ScalarStats && $stats->count > 0 ? $stats->sum : null,
            Extractor::First => $stats instanceof FirstLastStats ? $stats->first : null,
            Extractor::Last => $stats instanceof FirstLastStats ? $stats->last : ($stats instanceof VecStats ? $stats->last : null),
            Extractor::Min => $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->min : null,
            Extractor::Max => $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->max : null,
            Extractor::Count => $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->count : 0,
            Extractor::Avg => $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->avg() : null,
        };
    }

    private function initType(string $obsType): void
    {
        if (isset($this->stats[$obsType])) {
            return;
        }
        $this->stats[$obsType] = match ($this->policy->of($obsType)->accumulator) {
            StatsKind::Scalar => new ScalarStats(),
            StatsKind::Vector => new VecStats(),
            StatsKind::FirstLast => new FirstLastStats(),
        };
    }

    private function checkUnits(mixed $unitSystem): void
    {
        if ($unitSystem === null) {
            return;
        }
        if (!is_int($unitSystem)) {
            throw new AccumError(sprintf('usUnits is not an integer: %s', var_export($unitSystem, true)));
        }
        $incoming = UnitSystem::tryFrom($unitSystem)
            ?? throw new AccumError(sprintf('Unknown unit system %d', $unitSystem));
        if ($this->unitSystem === null) {
            $this->unitSystem = $incoming;
        } elseif ($this->unitSystem !== $incoming) {
            throw new AccumError(sprintf('Unit system mismatch: %d v. %d', $this->unitSystem->value, $incoming->value));
        }
    }
}
