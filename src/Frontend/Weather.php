<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Config;
use WeewxPhp\Time\Clock;
use WeewxPhp\Time\SystemClock;
use WeewxPhp\Version;
use WeewxPhp\Weewx\Units;

/** Theme entry point. No archive writer, scheduler run or arbitrary SQL is exposed here. */
final class Weather
{
    private ?ArchiveReader $reader = null;
    private ?Cache $cache = null;
    /** @var array<string, Value|Series|Report> */
    private array $memo = [];
    private readonly Clock $clock;
    private readonly ReadBudget $budget;
    private readonly ArchiveConfig $archiveConfig;
    private readonly int $now;

    public function __construct(
        private readonly Config $config,
        ?string $archive = null,
        ?Clock $clock = null,
        ?ReadBudget $budget = null,
        private readonly int $weekStart = 6,
        private readonly int $rainStart = 1,
        private readonly ?Output $outputProfile = null,
        private readonly string $reference = 'legacy',
        private readonly ?int $referenceAt = null,
        private readonly bool $preparedOnly = false,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->now = $this->clock->now();
        $this->budget = $budget ?? new ReadBudget();
        $id = $archive ?? array_key_first($config->archives);
        $this->archiveConfig = $id === null ? throw new QueryError('No archive configured') : ($config->archive($id) ?? throw new QueryError('Unknown archive: ' . $id));
    }

    public static function open(string $configPath, ?string $archive = null): self
    {
        return new self(Config::load($configPath), $archive);
    }

    public function configuration(): ArchiveConfig
    {
        return $this->archiveConfig;
    }

    public function archive(string $id): self
    {
        return new self($this->config, $id, $this->clock, $this->budget, $this->weekStart, $this->rainStart, $this->outputProfile, $this->reference, $this->referenceAt, $this->preparedOnly);
    }

    public function calendar(int $weekStart = 6, int $rainYearStart = 1): self
    {
        return new self($this->config, $this->archiveConfig->id, $this->clock, $this->budget, $weekStart, $rainYearStart, $this->outputProfile, $this->reference, $this->referenceAt, $this->preparedOnly);
    }

    public function output(Output $profile): self
    {
        return new self($this->config, $this->archiveConfig->id, $this->clock, $this->budget, $this->weekStart, $this->rainStart, $profile, $this->reference, $this->referenceAt, $this->preparedOnly);
    }

    public function reference(string $reference, int|string|null $at = null): self
    {
        $stamp = $at === null ? null : Span::timestamp($at, $this->archiveConfig->timezone);
        (new Spec(reference: $reference, referenceAt: $stamp))->validate();
        return new self($this->config, $this->archiveConfig->id, $this->clock, $this->budget, $this->weekStart, $this->rainStart, $this->outputProfile, $reference, $stamp, $this->preparedOnly);
    }

    public function cacheOnly(bool $enabled = true): self
    {
        return new self($this->config, $this->archiveConfig->id, $this->clock, $this->budget, $this->weekStart, $this->rainStart, $this->outputProfile, $this->reference, $this->referenceAt, $enabled);
    }

    public function context(Spec $spec): Spec
    {
        $copy = clone $spec;
        if ($copy->reference === 'legacy') {
            $copy->reference = $this->reference;
            $copy->referenceAt = $this->referenceAt;
        }
        return $copy;
    }

    public function present(Value|Series|Report $result, string $observation): Value|Series|Report
    {
        return $this->outputProfile?->apply($result, $observation) ?? $result;
    }

    public static function anchor(Spec $spec, int $now, ?int $last): int
    {
        return match ($spec->reference) {
            'fixed' => $spec->referenceAt ?? $now,
            'clock' => $now,
            'archive' => min($now, $last ?? $now),
            default => $spec->period === 'almanac' ? $now : min($now, $last ?? $now),
        };
    }

    public function today(): Period
    {
        return $this->reference('clock')->day();
    }

    /** Exactly N calendar dates, including the reference day. */
    public function days(int $count = 7): Period
    {
        if ($count < 1 || $count > 2048) {
            throw new QueryError('Calendar day count must be 1..2048');
        }
        return new Period($this, new Spec(calendarDays: $count, weekStart: $this->weekStart, rainStart: $this->rainStart));
    }

