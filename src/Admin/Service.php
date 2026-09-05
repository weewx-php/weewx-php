<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\Mapping;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\Section;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\Store;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Measurement\Catalog;
use WeewxPhp\Weewx\Extractor;

/** User commands, independent of HTTP, templates and CLI argument parsing. */
final class Service
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly ?string $themeDirectory = null) {}

    /** @param array<string, mixed> $input */
    public function execute(string $action, array $input): void
    {
        $read = new ReadModel($this->path);
        if (in_array($action, ['station.adopt', 'station.reject', 'station.restore'], true)) {
            $id = Input::id(Input::text($input, 'station'));
            $live = LiveDb::open($read->config->settings->liveDbPath(), $read->config->settings->journalMode);
            $store = Store::open($read->config->settings);
            try {
                $native = $live->collector();
                $name = Input::text($input, 'name');
                $name = $name === '' ? null : Input::label($name);
                if ($native->sender($id) !== null) {
                    $native->setState($id, match ($action) {
                        'station.adopt' => 'adopted', 'station.reject' => 'ignored', default => 'pending',
                    }, $name);
                } elseif ($store->sender($id) !== null) {
                    if ($action === 'station.adopt') {
                        $store->adopt($id, $name, $this->now);
                    } else {
                        $store->setState($id, $action === 'station.reject' ? 'ignored' : 'pending');
                    }
                } else {
                    throw new Problem('error.station');
                }
            } finally {
                $store->close();
                $live->close();
            }
            $auth = new Auth($read->config->settings);
            try {
                $auth->audit($action, $this->now, $id);
            } finally {
                $auth->close();
            }
            return;
        }
        if ($action === 'maintenance.queue') {
            (new Jobs($this->path, $this->now))->queue($input);
            return;
        }
        (new Changes($this->path, $this->now))->apply(
            Input::text($input, 'revision'),
            $action,
            function (ConfFile $file, Config $config) use ($action, $input): void {
                match ($action) {
                    'archive.create', 'archive.connect' => $this->createArchive($file, $config, $input, $action === 'archive.connect'),
                    'archive.save' => $this->saveArchive($file, $config, $input),
                    'station.rename' => $this->renameStation($file, $config, $input),
                    'mapping.save' => $this->saveMapping($file, $config, $input),
                    'mapping.save_all' => $this->saveAllMappings($file, $config, $input),
                    'mapping.explicit' => $this->explicit($file, $config, $input),
                    'column.create' => $this->column($file, $config, $input),
                    'field.define' => $this->source($file, $config, $input),
                    'theme.save' => $this->theme($file, $config, $input),
                    'settings.save' => $this->settings($file, $input),
                    'upload.save' => $this->upload($file, $input),
                    default => throw new Problem('error.action', status: 404),
                };
            },
        );
    }

    public static function section(Section $parent, string $name): Section
    {
        return $parent->optionalSection($name) ?? $parent->addSection($name);
    }

    /** @param array<string, mixed> $input */
    private function renameStation(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'station');
        if ($config->station($id) === null) {
            throw new Problem('error.station');
        }
        self::section(self::section($file->root(), 'Stations'), $id)->set('name', Input::label(Input::text($input, 'name')));
    }

    /** @param array<string, mixed> $input */
    private function createArchive(ConfFile $file, Config $config, array $input, bool $connect): void
    {
        $id = Input::id(Input::text($input, 'archive'));
        if ($config->archive($id) !== null) {
            throw new Problem('error.exists', 'archive');
        }
        $directory = $config->settings->dataDir . '/archives';
        if (!$connect && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Problem('error.path');
        }
        $path = DatabasePath::resolve($config->settings->dataDir, $connect ? Input::text($input, 'database') : 'archives/' . $id . '.sdb', $connect);
        if (!$connect && file_exists($path)) {
            throw new Problem('error.exists', 'archive');
        }
        $one = self::section($file->root(), 'Archives')->addSection($id);
        $one->set('database', $path);
        $one->set('explicit_mapping', 'true');
        $one->set('senders', []);
        $one->set('enabled', 'false');
        foreach (['name', 'timezone', 'unit_system', 'archive_interval'] as $key) {
            $one->set($key, $key === 'name' ? Input::label(Input::text($input, $key)) : Input::text($input, $key));
        }
    }

    /** @param array<string, mixed> $input */
    private function saveArchive(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'archive');
        $archive = $config->archive($id) ?? throw new Problem('error.archive');
        $one = $file->root()->section('Archives')->section($id);
        $one->set('name', Input::label(Input::text($input, 'name')));
        foreach (['location', 'latitude', 'longitude', 'altitude'] as $key) {
            $value = Input::text($input, $key);
            if ($value === '') {
                $one->remove($key);
            } else {
                $one->set($key, $key === 'altitude' ? array_map('trim', explode(',', $value)) : $value);
            }
        }
        $one->set('enabled', Input::text($input, 'enabled') === 'true' ? 'true' : 'false');
        $one->set('timezone', Input::text($input, 'timezone', $archive->timezone->getName()));
        $one->set('archive_interval', Input::text($input, 'archive_interval', (string) $archive->interval($config->settings)));
        $senders = Input::strings($input, 'senders');
        foreach ($senders as $sender) {
            if ($config->station($sender) === null) {
                throw new Problem('error.station');
            }
        }
        $one->set('senders', $senders);
        if ($archive->primary !== null && !in_array($archive->primary, $senders, true)) {
            $one->remove('primary');
        }
        $fields = $one->optionalSection('fields');
        foreach ($fields?->sections() ?? [] as $sender => $unused) {
            if (!in_array($sender, $senders, true)) {
                $fields?->remove($sender);
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function saveMapping(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'archive');
        $archive = $config->archive($id) ?? throw new Problem('error.archive');
        if (!$archive->explicitMapping) {
            throw new Problem('error.explicit_required');
        }
        $station = Input::text($input, 'station');
        if (!$archive->selects($station)) {
            throw new Problem('error.station');
        }
        $assignments = $input['mapping'] ?? [];
        $confirmations = $input['history'] ?? [];
        if (!is_array($assignments) || !is_array($confirmations) || count($assignments) > 256) {
            throw new Problem('error.input');
        }
        $known = [];
        foreach ((new ReadModel($this->path))->fields($station) as $field) {
            if (is_string($field['observation'])) {
                $known[$field['observation']] = true;
            }
        }
        foreach ($config->sources[$station] ?? [] as $source) {
            $known[$source->observation] = true;
        }
        $fields = self::section(self::section($file->root()->section('Archives')->section($id), 'fields'), $station);
        foreach ($assignments as $source => $target) {
            if (!is_string($source) || !is_string($target) || (!isset($known[$source]) && !isset($archive->fields[$station][$source]))) {
                throw new Problem('error.source');
            }
            if ($target === '') {
                $fields->remove($source);
            } else {
                if ($target !== '-') {
                    Catalog::identifier($target);
                    $previous = $archive->fields[$station][$source] ?? null;
                    if ($previous !== $target && Input::text($input, 'continue_history') !== 'true' && ($confirmations[$source] ?? null) !== $target && $this->populated($archive->database, $target)) {
                        throw new Problem('error.history');
                    }
                }
                $fields->set($source, $target);
            }
        }
    }

    /** Create columns and assign sources as one validated archive change.
     * @param array<string, mixed> $input
     */
    private function saveAllMappings(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'archive');
        $archive = $config->archive($id) ?? throw new Problem('error.archive');
        if (!$archive->explicitMapping) {
            throw new Problem('error.explicit_required');
        }
        $assignments = $input['mapping'] ?? [];
        $columns = $input['columns'] ?? [];
        $confirmations = $input['history'] ?? [];
        if (!is_array($assignments) || !is_array($columns) || !is_array($confirmations) || count($assignments) > 2000) {
            throw new Problem('error.input');
        }
        foreach ($assignments as $station => $rows) {
            if (!is_string($station) || !$archive->selects($station) || !is_array($rows) || count($rows) > 256) {
                throw new Problem('error.station');
            }
            $mapping = [];
            foreach ($rows as $source => $target) {
                if (!is_string($source) || !is_string($target)) {
                    throw new Problem('error.input');
                }
                $native = str_starts_with($source, 'native:') ? substr($source, 7) : null;
                if ($target === '__new__') {
                    $stationColumns = $columns[$station] ?? [];
                    $draft = is_array($stationColumns) ? ($stationColumns[$source] ?? null) : null;
                    if (!is_array($draft)) {
                        throw new Problem('error.input');
                    }
                    $draft = \WeewxPhp\Db\Json::object(json_encode($draft, JSON_THROW_ON_ERROR));
                    $name = Input::text($draft, 'column');
                    $kind = $native === null ? Catalog::kind($source, $config->measurements) : Input::text($draft, 'kind');
                    if ($kind === null) {
                        throw new Problem('error.source');
                    }
                    if ($native !== null) {
                        $this->source($file, $config, ['station' => $station, 'native' => $native, 'observation' => $name, 'kind' => $kind, 'unit' => Input::text($draft, 'unit')]);
                        $source = $name;
                    }
                    $this->column($file, $config, ['archive' => $id, 'kind' => $kind] + $draft);
                    $resolved = realpath($this->path);
                    $config = \WeewxPhp\Config\ConfigReader::read($file, dirname($resolved === false ? $this->path : $resolved));
                    $target = $name;
                } elseif ($native !== null) {
                    if ($target !== '') {
                        throw new Problem('error.source');
                    }
                    continue;
                }
                $mapping[$source] = $target;
            }
            $this->saveMapping($file, $config, ['archive' => $id, 'station' => $station, 'mapping' => $mapping,
                'continue_history' => Input::text($input, 'continue_history'), 'history' => $confirmations[$station] ?? []]);
        }
    }

    private function populated(string $database, string $column): bool
    {
        Catalog::identifier($column);
        $db = Sqlite::readOnly($database);
        try {
            if (!in_array($column, array_column($db->columns('archive'), 'name'), true)) {
                return false;
            }
            return $db->scalar('SELECT 1 FROM archive WHERE "' . $column . '" IS NOT NULL LIMIT 1') !== null;
        } finally {
            $db->close();
        }
    }

    /** @param array<string, mixed> $input */
    private function explicit(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'archive');
        $archive = $config->archive($id) ?? throw new Problem('error.archive');
        $read = new ReadModel($this->path);
        $senders = $archive->senders ?? array_keys($config->stations);
        $mapping = new Mapping($archive, $read->primary($archive));
        $schema = ReadModel::archive($archive)['schema'];
        $one = $file->root()->section('Archives')->section($id);
        $one->set('explicit_mapping', 'true');
        $one->set('senders', $senders);
        $fields = self::section($one, 'fields');
        foreach ($senders as $station) {
            $names = array_keys($archive->fields[$station] ?? []);
            foreach ($read->fields($station) as $field) {
                if (is_string($field['observation'])) {
                    $names[] = $field['observation'];
                }
            }
            foreach (array_unique($names) as $source) {
                $target = $mapping->target($station, $source);
                if ($target !== null && $schema->hasColumn($target)) {
                    self::section($fields, $station)->set($source, $target);
                }
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function column(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'archive');
        $archive = $config->archive($id) ?? throw new Problem('error.archive');
        $name = Input::text($input, 'column');
        $kind = Input::text($input, 'kind');
        Catalog::validate($name, $kind);
        if ((isset($config->measurements[$name]) && $config->measurements[$name] !== $kind) || isset($archive->columns[$name])) {
            throw new Problem('error.column_type', 'column');
        }
        $schema = ReadModel::archive($archive)['schema'];
        foreach ($schema->columns as $existing) {
            if (strtolower($existing) === strtolower($name)) {
                throw new Problem('error.exists', 'column');
            }
        }
        $type = Input::text($input, 'storage_type', 'REAL');
        $aggregation = Input::text($input, 'aggregation', Catalog::lastKind($kind) ? 'last' : 'avg');
        if (!in_array($type, ['REAL', 'INTEGER'], true) || !in_array($aggregation, ['avg', 'sum', 'min', 'max', 'last', 'first'], true)
            || Extractor::tryFrom($aggregation) === null || (Catalog::lastKind($kind) && $aggregation !== 'last')
            || ($kind === 'direction')) {
            throw new Problem('error.aggregation');
        }
        $measurements = self::section($file->root(), 'Measurements');
        $definition = self::section($measurements, $name);
        $definition->set('kind', $kind);
        $definition->set('label', Input::text($input, 'label', $name));
        $one = $file->root()->section('Archives')->section($id);
        self::section($one, 'columns')->set($name, $type);
        self::section($one, 'extractors')->set($name, $aggregation);
    }

    /** @param array<string, mixed> $input */
    private function source(ConfFile $file, Config $config, array $input): void
    {
        $station = Input::text($input, 'station');
        if ($config->station($station) === null) {
            throw new Problem('error.station');
        }
        $native = Input::text($input, 'native');
        $found = false;
        foreach ((new ReadModel($this->path))->fields($station) as $field) {
            if ($field['native'] === $native && $field['observation'] === null) {
                $found = true;
            }
        }
        if (!$found) {
            throw new Problem('error.source');
        }
        $name = Input::text($input, 'observation');
        $kind = Input::text($input, 'kind');
        Catalog::validate($name, $kind);
        if (\WeewxPhp\Weewx\Units::groupOf($name) !== null || (isset($config->measurements[$name]) && $config->measurements[$name] !== $kind)) {
            throw new Problem('error.source');
        }
        $unit = Input::text($input, 'unit');
        Catalog::validateUnit($kind, $unit);
        self::section(self::section($file->root(), 'Measurements'), $name)->set('kind', $kind);
        $source = self::section(self::section(self::section($file->root(), 'Sources'), $station), $native);
        $source->set('observation', $name);
        $source->set('unit', $unit);
    }

    /** @param array<string, mixed> $input */
    private function theme(ConfFile $file, Config $config, array $input): void
    {
        $id = Input::text($input, 'theme');
        $registry = new ThemeRegistry($this->themeDirectory ?? dirname(__DIR__, 2) . '/themes');
        $values = $registry->validate($id, $input, $config);
        $themes = self::section($file->root(), 'Themes');
        if (Input::text($input, 'activate') === 'true') {
            $themes->set('active', $id);
        }
        $one = self::section($themes, $id);
        $one->set('schema_version', '1');
        foreach ($values as $key => $value) {
            $one->set($key, $value);
        }
    }

    /** @param array<string, mixed> $input */
    private function settings(ConfFile $file, array $input): void
    {
        foreach (['timezone', 'archive_delay', 'live_retention', 'raw_retention', 'time_budget', 'max_intervals_per_run'] as $key) {
            $value = Input::text($input, $key);
            if ($value !== '') {
                $file->root()->set($key, $value);
            }
        }
        $language = Input::text($input, 'language', 'en');
        if ((new Translator($language))->language !== $language) {
            throw new Problem('error.input', 'language');
        }
        self::section($file->root(), 'Admin')->set('language', $language);
    }

    /** @param array<string, mixed> $input */
    private function upload(ConfFile $file, array $input): void
    {
        $id = Input::id(Input::text($input, 'upload'));
        $one = self::section(self::section($file->root(), 'Uploads'), $id);
        $kind = \WeewxPhp\Upload\Kind::tryFrom(Input::text($input, 'kind')) ?? throw new Problem('error.input', 'kind');
        if ($one->optional('kind') !== null && $one->value('kind')->string() !== $kind->value) {
            throw new Problem('error.input', 'kind');
        }
        foreach (['kind', 'archive', 'trigger', 'every', 'catch_up', 'timeout', 'stale'] as $key) {
            $value = Input::text($input, $key);
            if ($value !== '') {
                $one->set($key, $value);
            }
        }
        foreach ($kind->spec() as $key => $spec) {
            $value = Input::text($input, $key);
            if ($value === '' && $spec->type === \WeewxPhp\Upload\OptionSpec::SECRET) {
                continue;
            }
            if ($value === '') {
                $one->remove($key);
            } else {
                $one->set($key, $spec->type === \WeewxPhp\Upload\OptionSpec::LIST ? array_map('trim', explode(',', $value)) : $value);
            }
        }
    }
}
