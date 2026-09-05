<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Changes;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Admin\DatabasePath;
use WeewxPhp\Admin\Page;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Admin\ThemeRegistry;
use WeewxPhp\Admin\Translator;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\Receiver;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;

final class AdminTest extends TestCase
{
    private const NOW = 1788609600;
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('admin');
        $this->path = $this->dir . '/weather.conf';
        file_put_contents($this->path, 'data_dir = ' . $this->dir . "\ntimezone = UTC\narchive_delay = 0\n[Ingest]\n    enabled = true\n    public_url = https://weather.example\n");
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @param array<string, mixed> $input */
    private function command(string $action, array $input, int $now = self::NOW): void
    {
        $read = new ReadModel($this->path);
        (new Service($this->path, $now))->execute($action, $input + ['revision' => $read->revision]);
    }

    private function upload(string $identity, int $now, string $values): string
    {
        $runtime = Runtime::boot($this->path, new FixedClock($now), new MemoryLogger());
        try {
            $key = $runtime->ingest()->credentials()['ecowitt'];
            $body = 'PASSKEY=' . $identity . '&tempf=68&' . $values;
            $response = (new Receiver($runtime))->handle('POST', '/' . $key . '/ecowitt/', '', static fn(): string => $body, '127.0.0.1', true);
            self::assertSame(200, $response->status, $response->body);
            foreach ($runtime->ingest()->senders() as $sender) {
                if ($sender->identity === $identity) {
                    return $sender->id;
                }
            }
            self::fail('Sender was not discovered');
        } finally {
            $runtime->close();
        }
    }

    private function archive(string $id = 'garden', int $interval = 300): void
    {
        $this->command('archive.create', ['archive' => $id, 'name' => ucfirst($id), 'unit_system' => 'METRICWX', 'timezone' => 'UTC', 'archive_interval' => (string) $interval]);
    }

    /** @param list<string> $stations */
    private function enable(array $stations, string $id = 'garden'): void
    {
        $this->command('archive.save', ['archive' => $id, 'name' => ucfirst($id), 'enabled' => 'true', 'senders' => $stations]);
    }

    private function tick(int $now): void
    {
        $runtime = Runtime::boot($this->path, new FixedClock($now), new MemoryLogger());
        try {
            self::assertSame('ok', (new Tick($runtime))->run('test')->status);
        } finally {
            $runtime->close();
        }
    }