    public function defaultAggregate(string $observation): string
    {
        if (in_array($observation, ['windvec', 'windgustvec', 'heatdeg', 'cooldeg', 'growdeg'], true)) {
            return 'avg';
        }
        $kind = \WeewxPhp\Measurement\Catalog::kind($observation, $this->archiveConfig->measurementKinds);
        return match ($kind) {
            'rain', 'energy' => 'sum',
            'rain_counter' => 'last',
            'direction' => throw new QueryError('Use wind vecdir for a speed-weighted direction'),
            default => 'weighted_avg',
        };
    }

    public function current(string $observation, ?int $at = null, int $maxDelta = 0): Query
    {
        return new Query($this, new Spec(period: 'current', priority: 10, end: $at, observation: $observation, aggregate: 'value', maxDelta: $maxDelta));
    }

    public function latest(string $observation): Query
    {
        return new Query($this, new Spec(period: 'latest', observation: $observation, aggregate: 'value', priority: 10));
    }
    public function hour(int $ago = 0): Period
    {
        return $this->period('hour', $ago);
    }
    public function day(int $ago = 0): Period
    {
        return $this->period('day', $ago);
    }
    public function yesterday(): Period
    {
        return $this->day(1);
    }
    public function week(int $ago = 0): Period
    {
        return $this->period('week', $ago);
    }
    public function month(int $ago = 0): Period
    {
        return $this->period('month', $ago);
    }
    public function season(int $ago = 0): Period
    {
        return $this->period('season', $ago);
    }
    public function year(int $ago = 0): Period
    {
        return $this->period('year', $ago);
    }
    public function rainyear(int $ago = 0): Period
    {
        return $this->period('rainyear', $ago);
    }
    public function seasonsyear(int $ago = 0): Period
    {
        return $this->period('seasonsyear', $ago);
    }
    public function alltime(): Period
    {
        return $this->period('alltime');
    }

    public function period(string $name, int $ago = 0): Period
    {
        return new Period($this, new Spec(period: $name, ago: $ago, weekStart: $this->weekStart, rainStart: $this->rainStart));
    }

    public function last(int|string $duration): Period
    {
        return new Period($this, new Spec(period: 'last', duration: Span::seconds($duration), weekStart: $this->weekStart, rainStart: $this->rainStart));
    }

    public function between(int|string $start, int|string $end): Period
    {
        return new Period($this, new Spec(period: 'between', start: Span::timestamp($start, $this->archiveConfig->timezone), end: Span::timestamp($end, $this->archiveConfig->timezone), weekStart: $this->weekStart, rainStart: $this->rainStart));
    }

    /** One complete named calendar day, month or year. */
    public function on(string $date): Period
    {
        if (preg_match('/^\d{4}$/D', $date) === 1) {
            $start = $date . '-01-01';
            $increment = '+1 year';
        } elseif (preg_match('/^\d{4}-\d{2}$/D', $date) === 1) {
            $start = $date . '-01';
            $increment = '+1 month';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
            $start = $date;
            $increment = '+1 day';
        } else {
            throw new QueryError('Use YYYY, YYYY-MM or YYYY-MM-DD');
        }
        $timestamp = Span::timestamp($start, $this->archiveConfig->timezone);
        return $this->between($timestamp, Span::date($timestamp, $this->archiveConfig->timezone)->modify($increment)->getTimestamp());
    }

    public function trend(string $observation, int|string $over = '3h', int $maxDelta = 300): Query
    {
        return new Query($this, new Spec(period: 'current', observation: $observation, aggregate: 'trend', duration: Span::seconds($over), maxDelta: $maxDelta));
    }

    public function time(int $timestamp): Value
    {
        return new Value($timestamp, 'unix_epoch', 'group_time', timezone: $this->archiveConfig->timezone->getName());
    }

    public function almanac(?int $at = null): Almanac
    {
        return new Almanac($this, new Spec(period: 'almanac', end: $at, aggregate: 'value', refresh: '5m'));
    }

