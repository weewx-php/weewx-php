<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use DateTimeZone;
use Exception;
use InvalidArgumentException;
use WeewxPhp\Archive\Derivable;
use WeewxPhp\Archive\How;
use WeewxPhp\Ingest\Store;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\OptionSpec;
use WeewxPhp\Upload\Trigger;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Turns a parsed weewx-php.conf into {@see Config}, checking every setting
 * as it goes.
 *
 * @internal Use {@see Config::load()}.
 */
final class ConfigReader
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';
    private const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const RESERVED_COLUMNS = ['dateTime', 'usUnits', 'interval'];

    private const SETTINGS_KEYS = ['data_dir', 'timezone', 'archive_interval', 'archive_delay', 'loop_hilo',
        'late_packets', 'live_retention', 'raw_retention', 'time_budget', 'max_intervals_per_run',
        'journal_mode', 'tick_token', 'log_level'];
    private const STATION_KEYS = ['name', 'expected_interval', 'stale_after', 'down_after'];
    private const ARCHIVE_KEYS = ['name', 'location', 'latitude', 'longitude', 'altitude', 'database',
        'unit_system', 'timezone', 'primary', 'senders', 'auto_mapping', 'explicit_mapping', 'enabled', 'archive_interval'];
    private const ARCHIVE_SECTIONS = ['members', 'columns', 'fields', 'extractors', 'calculate', 'qc', 'calibrate'];
    private const UPLOAD_KEYS = ['kind', 'archive', 'trigger', 'every', 'catch_up', 'timeout', 'stale'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, string> */
    private array $measurements = [];

    private function __construct(private readonly string $baseDir) {}

    public static function read(ConfFile $file, string $baseDir): Config
    {
        $reader = new self($baseDir);
        $root = $file->root();

        $settings = $reader->settings($root);
        $reader->measurements = self::measurements($root->optionalSection('Measurements'));
        $stations = $reader->stations($root->optionalSection('Stations'));
        // Adoption supplies station metadata; a hand-written entry may refine it.
        $stations += Store::stations($settings);
        $stations += \WeewxPhp\Ingest\CollectorStore::configuredStations($settings);
        $archives = $reader->archives($root->optionalSection('Archives'), $settings, $stations);
        $uploads = $reader->uploads($root->optionalSection('Uploads'), $archives);

        foreach ($root->keys() as $key) {
            if (!in_array($key, self::SETTINGS_KEYS, true) && !in_array($key, ['Stations', 'Archives', 'Uploads', 'Ingest', 'Measurements', 'Sources', 'Admin', 'Themes'], true)) {
                $reader->warnings[] = sprintf('%s: unknown setting, ignored', $key);
            }
        }
        $ingest = $reader->ingest($root->optionalSection('Ingest'));
        return new Config(
            $settings,
            $stations,
            $archives,
            $reader->warnings,
            $uploads,
            $ingest,
            $reader->measurements,
            self::sources($root->optionalSection('Sources'), $reader->measurements),
        );
    }

    /**
     * @return array<string, string> */
    private static function measurements(?Section $section): array
    {
        $result = [];
        foreach ($section?->sections() ?? [] as $name => $one) {
            $kind = $one->value('kind')->string();
            \WeewxPhp\Measurement\Catalog::validate($name, $kind);
            if (in_array(strtolower($name), array_map('strtolower', array_keys($result)), true)) {
                throw new ConfigError('Duplicate measurement name');
            }
            $result[$name] = $kind;
        }
        return $result;
    }

    /** @param array<string, string> $kinds
     * @return array<string, array<string, \WeewxPhp\Measurement\Source>> */
    private static function sources(?Section $section, array $kinds): array
    {
        $result = [];
        foreach ($section?->sections() ?? [] as $station => $fields) {
            $used = [];
            foreach ($fields->sections() as $native => $one) {
                if (!\WeewxPhp\Ingest\Parser::measurementKey($native)) {
                    throw new ConfigError('Invalid source field');
                }
                $name = $one->value('observation')->string();
                if (isset($used[$name])) {
                    throw new ConfigError('Source fields must have distinct observation names');
                }
                $used[$name] = true;
                $result[$station][$native] = new \WeewxPhp\Measurement\Source(
                    $name,
                    $one->value('unit')->string(),
                    $kinds[$name] ?? throw new ConfigError('Define the measurement before its source'),
                );
            }
        }
        return $result;
    }

    private function ingest(?Section $section): IngestConfig
    {
        if ($section === null) {
            return new IngestConfig();
        }
        $this->warnUnknownKeys($section, ['enabled', 'public_url', 'http_ecowitt', 'http_wunderground',
            'trusted_proxies', 'requests_per_minute', 'sender_requests_per_minute', 'max_pending', 'metric_wind', 'tick_mode', 'max_native_receipts'], []);
        $url = rtrim($section->optional('public_url')?->string() ?? '', '/');
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
                throw ConfigError::at($section->pathOf('public_url'), 'expected an HTTP(S) base URL without credentials, query or fragment');
            }
        }
        $proxies = $section->optional('trusted_proxies')?->list() ?? [];
        foreach ($proxies as $proxy) {
            if (filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                throw ConfigError::at($section->pathOf('trusted_proxies'), 'expected exact proxy IP addresses');
            }
        }
        $wind = $section->optional('metric_wind')?->string() ?? 'kph';
        if (!in_array($wind, ['kph', 'mps'], true)) {
            throw ConfigError::at($section->pathOf('metric_wind'), 'expected kph or mps');
        }
        return new IngestConfig(
            enabled: $section->optional('enabled')?->bool() ?? false,
            publicUrl: $url,
            httpEcowitt: $section->optional('http_ecowitt')?->bool() ?? true,
            httpWunderground: $section->optional('http_wunderground')?->bool() ?? true,
            trustedProxies: $proxies,
            requestsPerMinute: self::positiveInt($section, 'requests_per_minute', 300),
            maxPending: self::positiveInt($section, 'max_pending', 100),
            metricWind: $wind,
            senderRequestsPerMinute: self::positiveInt($section, 'sender_requests_per_minute', 120),
            tickMode: $section->optional('tick_mode')?->oneOf(['auto', 'external']) ?? 'auto',
            maxNativeReceipts: self::positiveInt($section, 'max_native_receipts', 2000000),
        );
    }

    private function settings(Section $root): Settings
    {
        $dataDir = $this->absolute($root->optional('data_dir')?->string() ?? 'data', $this->baseDir);

        $interval = $root->optional('archive_interval')?->duration() ?? 300;
        if ($interval < 60 || $interval > 3600 || $interval % 60 !== 0) {
            throw ConfigError::at('archive_interval', 'must be a whole number of minutes between 1m and 1h');
        }
        $delay = $root->optional('archive_delay')?->duration() ?? 15;
        if ($delay < 0 || $delay > 300) {
            throw ConfigError::at('archive_delay', 'must be between 0 and 300 seconds');
        }
        $liveRetention = $root->optional('live_retention')?->duration() ?? 7 * 86400;
        if ($liveRetention < 3600) {
            throw ConfigError::at('live_retention', 'must be at least 1h');
        }
        $rawRetention = $root->optional('raw_retention')?->duration() ?? 3600;
        if ($rawRetention < 0 || $rawRetention > 86400) {
            throw ConfigError::at('raw_retention', 'must be between 0 and 1d');
        }
        $budget = $root->optional('time_budget')?->int() ?? 20;
        if ($budget < 1 || $budget > 3600) {
            throw ConfigError::at('time_budget', 'must be between 1 and 3600 seconds');
        }
        $maxIntervals = $root->optional('max_intervals_per_run')?->int() ?? 100;
        if ($maxIntervals < 1) {
            throw ConfigError::at('max_intervals_per_run', 'must be at least 1');
        }
        $token = $root->optional('tick_token')?->string();
        if ($token === '') {
            $token = null;
        }

        return new Settings(
            dataDir: $dataDir,
            timezone: $this->timezone($root, 'timezone', new DateTimeZone('UTC')),
            archiveInterval: $interval,
            archiveDelay: $delay,
            loopHilo: $root->optional('loop_hilo')?->bool() ?? true,
            latePackets: self::enum($root, 'late_packets', LatePackets::class, LatePackets::Ignore),
            liveRetention: $liveRetention,
            rawRetention: $rawRetention,
            timeBudget: $budget,
            maxIntervalsPerRun: $maxIntervals,
            journalMode: self::enum($root, 'journal_mode', JournalMode::class, JournalMode::Wal),
            tickToken: $token,
            logLevel: self::logLevel($root),
        );
    }

    /**
     * @return array<string, StationConfig> */
    private function stations(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $stations = [];
        foreach ($section->sections() as $id => $one) {
            self::requireId($one, $id);
            $this->warnUnknownKeys($one, self::STATION_KEYS, []);
            $expected = $one->optional('expected_interval')?->duration();
            if ($expected !== null && $expected < 1) {
                throw ConfigError::at($one->pathOf('expected_interval'), 'must be at least 1 second');
            }
            $stations[$id] = new StationConfig(
                id: $id,
                name: $one->optional('name')?->string() ?? $id,
                expectedInterval: $expected,
                staleAfter: self::positiveInt($one, 'stale_after', 3),
                downAfter: self::positiveInt($one, 'down_after', 20),
            );
        }
        foreach ($section->values() as $key => $value) {
            $this->warnings[] = sprintf('%s: a station is a section, not a value; ignored', $value->path());
        }
        return $stations;
    }

    /**
     * @param array<string, StationConfig> $stations
     *
     *
     * @return array<string, ArchiveConfig>
     */
    private function archives(?Section $section, Settings $settings, array $stations): array
    {
        if ($section === null) {
            return [];
        }
        $archives = [];
        foreach ($section->sections() as $id => $one) {
            $archives[$id] = $this->archive($id, $one, $settings, $stations);
        }
        foreach ($section->values() as $key => $value) {
            $this->warnings[] = sprintf('%s: an archive is a section, not a value; ignored', $value->path());
        }
        return $archives;
    }

    /** @param array<string, StationConfig> $stations */
    private function archive(string $id, Section $one, Settings $settings, array $stations): ArchiveConfig
    {
        self::requireId($one, $id);
        $this->warnUnknownKeys($one, self::ARCHIVE_KEYS, self::ARCHIVE_SECTIONS);
        $name = $one->optional('name')?->string() ?? $id;

        $senders = null;
        $sendersValue = $one->optional('senders');
        if ($sendersValue !== null) {
            $listed = $sendersValue->list();
            if ($listed !== ['*']) {
                $senders = [];
                foreach ($listed as $sender) {
                    $this->requireStation($stations, $sender, $sendersValue->path());
                    $senders[] = $sender;
                }
            }
        }
        $primary = $one->optional('primary')?->string();
        if ($primary !== null) {
            $this->requireStation($stations, $primary, $one->pathOf('primary'));
            if ($senders !== null && !in_array($primary, $senders, true)) {
                throw ConfigError::at($one->pathOf('primary'), sprintf('%s is not among the senders this archive reads', $primary));
            }
        }

        $interval = $one->optional('archive_interval')?->duration();
        if ($interval !== null && ($interval < 60 || $interval > 3600 || $interval % 60 !== 0)) {
            throw ConfigError::at($one->pathOf('archive_interval'), 'expected whole minutes between 1m and 1h');
        }
        $explicit = $one->optional('explicit_mapping')?->bool() ?? false;
        if ($explicit && $senders === null) {
            throw ConfigError::at($one->pathOf('senders'), 'explicit mapping requires an explicit station list');
        }
        return new ArchiveConfig(
            id: $id,
            name: $name,
            location: $one->optional('location')?->string() ?? $name,
            latitude: self::coordinate($one, 'latitude', 90.0),
            longitude: self::coordinate($one, 'longitude', 180.0),
            altitude: self::altitude($one),
            database: $this->absolute($one->optional('database')?->string() ?? 'archives/' . $id . '.sdb', $settings->dataDir),
            unitSystem: self::unitSystem($one, 'unit_system') ?? UnitSystem::US,
            timezone: $this->timezone($one, 'timezone', $settings->timezone),
            primary: $primary,
            senders: $senders,
            autoMapping: $one->optional('auto_mapping')?->bool() ?? false,
            indoor: $this->members($one->optionalSection('members'), $stations),
            columns: self::columns($one->optionalSection('columns')),
            fields: $this->fields($one->optionalSection('fields'), $stations),
            extractors: self::extractors($one->optionalSection('extractors')),
            calculate: $this->calculate($one->optionalSection('calculate')),
            qcUnitSystem: self::unitSystem($one->optionalSection('qc'), 'unit_system'),
            qc: self::qc($one->optionalSection('qc')),
            calibrate: $this->calibrate($one->optionalSection('calibrate'), $stations),
            explicitMapping: $explicit,
            enabled: $one->optional('enabled')?->bool() ?? true,
            archiveInterval: $interval,
            measurementKinds: $this->measurements,
        );
    }

    /**
     * @param array<string, StationConfig> $stations
     *
     *
     * @return array<string, bool>
     */
    private function members(?Section $section, array $stations): array
    {
        if ($section === null) {
            return [];
        }
        $indoor = [];
        foreach ($section->sections() as $sender => $member) {
            $this->requireStation($stations, $sender, $member->path());
            $this->warnUnknownKeys($member, ['indoor'], []);
            $indoor[$sender] = $member->optional('indoor')?->bool() ?? true;
        }
        return $indoor;
    }

    /**
     * @return array<string, ColumnType> */
    private static function columns(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $columns = [];
        foreach ($section->values() as $name => $value) {
            self::requireColumnName($value->path(), $name);
            try {
                $columns[$name] = ColumnType::fromName($value->string());
            } catch (InvalidArgumentException $error) {
                throw ConfigError::at($value->path(), $error->getMessage());
            }
        }
        return $columns;
    }

    /**
     * @param array<string, StationConfig> $stations
     *
     *
     * @return array<string, array<string, string>>
     */
    private function fields(?Section $section, array $stations): array
    {
        if ($section === null) {
            return [];
        }
        $fields = [];
        foreach ($section->sections() as $sender => $placements) {
            $this->requireStation($stations, $sender, $placements->path());
            foreach ($placements->values() as $raw => $value) {
                $target = $value->string();
                if ($target !== '-') {
                    self::requireColumnName($value->path(), $target);
                }
                $fields[$sender][$raw] = $target;
            }
        }
        foreach ($section->values() as $key => $value) {
            $this->warnings[] = sprintf('%s: a placement belongs under the sender it applies to; ignored', $value->path());
        }
        return $fields;
    }

    /**
     * @return array<string, Extractor> */
    private static function extractors(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $extractors = [];
        foreach ($section->values() as $obsType => $value) {
            $extractors[$obsType] = Extractor::from($value->oneOf(array_column(Extractor::cases(), 'value')));
        }
        return $extractors;
    }

    /**
     * @return array<string, How> */
    private function calculate(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $calculate = [];
        foreach ($section->values() as $obsType => $value) {
            $how = How::from($value->oneOf(array_column(How::cases(), 'value')));
            if (!Derivable::knows($obsType)) {
                $this->warnings[] = sprintf('%s: nothing here can derive %s; ignored', $value->path(), $obsType);
                continue;
            }
            $calculate[$obsType] = $how;
        }
        return $calculate;
    }

    /**
     * @return array<string, QcRule> */
    private static function qc(?Section $section): array
    {
        if ($section === null) {
            return [];
        }
        $rules = [];
        foreach ($section->values() as $obsType => $value) {
            if ($obsType === 'unit_system') {
                continue;
            }
            $parts = $value->list();
            if (count($parts) < 2 || count($parts) > 3) {
                throw ConfigError::at($value->path(), 'expected minimum, maximum and optionally a unit');
            }
            $limits = array_map(static fn(string $text): float => self::number($value->path(), $text), array_slice($parts, 0, 2));
            if ($limits[0] > $limits[1]) {
                throw ConfigError::at($value->path(), 'the minimum is above the maximum');
            }
            $unit = $parts[2] ?? null;
            if ($unit !== null) {
                // A unit is converted into the record's on every check, which
                // takes a unit group WeeWX knows the type by.
                $group = Units::groupOf($obsType);
                if ($group === null) {
                    throw ConfigError::at($value->path(), sprintf('WeeWX knows no unit group for %s, so its limits cannot name a unit', $obsType));
                }
                if (!Units::canConvert($unit, Units::standardUnit(UnitSystem::US, $group))) {
                    throw ConfigError::at($value->path(), sprintf('%s is not a unit of %s', $unit, $group));
                }
            }
            $rules[$obsType] = new QcRule($obsType, $limits[0], $limits[1], $unit);
        }
        return $rules;
    }

    /**
     * @param array<string, StationConfig> $stations
     *
     *
     * @return array<string, array<string, Calibration>>
     */
    private function calibrate(?Section $section, array $stations): array
    {
        if ($section === null) {
            return [];
        }
        $calibrate = [];
        foreach ($section->sections() as $sender => $corrections) {
            $this->requireStation($stations, $sender, $corrections->path());
            foreach ($corrections->values() as $obsType => $value) {
                $parts = $value->list();
                if (count($parts) < 1 || count($parts) > 2) {
                    throw ConfigError::at($value->path(), 'expected an offset and optionally a scale');
                }
                $calibrate[$sender][$obsType] = new Calibration(
                    self::number($value->path(), $parts[0]),
                    isset($parts[1]) ? self::number($value->path(), $parts[1]) : 1.0,
                );
            }
        }
        foreach ($section->values() as $key => $value) {
            $this->warnings[] = sprintf('%s: a correction belongs under the sender it applies to; ignored', $value->path());
        }
        return $calibrate;
    }

    /**
     * @param array<string, ArchiveConfig> $archives
     *
     *
     * @return array<string, UploadConfig>
     */
    private function uploads(?Section $section, array $archives): array
    {
        if ($section === null) {
            return [];
        }
        $uploads = [];
        foreach ($section->sections() as $id => $one) {
            self::requireId($one, $id);
            $kindValue = $one->optional('kind');
            if ($kindValue === null) {
                throw ConfigError::at($one->pathOf('kind'), 'is needed: one of ' . implode(', ', Kind::names()));
            }
            $kind = Kind::from($kindValue->oneOf(Kind::names()));
            $this->warnUnknownKeys($one, [...self::UPLOAD_KEYS, ...array_keys($kind->spec())], []);

            $trigger = self::enum($one, 'trigger', Trigger::class, $kind->defaultTrigger());
            if ($trigger === Trigger::Live && !$kind->allowsLive()) {
                throw ConfigError::at($one->pathOf('trigger'), 'live is for mqtt only; a weather service takes one reading per record');
            }
            $every = $one->optional('every')?->duration() ?? $kind->defaultEvery();
            if ($every < 60 || $every > 86400) {
                throw ConfigError::at($one->pathOf('every'), 'must be between 1m and 1d');
            }
            $catchUp = $one->optional('catch_up')?->int() ?? $kind->defaultCatchUp();
            if ($catchUp < 0 || $catchUp > $kind->maxCatchUp()) {
                throw ConfigError::at($one->pathOf('catch_up'), sprintf('must be between 0 and %d', $kind->maxCatchUp()));
            }
            $timeout = $one->optional('timeout')?->duration() ?? $kind->defaultTimeout();
            if ($timeout < 1 || $timeout > $kind->maxTimeout()) {
                throw ConfigError::at($one->pathOf('timeout'), sprintf('must be between 1 and %d seconds', $kind->maxTimeout()));
            }
            $stale = $one->optional('stale')?->duration() ?? $kind->defaultStale();
            if ($stale < 60) {
                throw ConfigError::at($one->pathOf('stale'), 'must be at least 1m');
            }

            $uploads[$id] = new UploadConfig(
                id: $id,
                kind: $kind,
                archive: self::uploadArchive($one, $archives),
                trigger: $trigger,
                every: $every,
                catchUp: $catchUp,
                timeout: $timeout,
                stale: $stale,
                options: self::uploadOptions($one, $kind),
            );
        }
        foreach ($section->values() as $key => $value) {
            $this->warnings[] = sprintf('%s: an upload is a section, not a value; ignored', $value->path());
        }
        return $uploads;
    }

    /**
     * Whose readings an upload sends. With one archive there is nothing to
     * choose; with two, a registration with a weather service is for one
     * spot and the coordinates sent along come from it, so it has to be said.
     *
     * @param array<string, ArchiveConfig> $archives
     */
    private static function uploadArchive(Section $one, array $archives): string
    {
        $named = $one->optional('archive')?->string();
        if ($named === null) {
            if (count($archives) === 1) {
                return array_key_first($archives) ?? '';
            }
            throw ConfigError::at($one->pathOf('archive'), sprintf(
                'is needed: there are %d archives, and a registration is for one place',
                count($archives),
            ));
        }
        if (!isset($archives[$named])) {
            throw ConfigError::at($one->pathOf('archive'), sprintf('%s is not an archive', $named));
        }
        return $named;
    }

    /**
     * The kind's own settings, each read as its spec says and filled in
     * with its default when absent.
     *
     *
     * @return array<string, string|int|float|bool|list<string>|null>
     */
    private static function uploadOptions(Section $one, Kind $kind): array
    {
        $options = [];
        foreach ($kind->spec() as $key => $spec) {
            $value = $one->optional($key);
            if ($value === null) {
                if ($spec->required) {
                    throw ConfigError::at($one->pathOf($key), sprintf('is needed for a %s upload', $kind->value));
                }
                $options[$key] = $spec->default;
                continue;
            }
            $options[$key] = match ($spec->type) {
                OptionSpec::BOOL => $value->bool(),
                OptionSpec::INT => $value->int(),
                OptionSpec::FLOAT => $value->float(),
                OptionSpec::LIST => $value->list(),
                OptionSpec::CHOICE => $value->oneOf($spec->choices),
                default => $value->string(),
            };
            if ($spec->required && $options[$key] === '') {
                throw ConfigError::at($value->path(), 'must not be empty');
            }
        }
        return $options;
    }

    private static function coordinate(Section $section, string $key, float $limit): ?float
    {
        $value = $section->optional($key)?->float();
        if ($value !== null && ($value < -$limit || $value > $limit)) {
            throw ConfigError::at($section->pathOf($key), sprintf('must be between -%s and %s', $limit, $limit));
        }
        return $value;
    }

    /**
     * `altitude = 440, meter` as WeeWX writes it, or `1443, foot`; a bare
     * number is metres.
     */
    private static function altitude(Section $section): ?Altitude
    {
        $value = $section->optional('altitude');
        if ($value === null) {
            return null;
        }
        $parts = $value->list();
        if (count($parts) > 2) {
            throw ConfigError::at($value->path(), 'expected a height and optionally a unit');
        }
        $height = self::number($value->path(), $parts[0]);
        $unit = $parts[1] ?? 'meter';
        if (!in_array($unit, Altitude::UNITS, true)) {
            throw ConfigError::at($value->path(), sprintf('the unit must be meter or foot, not %s', var_export($unit, true)));
        }
        return new Altitude($height, $unit);
    }

    private static function unitSystem(?Section $section, string $key): ?UnitSystem
    {
        $value = $section?->optional($key);
        if ($value === null) {
            return null;
        }
        try {
            return UnitSystem::fromName($value->string());
        } catch (InvalidArgumentException) {
            throw ConfigError::at($value->path(), 'expected US, METRIC or METRICWX');
        }
    }

    private function timezone(Section $section, string $key, DateTimeZone $default): DateTimeZone
    {
        $value = $section->optional($key);
        if ($value === null) {
            return $default;
        }
        try {
            return new DateTimeZone($value->string());
        } catch (Exception) {
            throw ConfigError::at($value->path(), sprintf('unknown time zone %s', var_export($value->string(), true)));
        }
    }

    private static function logLevel(Section $root): LogLevel
    {
        $value = $root->optional('log_level');
        if ($value === null) {
            return LogLevel::Info;
        }
        try {
            return LogLevel::fromName($value->string());
        } catch (InvalidArgumentException) {
            throw ConfigError::at($value->path(), 'expected debug, info, warning or error');
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     * @param T $default
     *
     *
     * @return T
     */
    private static function enum(Section $section, string $key, string $enum, \BackedEnum $default): \BackedEnum
    {
        $value = $section->optional($key);
        if ($value === null) {
            return $default;
        }
        $allowed = array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases());
        return $enum::from($value->oneOf($allowed));
    }

    private static function positiveInt(Section $section, string $key, int $default): int
    {
        $value = $section->optional($key)?->int() ?? $default;
        if ($value < 1) {
            throw ConfigError::at($section->pathOf($key), 'must be at least 1');
        }
        return $value;
    }

    private static function number(string $path, string $text): float
    {
        $text = trim($text);
        if (!is_numeric($text)) {
            throw ConfigError::at($path, sprintf('expected a number, got %s', var_export($text, true)));
        }
        return (float) $text;
    }

    private static function requireId(Section $section, string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw ConfigError::at($section->path(), 'the name may hold letters, digits, "_", "." and "-" and must start with a letter or digit');
        }
    }

    private static function requireColumnName(string $path, string $name): void
    {
        if (preg_match(self::COLUMN_PATTERN, $name) !== 1) {
            throw ConfigError::at($path, sprintf('%s is not a usable column name', var_export($name, true)));
        }
        if (in_array($name, self::RESERVED_COLUMNS, true)) {
            throw ConfigError::at($path, sprintf('%s is not a column a reading can go to', $name));
        }
    }

    /** @param array<string, StationConfig> $stations */
    private function requireStation(array $stations, string $sender, string $path): void
    {
        if (!array_key_exists($sender, $stations)) {
            throw ConfigError::at($path, sprintf('%s is not a station in [Stations]', $sender));
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $sections
     */
    private function warnUnknownKeys(Section $section, array $keys, array $sections): void
    {
        foreach ($section->keys() as $key) {
            $known = $section->isSection($key) ? in_array($key, $sections, true) : in_array($key, $keys, true);
            if (!$known) {
                $this->warnings[] = sprintf('%s: unknown setting, ignored', $section->pathOf($key));
            }
        }
    }

    private function absolute(string $path, string $base): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            return $path;
        }
        return rtrim($base, '/\\') . '/' . $path;
    }
}