    public function testCompleteWorkflowCustomUnitsIndependentStationsAndRevisions(): void
    {
        $first = $this->upload(str_repeat('A', 32), self::NOW, 'probe=86&api_token=12345');
        $second = $this->upload(str_repeat('B', 32), self::NOW, 'soiltemp1f=50');
        $this->command('station.adopt', ['station' => $first, 'name' => 'Garden']);
        $this->command('station.adopt', ['station' => $second, 'name' => 'Greenhouse']);
        $this->archive();
        $this->enable([$first, $second]);
        $this->command('field.define', ['station' => $first, 'native' => 'probe', 'observation' => 'probeTemp', 'kind' => 'temperature', 'unit' => 'degree_F']);
        $this->command('column.create', ['archive' => 'garden', 'column' => 'greenhouseTemp', 'kind' => 'temperature', 'aggregation' => 'avg']);
        $this->command('mapping.save', ['archive' => 'garden', 'station' => $first, 'mapping' => ['outTemp' => 'outTemp', 'probeTemp' => 'greenhouseTemp']]);
        $this->command('mapping.save', ['archive' => 'garden', 'station' => $second, 'mapping' => ['outTemp' => 'extraTemp1']]);
        $this->upload(str_repeat('A', 32), self::NOW + 310, 'probe=86');
        $this->upload(str_repeat('B', 32), self::NOW + 320, 'soiltemp1f=50');
        $this->tick(self::NOW + 610);
        $config = Config::load($this->path);
        $archive = $config->archive('garden');
        self::assertNotNull($archive);
        $db = ArchiveDb::open($archive->database, $config->settings->journalMode, new Policy(), $archive->timezone);
        try {
            $record = $db->record(self::NOW + 600);
            self::assertNotNull($record);
            self::assertEqualsWithDelta(20.0, $record['outTemp'], 1e-10);
            self::assertEqualsWithDelta(20.0, $record['extraTemp1'], 1e-10);
            self::assertEqualsWithDelta(30.0, $record['greenhouseTemp'], 1e-10);
            self::assertArrayHasKey('greenhouseTemp', $db->schema()->dayTypes);
        } finally {
            $db->close();
        }
        $this->command('mapping.save', ['archive' => 'garden', 'station' => $second, 'mapping' => ['outTemp' => 'extraTemp2']], self::NOW + 650);
        $this->upload(str_repeat('B', 32), self::NOW + 920, 'soiltemp1f=50');
        $this->tick(self::NOW + 1210);
        $runtime = Runtime::boot($this->path, new FixedClock(self::NOW + 1300), new MemoryLogger());
        try {
            $current = $runtime->config->archive('garden');
            self::assertNotNull($current);
            $archiver = \WeewxPhp\Archive\Archiver::open($current, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, self::NOW + 1300);
            try {
                $rebuilt = $archiver->build(self::NOW + 600);
                self::assertNotNull($rebuilt);
                self::assertArrayHasKey('extraTemp1', $rebuilt->record);
                self::assertArrayNotHasKey('extraTemp2', $rebuilt->record);
                self::assertNotNull($archiver->archive()->record(self::NOW + 1200));
            } finally {
                $archiver->close();
            }
        } finally {
            $runtime->close();
        }
    }

    public function testDiscoveryRemembersIntermittentFieldsAndExcludesSecrets(): void
    {
        $station = $this->upload(str_repeat('A', 32), self::NOW, 'probe=12&secret=55&PASSWORD=66&uptime=33');
        $this->upload(str_repeat('A', 32), self::NOW + 10, 'humidity=70');
        $fields = (new ReadModel($this->path))->fields($station);
        $names = array_column($fields, 'native');
        self::assertContains('probe', $names);
        self::assertNotContains('secret', $names);
        self::assertNotContains('PASSWORD', $names);
        self::assertNotContains('PASSKEY', $names);
        self::assertNotContains('uptime', $names);
        self::assertFileDoesNotExist($this->dir . '/live.sdb');
        $this->command('station.reject', ['station' => $station]);
        $this->upload(str_repeat('A', 32), self::NOW + 20, 'probe=100');
        foreach ((new ReadModel($this->path))->fields($station) as $field) {
            self::assertNull($field['value']);
        }
    }

    public function testIndependentIntervalsAndNewFieldsStayUnmapped(): void
    {
        $station = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $this->command('station.adopt', ['station' => $station]);
        foreach (['short' => 300, 'long' => 600] as $id => $interval) {
            $this->archive($id, $interval);
            $this->enable([$station], $id);
            $this->command('mapping.save', ['archive' => $id, 'station' => $station, 'mapping' => ['outTemp' => 'outTemp']]);
        }
        $this->upload(str_repeat('A', 32), self::NOW + 610, 'humidity=70');
        $this->upload(str_repeat('A', 32), self::NOW + 920, 'humidity=80');
        $this->tick(self::NOW + 1210);
        $config = Config::load($this->path);
        foreach (['short' => 2, 'long' => 1] as $id => $count) {
            $archive = $config->archive($id);
            self::assertNotNull($archive);
            $db = Sqlite::readOnly($archive->database);
            try {
                self::assertSame($count, $db->scalar('SELECT COUNT(*) FROM archive'));
                self::assertSame(0, $db->scalar('SELECT COUNT(outHumidity) FROM archive'));
            } finally {
                $db->close();
            }
        }
    }