    /** @return array<string, int|float|string|Value|null> */
    public function station(): array
    {
        return ['id' => $this->archiveConfig->id, 'name' => $this->archiveConfig->name,
            'location' => $this->archiveConfig->location, 'latitude' => $this->archiveConfig->latitude,
            'longitude' => $this->archiveConfig->longitude,
            'altitude' => $this->archiveConfig->altitude === null ? null : new Value($this->archiveConfig->altitude->value, $this->archiveConfig->altitude->unit, 'group_altitude'),
            'week_start' => $this->weekStart, 'rain_year_start' => $this->rainStart,
            'version' => Version::STRING, 'software' => Version::NAME, 'php_version' => PHP_VERSION];
    }

    /** @return array{type: string|null, label: string, format: string} */
    public function unit(string $observation): array
    {
        Catalog::identifier($observation);
        [$unit, $group] = Units::unitOf($this->reader()->units, $observation, $this->archiveConfig->groups());
        $target = $this->outputProfile->units[$observation] ?? $this->outputProfile->units[$group ?? ''] ?? $unit;
        if ($target !== null) {
            (new Value(null, $unit, $group))->to($target);
        }
        $places = $this->outputProfile->decimals[$observation] ?? $this->outputProfile->decimals[$target ?? '']
            ?? $this->outputProfile->decimals[$group ?? ''] ?? ($group === 'group_count' ? 0 : 1);
        return ['type' => $target, 'label' => $this->outputProfile->labels[$target ?? ''] ?? Value::label($target), 'format' => '%.' . $places . 'f'];
    }

    /** @return list<string> */
    public function observations(): array
    {
        return $this->reader()->columns;
    }

    /** @param array<string, Query> $queries */
    public function dataset(array $queries): Dataset
    {
        return new Dataset($queries);
    }

    /** @return array<string, mixed> */
    public function measurement(string $observation): array
    {
        Catalog::identifier($observation);
        $reader = $this->reader();
        $stored = in_array($observation, $reader->columns, true);
        $dependencies = match ($observation) {
            'dewpoint', 'heatindex', 'humidex' => ['outTemp', 'outHumidity'],
            'inDewpoint' => ['inTemp', 'inHumidity'],
            'windchill', 'windrun' => ['outTemp', 'windSpeed'],
            'appTemp' => ['outTemp', 'outHumidity', 'windSpeed'],
            'cloudbase' => ['outTemp', 'outHumidity'],
            'rainRate' => ['rain'],
            'ET' => ['outTemp', 'outHumidity', 'windSpeed', 'radiation'],
            'wind', 'windvec' => ['windSpeed', 'windDir'], 'windgustvec' => ['windGust', 'windGustDir'],
            'heatdeg', 'cooldeg', 'growdeg' => ['outTemp'],
            default => [],
        };
        $missing = array_values(array_diff($dependencies, $reader->columns));
        $derived = !$stored && $reader->has($observation);
        [$unit, $group] = Units::unitOf($reader->units, $observation, $this->archiveConfig->groups());
        $latest = $reader->has($observation) ? $this->current($observation)->get() : new Value(null);
        $measured = $latest instanceof Value && $latest->raw !== null;
        $kind = \WeewxPhp\Measurement\Catalog::kind($observation, $this->archiveConfig->measurementKinds);
        return ['name' => $observation, 'kind' => $kind, 'unit' => $unit, 'group' => $group,
            'stored' => $stored, 'derivable' => $derived, 'dependencies' => $dependencies,
            'missingDependencies' => $missing, 'availability' => $measured ? 'measured' : ($stored ? 'missing' : ($derived && $missing === [] ? 'derivable' : 'unavailable')),
            'asOf' => $reader->last, 'age' => $reader->last === null ? null : max(0, $this->now - $reader->last),
            'defaultAggregate' => $kind === 'direction' ? 'vecdir' : $this->defaultAggregate($observation),
            'output' => $this->unit($observation), 'aggregates' => Catalog::CORE];
    }

