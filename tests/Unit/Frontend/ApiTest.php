<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\Api\Endpoint;
use WeewxPhp\Frontend\Api\Feed;
use WeewxPhp\Frontend\Api\Response;
use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

final class ApiTest extends TestCase
{
    private string $dir;
    private Config $config;
    private Weather $wx;
    private ReadBudget $budget;
    private const NOW = 1787734200;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('public-api');
        $archive = Archives::config(database: $this->dir . '/weather.sdb');
        $this->config = new Config(Archives::settings($this->dir), [], [$archive->id => $archive], []);
        $this->budget = new ReadBudget(milliseconds: 5000);
        $this->wx = new Weather($this->config, clock: new FixedClock(self::NOW), budget: $this->budget);
    }

    protected function tearDown(): void
    {
        $this->wx->close();
        TempDir::remove($this->dir);
    }

    private function endpoint(): Endpoint
    {
        return new Endpoint(['outside' => new Feed(
            $this->wx,
            ['temperature' => $this->wx->current('outTemp')],
            origins: ['https://blog.example.org'],
        )]);
    }

    public function testRequestCannotChooseSqlFilesIntervalsOrUnpublishedSensors(): void
    {
        $api = $this->endpoint();
        foreach ([['feed' => '../../secret'], ['feed' => ['outside']], ['feed' => 'outside', 'sql' => 'select * from archive'],
            ['feed' => 'outside', 'fields' => 'inTemp'], ['feed' => 'outside', 'fields' => ['temperature']],
            ['feed' => 'outside', 'fields' => 'temperature,temperature'], ['feed' => 'outside', 'fields' => ''],
            ['feed' => 'outside', 'range' => 'alltime']] as $query) {
            self::assertSame(400, $api->handle('GET', $query)->status);
        }
        self::assertSame(404, $api->handle('GET', ['feed' => 'private'])->status);
        self::assertSame(405, $api->handle('POST', ['feed' => 'outside'])->status);
        self::assertSame(0, $this->budget->snapshot()['rows']);
        self::assertFileDoesNotExist($this->dir . '/analytics.sdb');
    }

    public function testCorsAndPreflightAreExplicitAndDoNotOpenDatabases(): void
    {
        $api = $this->endpoint();
        foreach (['https://evil.example.org', 'https://blog.example.org.evil.test', 'null', "https://blog.example.org\r\nX-Evil: yes"] as $origin) {
            $response = $api->handle('GET', ['feed' => 'outside'], ['origin' => $origin]);
            self::assertSame(403, $response->status);
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
            self::assertSame('Origin', $response->headers['Vary']);
        }
        $response = $api->handle('OPTIONS', ['feed' => 'outside'], ['origin' => 'https://blog.example.org',
            'access-control-request-method' => 'GET', 'access-control-request-headers' => 'If-None-Match']);
        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('https://blog.example.org', $response->headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
        self::assertSame(400, $api->handle('OPTIONS', ['feed' => 'outside'], ['access-control-request-headers' => 'Authorization'])->status);
        self::assertSame(405, $api->handle('OPTIONS', ['feed' => 'outside'], ['access-control-request-method' => 'PUT'])->status);
        self::assertFileDoesNotExist($this->dir . '/analytics.sdb');
    }

    public function testCacheMissIsPendingWithoutReadingOrCreatingAnArchive(): void
    {
        $response = $this->endpoint()->handle('GET', ['feed' => 'outside']);
        self::assertSame(200, $response->status);
        $field = $this->fields($response)['temperature'];
        self::assertSame('pending', $field['status']);
        self::assertNull($field['value']);
        self::assertSame('archive', $field['source']);
        self::assertSame(0, $this->budget->snapshot()['rows']);
        self::assertSame(0, $this->budget->snapshot()['statements']);
        self::assertFileDoesNotExist($this->dir . '/weather.sdb');
    }

    public function testPreparedValueHasUnitsTimestampAndConditionalGetAndHead(): void
    {
        $archive = $this->wx->configuration();
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        try {
            $db->addRecord(['dateTime' => self::NOW, 'interval' => 5, 'usUnits' => 17, 'outTemp' => 0.0]);
        } finally {
            $db->close();
        }
        $this->wx->syncTheme('api-test', ['temperature' => $this->wx->current('outTemp')]);
        $clock = new FixedClock(self::NOW);
        $runtime = Runtime::of($this->config, $clock, new MemoryLogger());
        try {
            for ($i = 0; $i < 4; ++$i) {
                $result = (new Worker($runtime))->run(Budget::of($clock, 10, 100000));
                self::assertSame(0, $result['failed']);
                if ($result['pending'] === 0) {
                    break;
                }
            }
        } finally {
            $runtime->close();
        }
        $response = $this->endpoint()->handle('GET', ['feed' => 'outside']);
        $field = $this->fields($response)['temperature'];
        self::assertSame(0, $field['value'], $response->body);
        self::assertSame('degree_C', $field['unit']);
        self::assertSame(self::NOW, $field['asOf']);
        self::assertSame('ready', $field['status']);
        self::assertSame(0, $this->budget->snapshot()['rows']);
        self::assertStringNotContainsString($this->dir, $response->body);
        foreach ([$response->headers['ETag'], 'W/' . $response->headers['ETag'], '"other", ' . $response->headers['ETag'], '*'] as $etag) {
            $cached = $this->endpoint()->handle('GET', ['feed' => 'outside'], ['if-none-match' => $etag]);
            self::assertSame(304, $cached->status);
            self::assertSame('', $cached->body);
        }
        $head = $this->endpoint()->handle('HEAD', ['feed' => 'outside']);
        self::assertSame(200, $head->status);
        self::assertSame('', $head->body);
        self::assertSame($response->headers['ETag'], $head->headers['ETag']);
    }

    public function testLiveUsesRealMeasurementTimeAndStableEtagAndNoPrivateFields(): void
    {
        $live = LiveDb::open($this->config->settings->liveDbPath(), JournalMode::Wal);
        try {
            $live->add(new Packet(self::NOW - 10, UnitSystem::US, ['outTemp' => 68, 'inTemp' => 80, 'outHumidity' => 0], 'ecowitt'), [], 300);
        } finally {
            $live->close();
        }
        $make = function (int $now): Endpoint {
            $wx = (new Weather($this->config, clock: new FixedClock($now)))
                ->output(new Output('de', units: ['group_temperature' => 'degree_C']));
            return new Endpoint(['live' => new Feed($wx, live: ['temperature' => 'outTemp', 'humidity' => 'outHumidity'], origins: ['*'])]);
        };
        $first = $make(self::NOW)->handle('GET', ['feed' => 'live'], ['origin' => 'https://any.example']);
        $field = $this->fields($first)['temperature'];
        self::assertSame(20, $field['value']);
        self::assertSame('20,0 °C', $field['formatted']);
        self::assertSame(self::NOW - 10, $field['asOf']);
        self::assertNull($field['computedAt']);
        self::assertSame('live', $field['source']);
        self::assertSame('*', $first->headers['Access-Control-Allow-Origin']);
        self::assertStringNotContainsString('inTemp', $first->body);
        self::assertSame(0, $this->fields($first)['humidity']['value']);
        self::assertSame($first->headers['ETag'], $make(self::NOW + 1)->handle('GET', ['feed' => 'live'])->headers['ETag']);
        $stale = $make(self::NOW + 121)->handle('GET', ['feed' => 'live']);
        self::assertSame('stale', $this->fields($stale)['temperature']['status']);
        self::assertNotSame($first->headers['ETag'], $stale->headers['ETag']);
        $subset = $make(self::NOW)->handle('GET', ['feed' => 'live', 'fields' => 'humidity']);
        self::assertSame(['humidity'], array_keys($this->fields($subset)));
        self::assertFileDoesNotExist($this->dir . '/weather.sdb');
        self::assertFileDoesNotExist($this->dir . '/analytics.sdb');
    }

    public function testFeedLimitsAreEnforcedAtDefinitionTime(): void
    {
        $this->expectException(\WeewxPhp\Frontend\QueryError::class);
        new Feed($this->wx, live: array_fill_keys(range('a', 'i'), 'outTemp'));
    }

    public function testMissingLiveDatabaseRemainsUnavailableAndIsNotCreated(): void
    {
        $wx = $this->wx->output(new Output(units: ['group_temperature' => 'degree_F']));
        $response = (new Endpoint(['live' => new Feed($wx, live: ['temperature' => 'outTemp'])]))
            ->handle('GET', ['feed' => 'live']);
        self::assertSame('unavailable', $this->fields($response)['temperature']['status']);
        self::assertNull($this->fields($response)['temperature']['value']);
        self::assertSame('degree_F', $this->fields($response)['temperature']['unit']);
        self::assertFileDoesNotExist($this->config->settings->liveDbPath());
    }

    /** @return array<string, array<string, mixed>> */
    private function fields(Response $response): array
    {
        /** @var array{data: array<string, array<string, mixed>>} $data */
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        return $data['data'];
    }
}