    public function testStaleSaveDoesNotChangeConfiguration(): void
    {
        $this->archive();
        $before = file_get_contents($this->path);
        try {
            $this->command('settings.save', ['revision' => str_repeat('0', 64), 'timezone' => 'Europe/Berlin']);
            self::fail('Stale revision accepted');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testDuplicateTargetsFailBeforeSaving(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $b = $this->upload(str_repeat('B', 32), self::NOW, 'humidity=70');
        $this->command('station.adopt', ['station' => $a]);
        $this->command('station.adopt', ['station' => $b]);
        $this->archive();
        $this->enable([$a, $b]);
        $this->command('mapping.save', ['archive' => 'garden', 'station' => $a, 'mapping' => ['outTemp' => 'outTemp']]);
        $before = file_get_contents($this->path);
        try {
            $this->command('mapping.save', ['archive' => 'garden', 'station' => $b, 'mapping' => ['outTemp' => 'outTemp']]);
            self::fail('Collision accepted');
        } catch (\WeewxPhp\Archive\MappingError) {
            self::assertSame($before, file_get_contents($this->path));
        }
    }

    public function testAuthCsrfRotationExpiryAndThrottle(): void
    {
        $auth = new Auth(Config::load($this->path)->settings);
        try {
            $auth->setPassword('correct horse battery staple', self::NOW);
            $anonymous = $auth->session(null, self::NOW);
            $logged = $auth->login($anonymous['token'], $anonymous['csrf'], 'correct horse battery staple', '127.0.0.1', self::NOW);
            self::assertTrue($logged['authenticated']);
            self::assertNotSame($anonymous['token'], $logged['token']);
            self::assertFalse($auth->session($anonymous['token'], self::NOW)['authenticated']);
            self::assertFalse($auth->session($logged['token'], self::NOW + 7201)['authenticated']);
            $anonymous = $auth->session(null, self::NOW);
            for ($i = 0; $i < 5; ++$i) {
                try {
                    $auth->login($anonymous['token'], $anonymous['csrf'], 'incorrect password', '192.0.2.1', self::NOW);
                    self::fail('Bad password accepted');
                } catch (Problem $error) {
                    self::assertSame(403, $error->status);
                }
            }
            $this->expectException(Problem::class);
            $this->expectExceptionMessage('error.rate_limit');
            $auth->login($anonymous['token'], $anonymous['csrf'], 'correct horse battery staple', '192.0.2.1', self::NOW);
        } finally {
            $auth->close();
        }
    }

    public function testRoutesRequireAuthenticationAndEscapeStationNames(): void
    {
        $station = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $this->command('station.adopt', ['station' => $station, 'name' => '<script>alert(1)</script>']);
        $this->archive();
        $this->enable([$station]);
        $controller = new Controller($this->path, self::NOW);
        self::assertSame(403, $controller->handle('GET', [], [], null, '127.0.0.1', false)->status);
        $auth = new Auth(Config::load($this->path)->settings);
        try {
            $auth->setPassword('correct horse battery staple', self::NOW);
            $session = $auth->session(null, self::NOW);
            $anonymous = $controller->handle('POST', ['page' => 'stations'], ['csrf' => $session['csrf'], 'action' => 'station.reject', 'station' => $station], $session['token'], '127.0.0.1', true);
            self::assertSame(403, $anonymous->status);
            $session = $auth->login($session['token'], $session['csrf'], 'correct horse battery staple', '127.0.0.1', self::NOW);
            self::assertSame(403, $controller->handle('POST', [], ['action' => 'station.reject'], $session['token'], '127.0.0.1', true)->status);
            foreach (Page::PAGES as $page) {
                $response = $controller->handle('GET', ['page' => $page, 'archive' => 'garden'], [], $session['token'], '127.0.0.1', true);
                self::assertSame(200, $response->status, $page . ': ' . $response->body);
                self::assertStringNotContainsString('<script>alert', $response->body);
                $dom = new DOMDocument();
                @$dom->loadHTML($response->body);
                $xpath = new DOMXPath($dom);
                $nested = $xpath->query('//form//form');
                $main = $xpath->query('//main');
                self::assertNotFalse($nested);
                self::assertNotFalse($main);
                self::assertSame(0, $nested->length);
                self::assertSame(1, $main->length);
            }
            self::assertSame(404, $controller->handle('GET', ['page' => 'missing'], [], $session['token'], '127.0.0.1', true)->status);
        } finally {
            $auth->close();
        }
    }

    public function testThemeValidationAndTranslationFallback(): void
    {
        mkdir($this->dir . '/themes/example', 0700, true);
        file_put_contents($this->dir . '/themes/example/settings.json', json_encode(['theme' => 'example', 'schema_version' => 1, 'fields' => [
            ['key' => 'show_wind', 'type' => 'boolean', 'label' => 'settings.wind', 'default' => true],
            ['key' => 'days', 'type' => 'integer', 'label' => 'settings.days', 'default' => 3, 'min' => 1, 'max' => 10],
        ]], JSON_THROW_ON_ERROR));
        $registry = new ThemeRegistry($this->dir . '/themes');
        self::assertSame(['show_wind' => 'false', 'days' => '4'], $registry->validate('example', ['show_wind' => 'false', 'days' => '4'], Config::load($this->path)));
        $de = new Translator('de');
        self::assertSame('Felder', $de->text('nav.fields'));
        self::assertSame('2 Felder', $de->text('count.fields', count: 2));
        self::assertSame('Fields', (new Translator('missing'))->text('nav.fields'));
        $this->expectException(Problem::class);
        $registry->validate('example', ['days' => '100'], Config::load($this->path));
    }

    public function testPathTraversalAndDuplicateDatabaseAreRejected(): void
    {
        $this->archive();
        try {
            DatabasePath::resolve($this->dir, '../escape.sdb');
            self::fail('Traversal accepted');
        } catch (Problem $error) {
            self::assertSame('error.path', $error->getMessage());
        }
        $this->expectException(Problem::class);
        $this->command('archive.connect', ['archive' => 'duplicate', 'database' => 'archives/garden.sdb', 'name' => 'Duplicate', 'unit_system' => 'METRICWX', 'timezone' => 'UTC', 'archive_interval' => '300']);
    }

    public function testReadOnlyPagesDoNotCreateArchives(): void
    {
        file_put_contents($this->path, "\n[Archives]\n    [[missing]]\n        database = archives/missing.sdb\n", FILE_APPEND);
        $read = new ReadModel($this->path);
        $html = (new Page($read, new Translator(), 'csrf'))->render('overview', []);
        self::assertStringContainsString('missing', $html);
        self::assertFileDoesNotExist($this->dir . '/archives/missing.sdb');
    }

    public function testArchiveWideSaveCreatesColumnsAndDefinesUnknownSourceAtomically(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'probe=86&humidity=70');
        $b = $this->upload(str_repeat('B', 32), self::NOW, 'soiltemp1f=50');
        $this->command('station.adopt', ['station' => $a, 'name' => 'Garden']);
        $this->command('station.adopt', ['station' => $b, 'name' => 'Greenhouse']);
        $this->archive();
        $this->enable([$a, $b]);
        $this->command('mapping.save_all', ['archive' => 'garden', 'mapping' => [
            $a => ['outTemp' => 'outTemp', 'native:probe' => '__new__'], $b => ['soilTemp1' => '__new__'],
        ], 'columns' => [
            $a => ['native:probe' => ['column' => 'probeTemp', 'kind' => 'temperature', 'unit' => 'degree_F', 'aggregation' => 'avg']],
            $b => ['soilTemp1' => ['column' => 'greenhouseSoil', 'aggregation' => 'avg']],
        ]]);
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        self::assertSame('probeTemp', $archive->fields[$a]['probeTemp']);
        self::assertSame('greenhouseSoil', $archive->fields[$b]['soilTemp1']);
        self::assertSame('degree_F', $read->config->sources[$a]['probe']->unit);
        $html = (new Page($read, new Translator(), 'csrf'))->render('fields', ['archive' => 'garden']);
        self::assertStringContainsString('Garden</h2>', $html);
        self::assertStringContainsString('Greenhouse</h2>', $html);
        self::assertStringContainsString('mapping[' . $a . '][outTemp]', $html);
        self::assertStringContainsString('mapping[' . $b . '][soilTemp1]', $html);
        self::assertStringNotContainsString('<select name="station">', $html);
        $this->upload(str_repeat('A', 32), self::NOW + 310, 'probe=86');
        $this->upload(str_repeat('B', 32), self::NOW + 320, 'soiltemp1f=50');
        $this->tick(self::NOW + 610);
        $db = Sqlite::readOnly($archive->database);
        try {
            self::assertEqualsWithDelta(30.0, $db->scalar('SELECT probeTemp FROM archive'), 1e-9);
            self::assertEqualsWithDelta(10.0, $db->scalar('SELECT greenhouseSoil FROM archive'), 1e-9);
        } finally {
            $db->close();
        }
    }

    public function testInvalidArchiveWideDraftCreatesNoColumnsOrAssignments(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $this->command('station.adopt', ['station' => $a]);
        $this->archive();
        $this->enable([$a]);
        $before = file_get_contents($this->path);
        try {
            $this->command('mapping.save_all', ['archive' => 'garden', 'mapping' => [$a => ['outTemp' => '__new__', 'outHumidity' => 'outTemp']],
                'columns' => [$a => ['outTemp' => ['column' => 'newTemperature', 'aggregation' => 'avg']]]]);
            self::fail('Incompatible mapping accepted');
        } catch (\WeewxPhp\Archive\MappingError) {
            self::assertSame($before, file_get_contents($this->path));
        }
        $archive = Config::load($this->path)->archive('garden');
        self::assertNotNull($archive);
        self::assertFalse(ReadModel::archive($archive)['schema']->hasColumn('newTemperature'));
    }

    public function testSelectedCounterProducesRainDeltas(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'dailyrainin=1');
        $this->command('station.adopt', ['station' => $a]);
        $this->archive();
        $this->enable([$a]);
        $this->command('mapping.save_all', ['archive' => 'garden', 'mapping' => [$a => ['dayRain' => 'rain']]]);
        $this->upload(str_repeat('A', 32), self::NOW + 290, 'dailyrainin=1');
        $this->upload(str_repeat('A', 32), self::NOW + 310, 'dailyrainin=1.1');
        $this->upload(str_repeat('A', 32), self::NOW + 320, 'dailyrainin=1.2');
        $this->tick(self::NOW + 610);
        $archive = Config::load($this->path)->archive('garden');
        self::assertNotNull($archive);
        $db = Sqlite::readOnly($archive->database);
        try {
            self::assertEqualsWithDelta(5.08, $db->scalar('SELECT rain FROM archive'), 1e-9);
            self::assertNull($db->scalar('SELECT outTemp FROM archive'));
        } finally {
            $db->close();
        }
    }