    /** Bounded LOOP snapshot. Live quantities never feed archive sums or overwrite archive extremes. */
    public function live(string $observation, int $maxAge = 120): Value
    {
        Catalog::identifier($observation);
        if ($maxAge < 0 || $maxAge > 86400) {
            throw new QueryError('Live maxAge must be 0..86400');
        }
        [$unit, $group] = Units::unitOf($this->archiveConfig->unitSystem, $observation, $this->archiveConfig->groups());
        $value = new Value(null, $unit, $group, 'unavailable');
        $path = $this->config->settings->liveDbPath();
        if (!is_file($path)) {
            $result = $this->present($value, $observation);
            return $result instanceof Value ? $result : $value;
        }
        $live = \WeewxPhp\Live\LiveDb::readOnly($path);
        try {
            $this->budget->statement();
            $this->budget->source('live');
            $mapping = new \WeewxPhp\Archive\Mapping(
                $this->archiveConfig,
                \WeewxPhp\Archive\Mapping::resolvePrimary($this->archiveConfig, $live->senders()),
            );
            $quality = \WeewxPhp\Archive\Quality::fromConfig($this->archiveConfig);
            foreach ($live->recent($this->now) as $packet) {
                $this->budget->row();
                $record = $mapping->place($packet);
                if ($record === null) {
                    continue;
                }
                $record = Units::toSystem($record, $this->archiveConfig->unitSystem, $this->archiveConfig->groups());
                $record = $quality->check($quality->calibrate($record, $packet->sender));
                $number = Accumulator::number($record[$observation] ?? null);
                if ($number !== null) {
                    $value = new Value($number, $unit, $group, $this->now - $packet->dateTime > $maxAge ? 'stale' : 'ready', $packet->dateTime, $this->now, timezone: $this->archiveConfig->timezone->getName());
                    break;
                }
            }
        } catch (Deferred) {
            $value = new Value(null, $unit, $group, 'pending');
        } finally {
            $live->close();
        }
        $result = $this->present($value, $observation);
        return $result instanceof Value ? $result : $value;
    }

    public function resolve(Spec $spec): Span
    {
        $reader = $this->reader();
        return (new Computation($spec, $reader, self::anchor($spec, $this->now, $reader->last)))->span;
    }

    public function register(Spec $spec): string
    {
        $spec->validate();
        return $this->cache()->register($this->archiveConfig->id, $spec, $this->now, true);
    }

    /** Named data.php definitions can be registered by CLI without rendering their templates.
     * @param array<string, Query> $queries
     * @return array<string, Query>
     */
    public function datasets(array $queries): array
    {
        foreach ($queries as $query) {
            $query->register();
        }
        return $queries;
    }

    /** @param array<string, Query> $queries
     * @return array<string, string>
     */
    public function syncTheme(string $theme, array $queries): array
    {
        $definitions = [];
        foreach ($queries as $name => $query) {
            $definitions[$name] = $query->definition();
        }
        return $this->cache()->sync($theme, $definitions, $this->now);
    }

    public function deactivateTheme(string $theme): void
    {
        $this->cache()->sync($theme, [], $this->now);
    }

    /** @var array<string, array<string, mixed>> */
    private array $trace = [];

