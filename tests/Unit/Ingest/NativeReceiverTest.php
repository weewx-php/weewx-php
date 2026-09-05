<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\CollectorStore;
use WeewxPhp\Ingest\NativeParser;
use WeewxPhp\Ingest\NativeReceiver;
use WeewxPhp\Ingest\Response;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\FixedClock;

final class NativeReceiverTest extends TestCase
{
    private const NOW = 1788609600;
    private const STATION = '11111111-1111-4111-8111-111111111111';
    private string $dir;
    private Runtime $runtime;
    private NativeReceiver $receiver;
    private FixedClock $clock;
    /** @var array{id: string, token: string} */
    private array $credentials;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('native');
        $this->clock = new FixedClock(self::NOW);
        $this->runtime = Runtime::of(new Config(Archives::settings($this->dir), [], [], [], ingest: new IngestConfig(enabled: true)), $this->clock, new MemoryLogger());
        $this->receiver = new NativeReceiver($this->runtime);
        $this->credentials = $this->store()->create('Raspberry', self::NOW);
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        TempDir::remove($this->dir);
    }

    private function store(): CollectorStore
    {
        return $this->runtime->live()->collector();
    }

    /** @return array<string, mixed> */
    private function packet(int $number = 1, string $station = self::STATION): array
    {
        return ['station_id' => $station, 'event_id' => sprintf('22222222-2222-4222-8222-%012d', $number),
            'driver_module' => 'weewx.drivers.vantage', 'kind' => 'loop', 'dateTime' => self::NOW - 10,
            'usUnits' => 17, 'data' => ['outTemp' => 18.7, 'rain' => 0.2, 'extraTemp1' => null]];
    }

    /** @param list<array<string, mixed>> $packets */
    private function body(array $packets): string
    {
        return json_encode(['version' => 1, 'collector_id' => $this->credentials['id'], 'packets' => $packets], JSON_THROW_ON_ERROR);
    }

    private function send(string $body, ?string $token = null): Response
    {
        return $this->receiver->handle('POST', '', static fn(): string => $body, '192.0.2.1', true, 'application/json', 'Bearer ' . ($token ?? $this->credentials['token']));
    }

    /** @return list<array<string, mixed>> */
    private function results(Response $response): array
    {
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

    public function testAdmissionRetryAndIndependentSameDriverStations(): void
    {
        $second = '33333333-3333-4333-8333-333333333333';
        $body = $this->body([$this->packet(), $this->packet(2, $second)]);
        $results = $this->results($this->send($body));
        self::assertSame(['pending', 'pending'], array_column($results, 'status'));
        self::assertSame(0, $this->runtime->live()->count());
        self::assertFalse($this->receiver->wrote());
        $this->store()->adopt($this->credentials['id'], self::STATION, 'Davis');
        $this->store()->adopt($this->credentials['id'], $second, 'Davis 2');
        $results = $this->results($this->send($body));
        self::assertSame(['stored', 'stored'], array_column($results, 'status'));
        self::assertNotSame($results[0]['sender'], $results[1]['sender']);
        self::assertTrue($this->receiver->wrote());
        $packets = iterator_to_array($this->runtime->live()->packets(self::NOW - 20, self::NOW));
        self::assertCount(2, $packets);
        self::assertNotSame($packets[0]->identity, $packets[1]->identity);
        self::assertSame(self::NOW - 10, $packets[0]->dateTime);
        self::assertSame(self::NOW, $packets[0]->received);
        self::assertNull($packets[0]->data['extraTemp1']);
        self::assertSame(0.2, $packets[0]->data['rain']);
        self::assertCount(2, CollectorStore::configuredStations($this->runtime->config->settings));

        // Same database after a process restart; no new events on a lost ACK.
        $config = $this->runtime->config;
        $this->runtime->close();
        $this->runtime = Runtime::of($config, $this->clock, new MemoryLogger());
        $this->receiver = new NativeReceiver($this->runtime);
        self::assertSame(['duplicate', 'duplicate'], array_column($this->results($this->send($body)), 'status'));
        self::assertSame(2, $this->runtime->live()->count());
        self::assertFalse($this->receiver->wrote());
    }

    public function testAuthenticationAndTransportAreCheckedBeforeReadingBody(): void
    {
        foreach ([['GET', '', true, 'Bearer ' . $this->credentials['token'], 405],
            ['POST', '', false, 'Bearer ' . $this->credentials['token'], 403],
            ['POST', 'token=hidden', true, 'Bearer ' . $this->credentials['token'], 400],
            ['POST', '', true, 'Bearer invalid', 401]] as [$method, $query, $https, $auth, $status]) {
            $response = $this->receiver->handle($method, $query, static function (): string {
                self::fail('body must not be read');
            }, '192.0.2.1', $https, 'application/json', $auth);
            self::assertSame($status, $response->status);
            self::assertSame('application/json', $response->contentType);
        }
        $other = $this->store()->create('Other', self::NOW);
        self::assertSame(403, $this->send($this->body([$this->packet()]), $other['token'])->status);
        self::assertSame([], $this->store()->stations($this->credentials['id']));
    }

    public function testTokenRotationDisableAndStationBlockPreserveIdentity(): void
    {
        $body = $this->body([$this->packet()]);
        $initial = $this->results($this->send($body))[0];
        $this->store()->adopt($this->credentials['id'], self::STATION);
        $rotated = $this->store()->rotate($this->credentials['id']);
        self::assertSame(401, $this->send($body)->status);
        self::assertSame($initial['sender'], $this->results($this->send($body, $rotated))[0]['sender']);
        $this->store()->block($this->credentials['id'], self::STATION);
        $blocked = $this->results($this->send($this->body([$this->packet(2)]), $rotated))[0];
        self::assertSame('station_blocked', $blocked['reason']);
        $this->store()->enable($this->credentials['id'], false);
        self::assertSame(401, $this->send($body, $rotated)->status);
        $this->store()->enable($this->credentials['id'], true);
        self::assertSame('duplicate', $this->results($this->send($body, $rotated))[0]['status']);
        $db = Sqlite::readOnly($this->runtime->config->settings->liveDbPath());
        try {
            $stored = json_encode($db->one('SELECT * FROM weewx_collector'), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($rotated, $stored);
            self::assertStringNotContainsString($this->credentials['token'], $stored);
        } finally {
            $db->close();
        }
    }

    public function testHistoricalTimesConflictsAndReceiptHorizon(): void
    {
        $packet = $this->packet();
        $packet['dateTime'] = self::NOW - 2 * 86400;
        $body = $this->body([$packet]);
        $this->send($body);
        $this->store()->adopt($this->credentials['id'], self::STATION);
        self::assertSame('stored', $this->results($this->send($body))[0]['status']);
        $packet['data'] = ['outTemp' => 99];
        self::assertSame('event_conflict', $this->results($this->send($this->body([$packet])))[0]['reason']);
        $old = $this->packet(3);
        $old['dateTime'] = self::NOW - NativeParser::maxAge($this->runtime->config->settings) - 1;
        $future = $this->packet(4);
        $future['dateTime'] = self::NOW + 61;
        self::assertSame(['too_old', 'future_timestamp'], array_column($this->results($this->send($this->body([$old, $future]))), 'reason'));
        $this->clock->advance(20 * 86400);
        $this->runtime->live()->prune($this->clock->now() - 7 * 86400);
        self::assertSame(0, $this->runtime->live()->count());
        self::assertSame('duplicate', $this->results($this->send($body))[0]['status']);
        $this->clock->advance(11 * 86400);
        $this->store()->prune($this->clock->now());
        self::assertSame('too_old', $this->results($this->send($body))[0]['reason']);
        self::assertSame(0, $this->runtime->live()->count());
    }

    public function testMalformedBatchesCannotWriteOrDiscover(): void
    {
        $valid = $this->packet();
        $bad = [
            '{', '[]', str_repeat(' ', NativeParser::MAX_BYTES + 1),
            $this->body([]), $this->body(array_fill(0, NativeParser::MAX_PACKETS + 1, $valid)),
            $this->body([$valid, $valid]),
            str_replace('"version":1', '"version":1,"version":1', $this->body([$valid])),
            str_replace('"version":1', '"version":1,"vers\\u0069on":1', $this->body([$valid])),
        ];
        foreach (['kind' => 'archive', 'dateTime' => '1788609600', 'usUnits' => 99, 'driver_module' => '../driver',
            'sender' => 'somebody_else', 'data' => ['outTemp' => '19']] as $key => $value) {
            $bad[] = $this->body([array_replace($valid, [$key => $value])]);
        }
        foreach ([['outTemp' => true], ['outTemp' => []], ['dateTime' => 1], ['0' => 1], ['outTemp' => null, 'bad-name' => 1]] as $data) {
            $bad[] = $this->body([array_replace($valid, ['data' => $data])]);
        }
        $bad[] = str_replace('18.7', '1e999', $this->body([$valid]));
        foreach ($bad as $body) {
            $response = $this->send($body);
            self::assertContains($response->status, [400, 413], $response->body);
            self::assertSame([], $this->store()->stations($this->credentials['id']));
        }
        self::assertSame(0, $this->runtime->live()->count());
    }

    public function testReceiptFailureRollsBackWholeBatchAndPendingMarks(): void
    {
        $body = $this->body([$this->packet(), array_replace($this->packet(2), ['dateTime' => self::NOW - 5])]);
        $this->send($body);
        $this->store()->adopt($this->credentials['id'], self::STATION);
        $db = Sqlite::open($this->runtime->config->settings->liveDbPath(), false, $this->runtime->config->settings->journalMode);
        try {
            $db->exec("CREATE TRIGGER reject_second_receipt BEFORE INSERT ON weewx_receipt WHEN NEW.event = '22222222-2222-4222-8222-000000000002' BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
            self::assertSame(503, $this->send($body)->status);
            self::assertSame(0, $this->runtime->live()->count());
            self::assertSame(0, $db->scalar('SELECT COUNT(*) FROM weewx_receipt'));
            self::assertSame(0, $db->scalar('SELECT COUNT(*) FROM pending'));
            $db->exec('DROP TRIGGER reject_second_receipt');
            self::assertSame(['stored', 'stored'], array_column($this->results($this->send($body)), 'status'));
        } finally {
            $db->close();
        }
    }

    public function testCredentialRevocationDuringBodyReadIsRecheckedInWriteTransaction(): void
    {
        $body = $this->body([$this->packet()]);
        $response = $this->receiver->handle('POST', '', function () use ($body): string {
            $this->store()->rotate($this->credentials['id']);
            return $body;
        }, '192.0.2.1', true, 'application/json', 'Bearer ' . $this->credentials['token']);
        self::assertSame(401, $response->status);
        self::assertSame([], $this->store()->stations($this->credentials['id']));
    }

    public function testDistinctEventsWithEqualValuesInTheSameSecondAreNotLost(): void
    {
        $body = $this->body([$this->packet(), $this->packet(2)]);
        $this->send($body);
        $this->store()->adopt($this->credentials['id'], self::STATION);
        self::assertSame(['stored', 'stored'], array_column($this->results($this->send($body)), 'status'));
        self::assertSame(2, $this->runtime->live()->count());
        self::assertSame(['duplicate', 'duplicate'], array_column($this->results($this->send($body)), 'status'));
        self::assertSame(2, $this->runtime->live()->count());
    }

    public function testCollectorLimitCannotBeEvadedByChangingPeerAddresses(): void
    {
        $settings = $this->runtime->config->settings;
        $this->runtime->config = new Config($settings, [], [], [], ingest: new IngestConfig(enabled: true, senderRequestsPerMinute: 1));
        $body = $this->body([$this->packet()]);
        self::assertSame(200, $this->send($body)->status);
        $response = $this->receiver->handle('POST', '', static function (): string {
            self::fail('rate-limited request must not read body');
        }, '192.0.2.100', true, 'application/json', 'Bearer ' . $this->credentials['token']);
        self::assertSame(429, $response->status);
        $this->clock->advance(60);
        self::assertSame(200, $this->send($body)->status);
    }

    public function testReceiptCapacityRollsBackNewBatchAndStillAcknowledgesAcceptedEvents(): void
    {
        $settings = $this->runtime->config->settings;
        $this->runtime->config = new Config($settings, [], [], [], ingest: new IngestConfig(enabled: true, maxNativeReceipts: 1));
        $this->send($this->body([$this->packet()]));
        $this->store()->adopt($this->credentials['id'], self::STATION);
        $response = $this->send($this->body([$this->packet(), $this->packet(2)]));
        self::assertSame(503, $response->status);
        self::assertSame('receipt_capacity', Json::object($response->body)['error']);
        self::assertSame(0, $this->runtime->live()->count());
        self::assertSame('stored', $this->results($this->send($this->body([$this->packet()])))[0]['status']);
        self::assertSame(503, $this->send($this->body([$this->packet(2)]))->status);
        self::assertSame('duplicate', $this->results($this->send($this->body([$this->packet()])))[0]['status']);
        self::assertSame(1, $this->runtime->live()->count());
    }
}