    public function testTypedThemeSettingsAndReservedFieldNames(): void
    {
        mkdir($this->dir . '/themes/example', 0700, true);
        $path = $this->dir . '/themes/example/settings.json';
        $definition = ['theme' => 'example', 'schema_version' => 1, 'fields' => [
            ['key' => 'show_wind', 'type' => 'boolean', 'default' => true],
            ['key' => 'days', 'type' => 'integer', 'default' => 3, 'min' => 1, 'max' => 10],
        ]];
        file_put_contents($path, json_encode($definition, JSON_THROW_ON_ERROR));
        $read = new ReadModel($this->path);
        (new Service($this->path, self::NOW, $this->dir . '/themes'))->execute('theme.save', ['theme' => 'example', 'show_wind' => 'false', 'days' => '7', 'activate' => 'true', 'revision' => $read->revision]);
        $theme = \WeewxPhp\Frontend\Theme::configured($this->path, directory: $this->dir . '/themes');
        self::assertSame('example', $theme->name);
        self::assertSame(['show_wind' => false, 'days' => 7], $theme->extras);
        $definition['fields'][] = ['key' => 'csrf', 'type' => 'text'];
        file_put_contents($path, json_encode($definition, JSON_THROW_ON_ERROR));
        $this->expectException(Problem::class);
        (new ThemeRegistry($this->dir . '/themes'))->definition('example');
    }