    /** Query diagnostics for this page. Only expose in development.
     * @return array<string, array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->trace;
    }

    public function execute(Spec $spec): Value|Series|Report
    {
        $spec->validate();
        $this->budget->resetSources();
        $before = $this->budget->snapshot();
        $id = Cache::key($this->archiveConfig->id, $spec);
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        $cache = $this->cache();
        $cache->register($this->archiveConfig->id, $spec, $this->now);
        $saved = $cache->request($id);
        $pending = $cache->pending($this->archiveConfig->id);
        $payload = is_string($saved['payload'] ?? null) ? $saved['payload'] : null;
        $due = Cache::integer($saved['due'] ?? null);
        $this->trace[$id] = ['source' => 'cache', 'cacheHit' => $payload !== null, 'rows' => 0, 'statements' => 0, 'nextRefresh' => $due, 'error' => $saved['error'] ?? null];
        if ($payload !== null && $due > $this->now && !$pending) {
            return $this->memo[$id] = ResultCodec::decode($payload, $this->archiveConfig->timezone->getName());
        }
        if (!$this->preparedOnly && $spec->analysis === '' && !$pending && $payload === null && !is_string($saved['work'] ?? null)) {
            $work = null;
            $revision = $cache->revision($this->archiveConfig->id);
            $token = ArchiveReader::fingerprint($this->archiveConfig->database);
            try {
                $reader = $this->reader();
                $work = new Computation($spec, $reader, self::anchor($spec, $this->now, $reader->last));
                do {
                    $finished = $work->step($this->budget, $cache);
                } while (!$finished);
                if ($token !== ArchiveReader::fingerprint($this->archiveConfig->database) || $revision !== $cache->revision($this->archiveConfig->id) || $cache->pending($this->archiveConfig->id)) {
                    $cache->discardChunks($this->archiveConfig->id, $work->span);
                    $cache->observe($this->archiveConfig);
                    throw new Deferred('Archive changed during computation');
                }
                if (!$cache->publish($id, $work, $this->now, self::nextDue($work, $this->now, $this->archiveConfig, $this->config->settings->archiveInterval), $revision)) {
                    throw new Deferred('Archive changed before publication');
                }
                $after = $this->budget->snapshot();
                $this->trace[$id] = $after + ['cacheHit' => false, 'nextRefresh' => self::nextDue($work, $this->now, $this->archiveConfig, $this->config->settings->archiveInterval), 'error' => null];
                $this->trace[$id]['rows'] = $after['rows'] - $before['rows'];
                $this->trace[$id]['milliseconds'] = $after['milliseconds'] - $before['milliseconds'];
                $this->trace[$id]['statements'] = $after['statements'] - $before['statements'];
                $cache->diagnose($id, $this->trace[$id], $this->now);
                return $this->memo[$id] = $work->result($this->now);
            } catch (Deferred) {
                if ($work !== null && $token === ArchiveReader::fingerprint($this->archiveConfig->database) && $revision === $cache->revision($this->archiveConfig->id) && !$cache->pending($this->archiveConfig->id)) {
                    $cache->progress($id, $work, $this->now, $revision);
                } elseif ($work !== null) {
                    $cache->discardChunks($this->archiveConfig->id, $work->span);
                }
            }
        }
        if (!$this->preparedOnly && $spec->analysis === '') {
            $after = $this->budget->snapshot();
            $this->trace[$id]['rows'] = $after['rows'] - $before['rows'];
            $this->trace[$id]['milliseconds'] = $after['milliseconds'] - $before['milliseconds'];
            $this->trace[$id]['sources'] = $after['sources'];
        }
        return $this->memo[$id] = $payload !== null
            ? ResultCodec::decode($payload, $this->archiveConfig->timezone->getName(), 'stale')
            : $this->placeholder($spec);
    }

    private function placeholder(Spec $spec): Value|Series|Report
    {
        [$unit, $group] = $spec->period === 'almanac' ? Astronomy::units($spec->observation)
            : Units::unitOf($this->archiveConfig->unitSystem, $spec->observation, $this->archiveConfig->groups());
        $group = Catalog::group($spec->aggregate, $group);
        $unit = $group === null ? $unit : Units::standardUnit($this->archiveConfig->unitSystem, $group);
        if ($spec->analysis !== '') {
            return new Report(new Series([], $unit, $group, 'pending'), [], [], 'pending');
        }
        return $spec->every === null ? new Value(null, $unit, $group, 'pending') : new Series([], $unit, $group, 'pending');
    }

    public static function nextDue(Computation $work, int $now, ArchiveConfig $archive, int $interval): int
    {
        if ($work->spec->period === 'almanac' && $work->spec->end !== null) {
            return PHP_INT_MAX;
        }
        if ($work->spec->period === 'between' && $work->span->end <= $work->asOf && !str_starts_with($work->spec->aggregate, 'historical_') && $work->spec->analysis !== 'compare_month') {
            return PHP_INT_MAX;
        }
        return (new Refresh($work->spec->refresh))->next($now, $archive->timezone, $archive->archiveInterval ?? $interval);
    }

    private function cache(): Cache
    {
        if ($this->cache === null) {
            $this->cache = new Cache($this->config->settings);
            $this->cache->observe($this->archiveConfig);
        }
        return $this->cache;
    }

    private function reader(): ArchiveReader
    {
        return $this->reader ??= new ArchiveReader($this->archiveConfig, $this->budget);
    }

    public function close(): void
    {
        $this->reader?->close();
        $this->cache?->close();
        $this->reader = null;
        $this->cache = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
