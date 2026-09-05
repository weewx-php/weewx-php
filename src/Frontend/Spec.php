<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** Serializable query recipe. Presentation is deliberately outside the cache key. */
final class Spec
{
    public function __construct(
        public string $period = 'day',
        public int $ago = 0,
        public ?int $start = null,
        public ?int $end = null,
        public int $duration = 86400,
        public string $observation = 'outTemp',
        public string $aggregate = 'avg',
        public int|string|null $every = null,
        public ?float $threshold = null,
        public ?string $thresholdUnit = null,
        public string $refresh = 'archive',
        public bool $completed = false,
        public float $coverage = 0,
        public int $maxDelta = 0,
        public int $weekStart = 6,
        public int $rainStart = 1,
        public string $body = 'sun',
        public ?string $otherBody = null,
        public float $horizon = 0,
        public float $temperature = 15,
        public float $pressure = 1010,
        public bool $useCenter = false,
        public int $daysAgo = 1,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $elevation = null,
        public string $reference = 'legacy',
        public ?int $referenceAt = null,
        public int $calendarDays = 0,
        public int $priority = 0,
        public string $analysis = '',
        public int $limit = 10,
        public bool $ascending = false,
        public int $month = 0,
        public string $operator = 'gt',
        public int $pointLimit = 2048,
        public float $quantile = 0.5,
    ) {}

    public function validate(): void
    {
        Catalog::identifier($this->observation);
        Catalog::check($this->aggregate);
        new Refresh($this->refresh);
        if (!in_array($this->period, ['almanac', 'current', 'latest', 'hour', 'day', 'week', 'month', 'season', 'year', 'rainyear', 'seasonsyear', 'alltime', 'between', 'last'], true)
            || $this->ago < 0 || $this->ago > 10000 || $this->duration < 1
            || $this->coverage < 0 || $this->coverage > 1 || !is_finite($this->coverage)
            || $this->maxDelta < 0 || $this->maxDelta > 86400) {
            throw new QueryError('Invalid query options');
        }
        if (!in_array($this->reference, ['legacy', 'clock', 'archive', 'fixed'], true)
            || ($this->reference === 'fixed' && $this->referenceAt === null)
            || $this->calendarDays < 0 || $this->calendarDays > 2048 || $this->priority < -10 || $this->priority > 10
            || !in_array($this->analysis, ['', 'rank', 'compare_month', 'spell', 'last_event', 'quantile'], true)
            || !is_finite($this->quantile) || $this->quantile < 0 || $this->quantile > 1
            || $this->limit < 1 || $this->limit > 2048 || $this->month < 0 || $this->month > 12
            || !in_array($this->operator, ['gt', 'ge', 'lt', 'le'], true) || $this->pointLimit < 1 || $this->pointLimit > 50000) {
            throw new QueryError('Invalid reference or analysis options');
        }
        if ($this->analysis !== '' && ($this->every === null || $this->every === 'archive')) {
            throw new QueryError('Analysis requires aggregated intervals');
        }
        if ($this->month > 0 && $this->every !== 'month') {
            throw new QueryError('calendarMonth requires monthly intervals');
        }
        if ($this->weekStart < 0 || $this->weekStart > 6 || $this->rainStart < 1 || $this->rainStart > 12) {
            throw new QueryError('Invalid calendar settings');
        }
        if ($this->period === 'almanac') {
            Astronomy::validate($this);
        }
        if ($this->period === 'between' && ($this->start === null || $this->end === null || $this->start >= $this->end)) {
            throw new QueryError('An explicit period needs start < end');
        }
        if ($this->threshold !== null && !is_finite($this->threshold)) {
            throw new QueryError('Threshold must be finite');
        }
        if (preg_match('/_(ge|gt|le|lt)$/D', $this->aggregate) === 1 && $this->threshold === null) {
            throw new QueryError('This aggregate requires a threshold');
        }
        if ($this->every !== null && (!is_string($this->every) || !in_array($this->every, ['hour', 'day', 'week', 'month', 'season', 'year', 'archive'], true))) {
            Span::seconds($this->every);
        }
        if ($this->aggregate === 'cumulative' && ($this->every === null || $this->every === 'archive')) {
            throw new QueryError('Cumulative series require an aggregation interval');
        }
    }

    public function json(): string
    {
        $this->validate();
        return json_encode(get_object_vars($this), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new QueryError('Invalid saved query');
        }
        // Named constructor arguments retain PHP's strict validation, with no object deserialization.
        $allowed = array_keys(get_object_vars(new self()));
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new QueryError('Unknown saved query option');
            }
        }
        $spec = new self(
            period: self::string($data['period'] ?? 'day'),
            ago: self::integer($data['ago'] ?? 0),
            start: isset($data['start']) ? self::integer($data['start']) : null,
            end: isset($data['end']) ? self::integer($data['end']) : null,
            duration: self::integer($data['duration'] ?? 86400),
            observation: self::string($data['observation'] ?? 'outTemp'),
            aggregate: self::string($data['aggregate'] ?? 'avg'),
            every: isset($data['every']) ? (is_int($data['every']) ? $data['every'] : self::string($data['every'])) : null,
            threshold: isset($data['threshold']) ? self::number($data['threshold']) : null,
            thresholdUnit: isset($data['thresholdUnit']) ? self::string($data['thresholdUnit']) : null,
            refresh: self::string($data['refresh'] ?? 'archive'),
            completed: self::boolean($data['completed'] ?? false),
            coverage: self::number($data['coverage'] ?? 0),
            maxDelta: self::integer($data['maxDelta'] ?? 0),
            weekStart: self::integer($data['weekStart'] ?? 6),
            rainStart: self::integer($data['rainStart'] ?? 1),
            body: self::string($data['body'] ?? 'sun'),
            otherBody: isset($data['otherBody']) ? self::string($data['otherBody']) : null,
            horizon: self::number($data['horizon'] ?? 0),
            temperature: self::number($data['temperature'] ?? 15),
            pressure: self::number($data['pressure'] ?? 1010),
            useCenter: self::boolean($data['useCenter'] ?? false),
            daysAgo: self::integer($data['daysAgo'] ?? 1),
            latitude: isset($data['latitude']) ? self::number($data['latitude']) : null,
            longitude: isset($data['longitude']) ? self::number($data['longitude']) : null,
            reference: self::string($data['reference'] ?? 'legacy'),
            referenceAt: isset($data['referenceAt']) ? self::integer($data['referenceAt']) : null,
            calendarDays: self::integer($data['calendarDays'] ?? 0),
            priority: self::integer($data['priority'] ?? 0),
            analysis: self::string($data['analysis'] ?? ''),
            limit: self::integer($data['limit'] ?? 10),
            ascending: self::boolean($data['ascending'] ?? false),
            month: self::integer($data['month'] ?? 0),
            operator: self::string($data['operator'] ?? 'gt'),
            quantile: self::number($data['quantile'] ?? 0.5),
            pointLimit: self::integer($data['pointLimit'] ?? 2048),
            elevation: isset($data['elevation']) ? self::number($data['elevation']) : null,
        );
        $spec->validate();
        return $spec;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new QueryError('Expected a string in saved query');
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new QueryError('Expected an integer in saved query');
    }

    private static function number(mixed $value): float
    {
        return Accumulator::number($value) ?? throw new QueryError('Expected a number in saved query');
    }

    private static function boolean(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new QueryError('Expected a boolean in saved query');
    }
}