    public function testInterruptedSchemaChangeResumesWithoutReplacingHistory(): void
    {
        $this->archive();
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        $weather = ArchiveDb::open($archive->database, $read->config->settings->journalMode, new Policy(), $archive->timezone);
        try {
            $weather->addColumn('partialColumn', \WeewxPhp\Weewx\ColumnType::Real);
        } finally {
            $weather->close();
        }
        $file = \WeewxPhp\Config\ConfFile::parse($read->file->toString());
        Service::section($file->root()->section('Archives')->section('garden'), 'columns')->set('partialColumn', 'REAL');
        $db = Changes::store($read->config->settings);
        try {
            $db->exec('INSERT INTO admin_operation VALUES (1, ?, ?, ?, ?, ?, ?)', [$read->revision, $file->toString(), self::NOW, 'column.create', '{"garden":"update"}', str_repeat('a', 32)]);
        } finally {
            $db->close();
        }
        $lock = \WeewxPhp\Tick\Lock::tryAcquire($read->config->settings->lockPath());
        self::assertNotNull($lock);
        try {
            Changes::recover($this->path, $read->config->settings);
            Changes::recover($this->path, $read->config->settings);
        } finally {
            $lock->release();
        }
        $updated = Config::load($this->path)->archive('garden');
        self::assertNotNull($updated);
        self::assertArrayHasKey('partialColumn', $updated->columns);
        self::assertSame(1, count(array_filter(ReadModel::archive($updated)['schema']->columns, static fn(string $name): bool => $name === 'partialColumn')));
    }

