<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\NativeParser;
use WeewxPhp\Ingest\NativeReceiver;
use WeewxPhp\Live\Packet;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\UnitSystem;

final class NativeReplayTest extends TestCase
{
    private const NOW = 1788609600;
    private const STATION = '11111111-1111-4111-8111-111111111111';
    private string $dir;
    private FixedClock $clock;
    private Runtime $runtime;
    private Runtime $control;
    /** @var array{id: string, token: string} */
    private array $credentials;
    private string $sender;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('native-replay');
        $this->clock = new FixedClock(self::NOW);
        $this->runtime = $this->runtime($this->dir . '/actual');
        $this->control = $this->runtime($this->dir . '/control');
        $this->credentials = $this->runtime->live()->collector()->create('Pi', self::NOW);
        $reply = $this->receive([$this->event(99, self::NOW, ['outTemp' => 1])]);
        $this->sender = Sqlite::text($reply[0]['sender']);
        $this->runtime->live()->collector()->adopt($this->credentials['id'], self::STATION, 'Davis');
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        $this->control->close();
        TempDir::remove($this->dir);
    }

    private function runtime(string $dir): Runtime
    {
        return Runtime::of(new Config(
            Archives::settings($dir),
            [],
            ['kirchdorf' => Archives::config(primary: null, senders: null, autoMapping: true, database: $dir . '/archive.sdb')],
            [],
            ingest: new IngestConfig(enabled: true),
        ), $this->clock, new MemoryLogger());
    }

    private function archiver(Runtime $runtime): Archiver
    {
        return Archiver::open(
            $runtime->config->archives['kirchdorf'],
            $runtime->config->settings,
            $runtime->live(),
            $runtime->state(),
            $runtime->log,
            $this->clock->now(),
        );
    }

    /** @param array<string, int|float|null> $data
     * @return array<string, mixed>
     */
    private function event(int $id, int $time, array $data): array
    {
        return ['station_id' => self::STATION, 'event_id' => sprintf('22222222-2222-4222-8222-%012d', $id),
            'driver_module' => 'weewx.drivers.vantage', 'kind' => 'loop', 'dateTime' => $time, 'usUnits' => 17, 'data' => $data];
    }

    /** @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function receive(array $events, int $version = 1): array
    {
        $body = json_encode(['version' => $version, 'collector_id' => $this->credentials['id'], 'packets' => $events], JSON_THROW_ON_ERROR);
        $response = (new NativeReceiver($this->runtime))->handle('POST', '', static fn(): string => $body, '192.0.2.1', true, 'application/json', 'Bearer ' . $this->credentials['token']);
        self::assertSame(200, $response->status, $response->body);
        $results = Json::object($response->body)['results'];
        self::assertIsArray($results);
        $checked = [];
        foreach ($results as $row) {
            self::assertIsArray($row);
            $checked[] = Json::object(json_encode($row, JSON_THROW_ON_ERROR));
        }
        return $checked;
    }

    /** @param list<array<string, mixed>> $events */
    private function seedControl(array $events): void
    {
        $body = json_encode(['version' => 1, 'collector_id' => $this->credentials['id'], 'packets' => $events], JSON_THROW_ON_ERROR);
        foreach (NativeParser::parse($body)['events'] as $event) {
            $this->control->live()->add(new Packet(
                $event->timestamp,
                $event->units,
                $event->data,
                $this->sender,
                'weewx',
                $this->credentials['id'] . '/' . self::STATION,
            ), ['kirchdorf'], 300);
        }
        $archive = $this->archiver($this->control);
        try {
            $archive->processDue($this->clock->now(), Budget::unlimited($this->clock));
        } finally {
            $archive->close();
        }
    }

    public function testLateHardwareReplacesSoftwareAndFillsLoggerOnlyGapOnce(): void
    {
        $start = Intervals::startOfDay(self::NOW - 86400, $this->runtime->config->settings->timezone);
        $this->receive([$this->event(1, $start + 30, ['rain' => 0.2, 'outTemp' => 10])]);
        $archiver = $this->archiver($this->runtime);
        try {
            $archiver->processReplay(self::NOW, Budget::unlimited($this->clock));
            $hardware = [
                array_replace($this->event(2, $start + 300, ['rain' => 0.5, 'outTemp' => 12]), ['kind' => 'archive', 'interval' => 5]),
                array_replace($this->event(3, $start + 600, ['rain' => 0.7, 'outTemp' => 14]), ['kind' => 'archive', 'interval' => 5]),
            ];
            self::assertSame(['stored', 'stored'], array_column($this->receive($hardware, 2), 'status'));
            $this->clock->advance(16);
            $archiver->processReplay($this->clock->now(), Budget::unlimited($this->clock));
            self::assertSame(['duplicate', 'duplicate'], array_column($this->receive($hardware, 2), 'status'));
            self::assertFalse($this->runtime->live()->replay()->pending());
            self::assertSame(2, $archiver->archive()->count());
            $day = $archiver->archive()->loadDay($start, UnitSystem::METRICWX);
            $rain = $day->get('rain');
            $temperature = $day->get('outTemp');
            self::assertInstanceOf(ScalarStats::class, $rain);
            self::assertInstanceOf(ScalarStats::class, $temperature);
            self::assertEqualsWithDelta(1.2, $rain->sum, 0.000001);
            self::assertSame(10.0, $temperature->min);
            self::assertSame(14.0, $temperature->max);
        } finally {
            $archiver->close();
        }
    }

    public function testReplaySurvivesBudgetRestartAndPruningAndMatchesTimelyArchive(): void
    {
        $start = Intervals::startOfDay(self::NOW - 86400, $this->runtime->config->settings->timezone);
        $events = [
            $this->event(1, $start + 30, ['outTemp' => 10, 'outHumidity' => 60, 'dayRain' => 1, 'windSpeed' => 3, 'windDir' => 350, 'windGust' => 5]),
            $this->event(2, $start + 90, ['outTemp' => 30, 'outHumidity' => 90, 'dayRain' => 2, 'windSpeed' => 5, 'windDir' => 10, 'windGust' => 8]),
            $this->event(3, $start + 330, ['outTemp' => 15, 'outHumidity' => 70, 'dayRain' => 3, 'windSpeed' => 2, 'windDir' => 5, 'windGust' => 4]),
            $this->event(4, $start + 930, ['outTemp' => 18, 'outHumidity' => 80, 'dayRain' => 4, 'windSpeed' => 1, 'windDir' => 180]),
            $this->event(5, $start + 43230, ['outTemp' => 20, 'outHumidity' => 55, 'pressure' => 960]),
            $this->event(6, self::NOW - 30, ['outTemp' => 14, 'outHumidity' => 66, 'rain' => 0.4]),
        ];
        $this->receive([$events[0], ...array_slice($events, 2)]);
        $this->clock->advance(16);
        $actual = $this->archiver($this->runtime);
        try {
            $actual->processReplay($this->clock->now(), Budget::unlimited($this->clock));
        } finally {
            $actual->close();
        }
        self::assertFalse($this->runtime->live()->replay()->pending());
        $this->seedControl($events);
        // Simulate an obsolete daily extreme; repair must replace, not only merge.
        $db = Sqlite::open($this->dir . '/actual/archive.sdb', false, $this->runtime->config->settings->journalMode);
        $db->exec('UPDATE archive_day_outTemp SET max = 999 WHERE dateTime = ?', [$start]);
        $db->close();
        self::assertSame('stored', $this->receive([$events[1]])[0]['status']);
        $actual = $this->archiver($this->runtime);
        try {
            $actual->processReplay($this->clock->now(), Budget::of($this->clock, 100, 2));
        } finally {
            $actual->close();
        }
        self::assertTrue($this->runtime->live()->replay()->pending());
        self::assertSame(0, $this->runtime->live()->prune(self::NOW + 1));
        self::assertSame(6, $this->runtime->live()->count());
        $config = $this->runtime->config;
        $this->runtime->close();
        $this->runtime = Runtime::of($config, $this->clock, new MemoryLogger());
        // Tick resumes the cursor even with the default late_packets=ignore.
        for ($i = 0; $i < 30 && $this->runtime->live()->replay()->pending(); ++$i) {
            (new Tick($this->runtime))->run('test');
        }
        // The late delivery's repair includes the current interval, which closes next.
        $this->clock->advance(300);
        for ($i = 0; $i < 30 && $this->runtime->live()->replay()->pending(); ++$i) {
            (new Tick($this->runtime))->run('test');
        }
        self::assertFalse($this->runtime->live()->replay()->pending());
        $actualDb = Sqlite::readOnly($this->dir . '/actual/archive.sdb');
        $expectedDb = Sqlite::readOnly($this->dir . '/control/archive.sdb');
        try {
            foreach ($expectedDb->tables() as $table) {
                if ($table !== 'archive' && !str_starts_with($table, 'archive_day_')) {
                    continue;
                }
                if ($table === 'archive_day__metadata') {
                    continue;
                }
                $expected = iterator_to_array($expectedDb->query('SELECT * FROM "' . $table . '" ORDER BY dateTime'), false);
                $actual = iterator_to_array($actualDb->query('SELECT * FROM "' . $table . '" ORDER BY dateTime'), false);
                self::assertEqualsWithDelta($expected, $actual, 1e-9, $table);
            }
            self::assertSame(30.0, $actualDb->scalar('SELECT max FROM archive_day_outTemp WHERE dateTime = ?', [$start]));
        } finally {
            $actualDb->close();
            $expectedDb->close();
        }
    }

    public function testConcurrentDeliveryCannotAdvanceAnObsoleteRepairCheckpoint(): void
    {
        $archive = $this->runtime->config->archives['kirchdorf'];
        $replay = $this->runtime->live()->replay();
        $timestamp = self::NOW - 600;
        $replay->mark($archive, $timestamp, self::NOW, 300);
        $before = $replay->job('kirchdorf');
        self::assertNotNull($before);
        $replay->mark($archive, $before['cursor'] + 10, self::NOW, 300);
        self::assertFalse($replay->advance('kirchdorf', $before['generation'], $before['cursor'], $before['cursor'] + 300, null));
        $current = $replay->job('kirchdorf');
        self::assertNotNull($current);
        self::assertTrue($replay->advance('kirchdorf', $current['generation'], $current['cursor'], $current['cursor'] + 600, null));
        $replay->mark($archive, $current['cursor'] + 20, self::NOW, 300);
        self::assertSame($current['cursor'], $replay->job('kirchdorf')['cursor'] ?? null);
    }

    public function testReadOnlyOldJournalWithoutCollectorTablesStillLoads(): void
    {
        $settings = Archives::settings($this->dir . '/old');
        $db = Sqlite::open($settings->liveDbPath(), true, $settings->journalMode);
        $db->exec('CREATE TABLE packet (dateTime INTEGER)');
        $db->close();
        self::assertSame([], \WeewxPhp\Ingest\CollectorStore::configuredStations($settings));
    }

    public function testLiveDeliveryIsProtectedWhenTheScheduledTickStopsForDays(): void
    {
        $this->receive([$this->event(1, self::NOW - 1, ['outTemp' => 21, 'rain' => 0.4])]);
        self::assertFalse($this->runtime->live()->replay()->pending());
        self::assertTrue($this->runtime->live()->replay()->protected());
        $this->clock->advance(10 * 86400);
        self::assertSame(0, $this->runtime->live()->prune($this->clock->now() - 7 * 86400));
        (new Tick($this->runtime))->run('test');
        self::assertFalse($this->runtime->live()->replay()->protected());
        $archive = $this->archiver($this->runtime);
        try {
            self::assertSame(21.0, $archive->archive()->record(self::NOW)['outTemp'] ?? null);
            self::assertSame(0.4, $archive->archive()->record(self::NOW)['rain'] ?? null);
        } finally {
            $archive->close();
        }
    }

    public function testContinuousLiveDeliveryDoesNotPinUnrelatedExpiredHistory(): void
    {
        $this->runtime->live()->add(new Packet(
            self::NOW - 20 * 86400,
            UnitSystem::METRICWX,
            ['outTemp' => 1],
            'old',
            'custom',
            'old',
        ), [], 300);
        $this->receive([$this->event(1, self::NOW - 1, ['outTemp' => 21])]);
        self::assertTrue($this->runtime->live()->replay()->protected());
        self::assertSame(1, $this->runtime->live()->prune(self::NOW - 7 * 86400));
        self::assertSame(1, $this->runtime->live()->count());
    }

    public function testDailyReplayRetainsRecordsOnAnOlderArchiveGrid(): void
    {
        $start = Intervals::startOfDay(self::NOW - 86400, $this->runtime->config->settings->timezone);
        $archive = $this->archiver($this->runtime);
        $archive->archive()->addRecord(['dateTime' => $start + 60, 'usUnits' => 17, 'interval' => 1, 'outTemp' => 50.0]);
        $this->receive([$this->event(1, $start + 330, ['outTemp' => 20])]);
        $this->clock->advance(16);
        try {
            $archive->processReplay($this->clock->now(), Budget::unlimited($this->clock));
            self::assertSame(50.0, $archive->archive()->record($start + 60)['outTemp'] ?? null);
            $day = $archive->archive()->loadDay($start, UnitSystem::METRICWX);
            self::assertSame(50.0, $day->get('outTemp')->statsTuple()[2]);
        } finally {
            $archive->close();
        }
    }
}