    public function testConnectDoesNotModifyExistingWeatherDatabase(): void
    {
        $this->archive();
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        $foreign = $this->dir . '/foreign.sdb';
        copy($archive->database, $foreign);
        $hash = hash_file('sha256', $foreign);
        $this->command('archive.connect', ['archive' => 'existing', 'name' => 'Existing', 'database' => 'foreign.sdb', 'unit_system' => 'METRICWX', 'timezone' => 'UTC', 'archive_interval' => '300']);
        self::assertSame($hash, hash_file('sha256', $foreign));
        $connected = Config::load($this->path)->archive('existing');
        self::assertNotNull($connected);
        self::assertFalse($connected->enabled);
        self::assertSame([], $connected->senders);
    }

    public function testWriterReloadsAConfigurationLoadedBeforeAnAdminChange(): void
    {
        $this->archive();
        $runtime = Runtime::boot($this->path, new FixedClock(self::NOW + 10), new MemoryLogger());
        try {
            $this->command('archive.save', ['archive' => 'garden', 'name' => 'Renamed', 'senders' => []]);
            (new Tick($runtime))->run('test');
            self::assertSame('Renamed', $runtime->config->archive('garden')?->name);
        } finally {
            $runtime->close();
        }
    }

    public function testUnknownOnlyMeasurementCanBeDiscoveredAndExplicitlyDefined(): void
    {
        $observation = \WeewxPhp\Ingest\Parser::observation(\WeewxPhp\Ingest\Protocol::Ecowitt, ['PASSKEY' => str_repeat('A', 32), 'probe' => '12.5', 'secret' => '99'], self::NOW);
        self::assertSame([], $observation->data);
        self::assertSame(['probe'], array_keys($observation->fields));
        self::assertSame(12.5, $observation->fields['probe']['value']);
    }

    public function testMaintenanceBackupIsQueuedIdempotentlyAndCompletedByTick(): void
    {
        $this->archive();
        $input = ['archive' => 'garden', 'kind' => 'backup', 'job_key' => str_repeat('b', 32)];
        $this->command('maintenance.queue', $input);
        $this->command('maintenance.queue', $input);
        $this->tick(self::NOW + 10);
        $rows = (new ReadModel($this->path))->rows('state.sdb', 'admin_job', 'SELECT status, result FROM admin_job');
        self::assertCount(1, $rows);
        self::assertSame('complete', $rows[0]['status']);
        self::assertFileExists($this->dir . '/' . Sqlite::text($rows[0]['result']));
    }

    public function testColumnInventoryCountsZeroAndExcludesNullWithUnknownImportedSources(): void
    {
        $this->archive();
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        $db = Sqlite::open($archive->database, false, $read->config->settings->journalMode);
        try {
            foreach ([0.0, null, 12.5] as $index => $value) {
                $db->exec('INSERT INTO archive(dateTime, usUnits, interval, outTemp) VALUES (?, 17, 5, ?)', [self::NOW - 900 + $index * 300, $value]);
            }
        } finally {
            $db->close();
        }
        $hash = hash_file('sha256', $archive->database);
        $inventory = \WeewxPhp\Admin\Inventory::read($archive, ['outTemp', 'outHumidity']);
        self::assertSame(3, $inventory['count']);
        self::assertSame(self::NOW - 900, $inventory['first']);
        self::assertSame(self::NOW - 300, $inventory['last']);
        self::assertSame(['count' => 2, 'first' => self::NOW - 900, 'last' => self::NOW - 300], $inventory['columns']['outTemp']);
        self::assertSame(['count' => 0, 'first' => null, 'last' => null], $inventory['columns']['outHumidity']);
        $data = (new Page($read, new Translator(), 'csrf'))->historyData($archive, 'outTemp', $inventory['columns']['outTemp']);
        self::assertSame('2 stored values', $data['summary']);
        self::assertSame('Source unknown', $data['sourceSummary']);
        self::assertSame($hash, hash_file('sha256', $archive->database));
    }

    public function testPopulatedMappingWarningRequiresConfirmationForEachExactAssignment(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $b = $this->upload(str_repeat('B', 32), self::NOW, 'humidity=60');
        $this->command('station.adopt', ['station' => $a]);
        $this->command('station.adopt', ['station' => $b]);
        $this->archive();
        $this->enable([$a, $b]);
        $this->command('mapping.save_all', ['archive' => 'garden', 'mapping' => [$a => ['outTemp' => 'outTemp']]]);
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        $db = Sqlite::open($archive->database, false, $read->config->settings->journalMode);
        try {
            $db->exec('INSERT INTO archive(dateTime, usUnits, interval, outTemp, extraTemp1, extraHumid1) VALUES (?, 17, 5, 10, 20, 0)', [self::NOW - 300]);
        } finally {
            $db->close();
        }
        $before = file_get_contents($this->path);
        $hash = hash_file('sha256', $archive->database);
        $draft = ['archive' => 'garden', 'mapping' => [$a => ['outTemp' => 'extraTemp1'], $b => ['outHumidity' => 'extraHumid1']]];
        $html = (new Page($read, new Translator(), 'csrf', $draft))->render('fields', ['archive' => 'garden']);
        self::assertSame(2, substr_count($html, 'class="existing-data-warning"'));
        self::assertStringContainsString('This column contains existing data.', $html);
        self::assertStringContainsString('1 stored value', $html);
        self::assertStringContainsString('Source unknown', $html);
        self::assertStringContainsString('name="history[' . $b . '][outHumidity]" value="extraHumid1" required', $html);
        $safeDraft = ['mapping' => [$a => ['outTemp' => 'outTemp'], $b => ['outHumidity' => 'extraHumid2']]];
        $safeHtml = (new Page($read, new Translator(), 'csrf', $safeDraft))->render('fields', ['archive' => 'garden']);
        self::assertStringNotContainsString('class="existing-data-warning"', $safeHtml);
        foreach ([[], [$a => ['outTemp' => 'extraTemp1']], [$a => ['outTemp' => 'extraTemp1'], $b => ['outTemp' => 'extraHumid1']],
            [$a => ['outTemp' => 'extraTemp1'], $b => ['outHumidity' => 'extraHumid2']]] as $confirmations) {
            try {
                $this->command('mapping.save_all', $draft + ['history' => $confirmations]);
                self::fail('Unconfirmed history assignment accepted');
            } catch (Problem $error) {
                self::assertSame('error.history', $error->getMessage());
                self::assertSame($before, file_get_contents($this->path));
            }
        }
        $confirmed = $draft + ['history' => [$a => ['outTemp' => 'extraTemp1'], $b => ['outHumidity' => 'extraHumid1']]];
        $confirmedHtml = (new Page($read, new Translator(), 'csrf', $confirmed))->render('fields', ['archive' => 'garden']);
        self::assertSame(2, substr_count($confirmedHtml, 'required checked'));
        $this->command('mapping.save_all', $confirmed);
        $updated = Config::load($this->path)->archive('garden');
        self::assertNotNull($updated);
        self::assertSame('extraTemp1', $updated->fields[$a]['outTemp']);
        self::assertSame('extraHumid1', $updated->fields[$b]['outHumidity']);
        self::assertSame($hash, hash_file('sha256', $archive->database));
    }

    public function testColumnHistoryShowsPreviousAndCurrentAssignments(): void
    {
        $a = $this->upload(str_repeat('A', 32), self::NOW, 'humidity=70');
        $b = $this->upload(str_repeat('B', 32), self::NOW, 'humidity=60');
        $this->command('station.adopt', ['station' => $a, 'name' => 'Garden']);
        $this->command('station.adopt', ['station' => $b, 'name' => 'Greenhouse']);
        $this->archive();
        $this->enable([$a, $b]);
        $this->command('mapping.save_all', ['archive' => 'garden', 'mapping' => [$a => ['outTemp' => 'outTemp']]]);
        $this->upload(str_repeat('A', 32), self::NOW + 310, 'humidity=70');
        $this->tick(self::NOW + 610);
        $this->command('mapping.save_all', ['archive' => 'garden', 'continue_history' => 'true', 'mapping' => [$a => ['outTemp' => ''], $b => ['outTemp' => 'outTemp']]], self::NOW + 650);
        $this->upload(str_repeat('B', 32), self::NOW + 920, 'humidity=60');
        $this->tick(self::NOW + 1210);
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        $stats = \WeewxPhp\Admin\Inventory::read($archive, ['outTemp'])['columns']['outTemp'];
        self::assertSame(2, $stats['count']);
        $data = (new Page($read, new Translator(), 'csrf'))->historyData($archive, 'outTemp', $stats);
        self::assertStringContainsString('Garden · outTemp', $data['sourceSummary']);
        self::assertStringContainsString('Greenhouse · outTemp', $data['sourceSummary']);
        self::assertCount(2, $data['sources']);
        $controller = new Controller($this->path, self::NOW);
        self::assertSame(403, $controller->handle('GET', ['page' => 'fields', 'archive' => 'garden', 'column' => 'outTemp', 'format' => 'column'], [], null, '127.0.0.1', true)->status);
    }
}
