<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\Protocol;
use WeewxPhp\Ingest\Receiver;
use WeewxPhp\Ingest\Response;
use WeewxPhp\Ingest\Store;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;

final class ReceiverTest extends TestCase
{
    private const NOW = 1788609600;
    private const PASSKEY = '0123456789ABCDEF0123456789ABCDEF';
    private const WU = '/weatherstation/updateweatherstation.php';
    private string $dir;
    private Runtime $runtime;
    private FixedClock $clock;
    private Receiver $receiver;
    /** @var array{ecowitt: string, wunderground: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('ingest');
        $this->clock = new FixedClock(self::NOW);
        $settings = Archives::settings($this->dir);
        $archive = Archives::config(primary: null, senders: null, database: $this->dir . '/weather.sdb');
        $this->runtime = Runtime::of(
            new Config($settings, [], ['kirchdorf' => $archive], [], ingest: new IngestConfig(enabled: true)),
            $this->clock,
            new MemoryLogger(),
        );
        $this->receiver = new Receiver($this->runtime);
        $this->keys = $this->runtime->ingest()->credentials();
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        TempDir::remove($this->dir);
    }

    public function testEcowittDiscoveryDoesNotWriteLiveUntilAdoption(): void
    {
        $body = $this->ecowitt();
        $response = $this->post($body);
        self::assertSame(200, $response->status);
        self::assertSame('{"errcode":"0","errmsg":"ok"}', $response->body);
        self::assertFileDoesNotExist($this->runtime->config->settings->liveDbPath());
        [$sender] = $this->runtime->ingest()->senders();
        self::assertSame('pending', $sender->state);
        self::assertSame(self::PASSKEY, $sender->identity);
        self::assertNotNull($sender->sample);
        self::assertStringNotContainsString(self::PASSKEY, $sender->sample);
        self::assertSame(1, $sender->received);
        $this->runtime->ingest()->adopt($sender->id, 'Garten', self::NOW);
        self::assertFileDoesNotExist($this->runtime->config->settings->liveDbPath());
        self::assertSame(200, $this->post($body)->status);
        self::assertCount(1, iterator_to_array($this->runtime->live()->packets(self::NOW - 1, self::NOW + 1)));
        self::assertTrue($this->receiver->wrote());
        self::assertSame(200, $this->post($body)->status);
        self::assertFalse($this->receiver->wrote());
        self::assertSame($this->keys['ecowitt'], $this->runtime->ingest()->credentials()['ecowitt']);
        self::assertSame('Garten', Store::stations($this->runtime->config->settings)[$sender->id]->name);
    }

    public function testAnalysisAndArchiveLocksDoNotBlockAcceptedPackets(): void
    {
        $this->post($this->ecowitt());
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, 'Rain', self::NOW);
        $this->runtime->nonBlockingIngest();
        $this->runtime->live();
        $archive = \WeewxPhp\Tick\Lock::tryAcquire($this->runtime->config->settings->lockPath());
        $analysis = \WeewxPhp\Tick\Lock::tryAcquire($this->dir . '/analytics.lock');
        self::assertNotNull($archive);
        self::assertNotNull($analysis);
        try {
            self::assertSame(200, $this->post($this->ecowitt())->status);
            self::assertTrue($this->receiver->wrote());
            self::assertSame(1, $this->runtime->live()->count());
        } finally {
            $analysis->release();
            $archive->release();
        }
    }

    public function testTwoEcowittConsolesSharePathAndHaveSeparateAdoption(): void
    {
        $this->post($this->ecowitt());
        $this->post(str_replace(self::PASSKEY, str_repeat('A', 32), $this->ecowitt()));
        [$first, $second] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($first->id, null, self::NOW);
        $this->post($this->ecowitt());
        $this->post(str_replace(self::PASSKEY, str_repeat('A', 32), $this->ecowitt()));
        $packets = iterator_to_array($this->runtime->live()->packets(self::NOW - 1, self::NOW + 1));
        self::assertCount(1, $packets);
        self::assertNotSame($second->id, $packets[0]->sender);
    }

    public function testWundergroundConsumesOnlyFreePasswordAndKeepsOldAssignments(): void
    {
        $password = $this->keys['wunderground'];
        $firstQuery = $this->wu($password);
        self::assertSame('success', $this->get($firstQuery)->body);
        $next = $this->runtime->ingest()->credentials()['wunderground'];
        self::assertNotSame($password, $next);
        $this->get($firstQuery);
        self::assertSame($next, $this->runtime->ingest()->credentials()['wunderground']);
        self::assertCount(1, $this->runtime->ingest()->senders());
        $this->get($this->wu($next)); // Deliberately the same hardware ID.
        [$first, $second] = $this->runtime->ingest()->senders();
        self::assertSame($first->identity, $second->identity);
        self::assertNotSame($first->id, $second->id);
        foreach ([$first, $second] as $sender) {
            self::assertNotNull($sender->sample);
            self::assertStringNotContainsString($password, $sender->sample);
            self::assertStringNotContainsString($next, $sender->sample);
            $this->runtime->ingest()->adopt($sender->id, null, self::NOW);
        }
        $this->get($firstQuery);
        $this->get($this->wu($next));
        self::assertCount(2, iterator_to_array($this->runtime->live()->packets(self::NOW - 1, self::NOW + 1)));
        self::assertCount(2, $this->runtime->live()->senders());
    }

    public function testInvalidInputsCannotConsumePasswordOrCreateSenders(): void
    {
        foreach (['ID=X&PASSWORD=' . $this->keys['wunderground'], $this->wu('wrong'),
            $this->wu($this->keys['wunderground']) . '&PASSWORD=other',
            str_replace('tempf=68', 'tempf=1e999', $this->wu($this->keys['wunderground']))] as $query) {
            self::assertGreaterThanOrEqual(400, $this->get($query)->status);
        }
        self::assertSame($this->keys, $this->runtime->ingest()->credentials());
        self::assertSame([], $this->runtime->ingest()->senders());
    }

    public function testBlockingStopsWritesAndDoesNotRotateAssignedPassword(): void
    {
        $this->get($this->wu($this->keys['wunderground']));
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, null, self::NOW);
        $this->runtime->ingest()->setState($sender->id, 'blocked');
        self::assertSame(200, $this->get($this->wu($this->keys['wunderground']))->status);
        self::assertFileDoesNotExist($this->runtime->config->settings->liveDbPath());
        self::assertNull($this->runtime->ingest()->sender($sender->id)?->sample);
        self::assertCount(1, $this->runtime->ingest()->senders());
    }

    public function testInvalidEcowittPathDoesNotReadTheBody(): void
    {
        $response = $this->receiver->handle('POST', '/aaaaaaaaaaaa/ecowitt/', '', static function (): string {
            self::fail('unauthenticated body must not be read');
        }, '192.0.2.1', false);
        self::assertSame(403, $response->status);
    }

    public function testRepeatedFailuresTemporarilyBlockOnePeerOnly(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(403, $this->get($this->wu('wrong'))->status);
        }
        self::assertSame(429, $this->get($this->wu($this->keys['wunderground']))->status);
        self::assertSame(200, $this->receiver->handle(
            'GET',
            self::WU,
            $this->wu($this->keys['wunderground']),
            static fn(): string => '',
            '192.0.2.2',
            false,
        )->status);
        self::assertTrue($this->runtime->ingest()->permit('192.0.2.1', self::NOW + 601, $this->runtime->config->ingest));
    }

    public function testDiscoveryLimitDoesNotConsumeTheNextPassword(): void
    {
        $settings = $this->runtime->config->settings;
        $other = Runtime::of(
            new Config($settings, [], [], [], ingest: new IngestConfig(enabled: true, maxPending: 1)),
            $this->clock,
            new MemoryLogger(),
        );
        try {
            $receiver = new Receiver($other);
            $receiver->handle('GET', self::WU, $this->wu($this->keys['wunderground']), static fn(): string => '', '192.0.2.1', true);
            $next = $other->ingest()->credentials()['wunderground'];
            self::assertSame(503, $receiver->handle('GET', self::WU, $this->wu($next), static fn(): string => '', '192.0.2.1', true)->status);
            self::assertSame($next, $other->ingest()->credentials()['wunderground']);
        } finally {
            $other->close();
        }
    }

    public function testPendingSamplesExpireButAssignedPasswordsSurvive(): void
    {
        $this->get($this->wu($this->keys['wunderground']));
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->prune(self::NOW + 86401);
        self::assertNull($this->runtime->ingest()->sender($sender->id)?->sample);
        $next = $this->runtime->ingest()->credentials()['wunderground'];
        $this->get($this->wu($this->keys['wunderground']));
        self::assertCount(1, $this->runtime->ingest()->senders());
        self::assertSame($next, $this->runtime->ingest()->credentials()['wunderground']);
    }

    public function testHttpsOnlySettingRefusesHttpWithoutConsumingCredential(): void
    {
        $other = Runtime::of(new Config(
            $this->runtime->config->settings,
            [],
            [],
            [],
            ingest: new IngestConfig(enabled: true, httpWunderground: false),
        ), $this->clock, new MemoryLogger());
        try {
            $receiver = new Receiver($other);
            self::assertSame(403, $receiver->handle('GET', self::WU, $this->wu($this->keys['wunderground']), static fn(): string => '', '192.0.2.1', false)->status);
            self::assertSame($this->keys, $other->ingest()->credentials());
            self::assertSame(200, $receiver->handle('GET', self::WU, $this->wu($this->keys['wunderground']), static fn(): string => '', '192.0.2.1', true)->status);
        } finally {
            $other->close();
        }
    }

    public function testSubdirectoryRouteAndDisabledReceiver(): void
    {
        $receiver = new Receiver($this->runtime, '/weather');
        self::assertSame(200, $receiver->handle('GET', '/weather' . self::WU, $this->wu($this->keys['wunderground']), static fn(): string => '', '192.0.2.1', true)->status);
        self::assertSame(404, $receiver->handle('GET', '/weather-other' . self::WU, '', static fn(): string => '', '192.0.2.1', true)->status);
        $disabled = Runtime::of(new Config($this->runtime->config->settings, [], [], []), $this->clock, new MemoryLogger());
        try {
            self::assertSame(404, (new Receiver($disabled))->handle('POST', '/' . $this->keys['ecowitt'] . '/ecowitt/', '', static fn(): string => '', '192.0.2.1', true)->status);
        } finally {
            $disabled->close();
        }
    }

    public function testAnAdoptedUploadReachesAWeewxArchive(): void
    {
        $this->post($this->ecowitt());
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, null, self::NOW);
        $this->post($this->ecowitt());
        $this->clock->advance(400);
        $outcome = (new Tick($this->runtime))->run('test');
        self::assertSame('ok', $outcome->status);
        self::assertGreaterThan(0, $outcome->archives['kirchdorf']['records']);
    }

    public function testUpdatingKnownFieldsDoesNotConsumeInventorySlots(): void
    {
        $extra = '';
        for ($i = 0; $i < 197; ++$i) {
            $extra .= '&probe' . $i . '=1';
        }
        $this->post($this->ecowitt() . $extra); // Three mapped fields, 197 unknown.
        [$sender] = $this->runtime->ingest()->senders();
        self::assertCount(200, $this->runtime->ingest()->fields($sender->id));
        for ($i = 197; $i < 255; ++$i) {
            $extra .= '&probe' . $i . '=2';
        }
        $this->post($this->ecowitt() . $extra);
        $fields = array_column($this->runtime->ingest()->fields($sender->id), 'value', 'native');
        self::assertCount(256, $fields);
        self::assertSame(2.0, $fields['probe252']);
        self::assertArrayNotHasKey('probe253', $fields);
        $this->post($this->ecowitt() . '&probe252=3');
        self::assertSame(3.0, array_column($this->runtime->ingest()->fields($sender->id), 'value', 'native')['probe252']);
    }

    public function testTickExpiresSamplesEvenWithoutFurtherUploads(): void
    {
        $this->post($this->ecowitt());
        [$sender] = $this->runtime->ingest()->senders();
        $this->clock->advance(86401);
        // A raw request only maintains rate-limit rows, never scans sensor fields.
        $this->runtime->ingest()->permit('192.0.2.2', $this->clock->now(), $this->runtime->config->ingest);
        self::assertNotNull($this->runtime->ingest()->sender($sender->id)?->sample);
        (new Tick($this->runtime))->run('test');
        self::assertNull($this->runtime->ingest()->sender($sender->id)->sample);
        foreach ($this->runtime->ingest()->fields($sender->id) as $field) {
            self::assertNull($field['value']);
        }
        self::assertCount(1, $this->runtime->ingest()->senders());
        self::assertCount(0, iterator_to_array($this->runtime->live()->packets(self::NOW - 1, $this->clock->now())));
    }

    public function testKnownSendersSurviveADiscoveryBlockOnTheirSharedIp(): void
    {
        $password = $this->keys['wunderground'];
        $this->get($this->wu($password));
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(403, $this->get($this->wu('wrong'))->status);
        }
        self::assertSame(200, $this->get($this->wu($password))->status);
        $free = $this->runtime->ingest()->credentials()['wunderground'];
        self::assertSame(429, $this->get($this->wu($free))->status);
        self::assertSame($free, $this->runtime->ingest()->credentials()['wunderground']);
    }

    public function testMalformedReadingsBlockOnlyTheirAuthenticatedSender(): void
    {
        $password = $this->keys['wunderground'];
        $this->get($this->wu($password));
        [$sender] = $this->runtime->ingest()->senders();
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(400, $this->get(str_replace('tempf=68', 'tempf=bad', $this->wu($password)))->status);
        }
        self::assertSame(429, $this->get($this->wu($password))->status);
        $otherPassword = $this->runtime->ingest()->credentials()['wunderground'];
        self::assertSame(200, $this->get($this->wu($otherPassword))->status);
        $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame(32, $diagnostics->received);
        self::assertSame(31, $diagnostics->discarded);
        self::assertSame('sender rate limited', $diagnostics->lastError);
        $this->clock->advance(601);
        self::assertSame(200, $this->get($this->wu($password))->status);
    }

    public function testSenderLimitsAreSharedAcrossWorkersAndStillRespectOuterLimits(): void
    {
        $password = $this->keys['wunderground'];
        $this->get($this->wu($password));
        $otherPassword = $this->runtime->ingest()->credentials()['wunderground'];
        $this->get($this->wu($otherPassword));
        $other = Runtime::of(
            new Config(
                $this->runtime->config->settings,
                [],
                [],
                [],
                ingest: new IngestConfig(enabled: true, requestsPerMinute: 6, senderRequestsPerMinute: 1),
            ),
            $this->clock,
            new MemoryLogger(),
        );
        try {
            $receiver = new Receiver($other);
            $send = fn(string $key): Response => $receiver->handle('GET', self::WU, $this->wu($key), static fn(): string => '', '192.0.2.1', true);
            self::assertSame(200, $send($password)->status);
            self::assertSame(429, $send($password)->status);
            self::assertSame(200, $send($otherPassword)->status);
            self::assertSame(429, $send($otherPassword)->status);
            // The seventh request is blocked by the IP limit before authentication.
            self::assertSame('request rate limited', $send($otherPassword)->body);
            $this->clock->advance(60);
            self::assertSame(200, $send($password)->status);
        } finally {
            $other->close();
        }
    }

    public function testDiagnosticsDistinguishOutcomesAndPreserveClockFallback(): void
    {
        $body = $this->ecowitt();
        $this->post($body);
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, null, self::NOW);
        $this->clock->advance(16);
        $this->post($body);
        $this->clock->advance(16);
        $this->post($body);
        $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame([3, 1, 1, 1, 0], [$diagnostics->received, $diagnostics->stored, $diagnostics->duplicates, $diagnostics->pending, $diagnostics->discarded]);
        self::assertSame(16, $diagnostics->lastInterval);
        self::assertSame(-32, $diagnostics->clockOffset);
        self::assertSame('device', $diagnostics->timeSource);
        $this->post('PASSKEY=' . self::PASSKEY . '&tempf=bad');
        $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame('no measurements', $diagnostics->lastError);
        self::assertSame(self::NOW + 32, $diagnostics->lastErrorAt);
        $this->clock->advance(16);
        $this->post('PASSKEY=' . self::PASSKEY . '&tempf=68&dateutc=' . urlencode(gmdate('Y-m-d H:i:s', self::NOW + 1000)));
        $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame('server', $diagnostics->timeSource);
        self::assertSame('out_of_range', $diagnostics->timeReason);
        self::assertSame(952, $diagnostics->clockOffset);
        self::assertSame('no measurements', $diagnostics->lastError); // Last error remains dated after recovery.
        $this->runtime->ingest()->setState($sender->id, 'blocked');
        $this->post($body);
        $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame([6, 2, 1, 1, 2], [$diagnostics->received, $diagnostics->stored, $diagnostics->duplicates, $diagnostics->pending, $diagnostics->discarded]);
        self::assertSame(1, $this->runtime->ingest()->rejections()[0]['count']);
    }

    public function testKeyReplacementPreservesStateIdentityAndLiveHistory(): void
    {
        $old = $this->keys['wunderground'];
        $this->get($this->wu($old));
        [$sender] = $this->runtime->ingest()->senders();
        $store = $this->runtime->ingest();
        $store->adopt($sender->id, 'Garden', self::NOW);
        $this->get($this->wu($old));
        $free = $store->credentials()['wunderground'];
        $new = $store->replacePassword($sender->id);
        self::assertNotSame($old, $new);
        self::assertNotSame($free, $new);
        self::assertSame($free, $store->credentials()['wunderground']);
        self::assertSame(403, $this->get($this->wu($old))->status);
        $this->clock->advance(16);
        self::assertSame(200, $this->get(str_replace('tempf=68', 'tempf=69', $this->wu($new)))->status);
        $after = $store->sender($sender->id);
        self::assertNotNull($after);
        self::assertSame('adopted', $after->state);
        self::assertSame('Garden', $after->name);
        self::assertSame($sender->identity, $after->identity);
        self::assertCount(1, $store->senders());
        self::assertCount(2, iterator_to_array($this->runtime->live()->packets(self::NOW - 1, $this->clock->now())));
        $newFree = $store->rotateSetup(Protocol::Wunderground);
        self::assertNotSame($free, $newFree);
        self::assertSame(403, $this->get($this->wu($free))->status);
        self::assertSame(200, $this->get($this->wu($new))->status);
        $store->setState($sender->id, 'blocked');
        $new = $store->replacePassword($sender->id);
        self::assertSame('success', $this->get($this->wu($new))->body);
        self::assertFalse($this->receiver->wrote());
        self::assertSame('blocked', $store->sender($sender->id)?->state);
    }

    public function testRotatedEcowittPathPreservesAllSenderAssignments(): void
    {
        $this->post($this->ecowitt());
        $this->post(str_replace(self::PASSKEY, str_repeat('A', 32), $this->ecowitt()));
        $senders = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($senders[0]->id, null, self::NOW);
        $new = $this->runtime->ingest()->rotateSetup(Protocol::Ecowitt);
        self::assertNotSame($this->keys['ecowitt'], $new);
        self::assertSame(403, $this->post($this->ecowitt())->status);
        $this->keys['ecowitt'] = $new;
        self::assertSame(200, $this->post($this->ecowitt())->status);
        self::assertSame(200, $this->post(str_replace(self::PASSKEY, str_repeat('A', 32), $this->ecowitt()))->status);
        self::assertSame(array_column($senders, 'id'), array_column($this->runtime->ingest()->senders(), 'id'));
    }

    public function testFailedLiveWriteDoesNotAcknowledgeOrLoseItsDiagnosis(): void
    {
        $this->post($this->ecowitt());
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, null, self::NOW);
        $this->runtime->live();
        $db = Sqlite::open($this->runtime->config->settings->liveDbPath(), false, $this->runtime->config->settings->journalMode);
        try {
            $db->exec("CREATE TRIGGER fail_packet BEFORE INSERT ON packet BEGIN SELECT RAISE(ABORT, 'private failure detail'); END");
            $response = $this->post($this->ecowitt());
            self::assertSame(503, $response->status);
            self::assertSame('unavailable', $response->body);
            self::assertCount(0, iterator_to_array($this->runtime->live()->packets(self::NOW - 1, self::NOW + 1)));
            $diagnostics = $this->runtime->ingest()->diagnostics($sender->id);
            self::assertNotNull($diagnostics);
            self::assertSame('storage or processing failed', $diagnostics->lastError);
            self::assertSame(1, $diagnostics->discarded);
            $db->exec('DROP TRIGGER fail_packet');
            self::assertSame(200, $this->post($this->ecowitt())->status);
        } finally {
            $db->close();
        }
    }

    public function testUpgradeAddsDiagnosticsWithoutResettingExistingSendersOrKeys(): void
    {
        $this->get($this->wu($this->keys['wunderground']));
        [$sender] = $this->runtime->ingest()->senders();
        $this->runtime->ingest()->adopt($sender->id, 'Garden', self::NOW);
        $keys = $this->runtime->ingest()->credentials();
        $this->runtime->close();
        // The previous schema did not have either diagnostic table.
        $db = Sqlite::open($this->runtime->config->settings->ingestDbPath(), false, $this->runtime->config->settings->journalMode);
        $db->exec('DROP TABLE ingest_diagnostic');
        $db->exec('DROP TABLE ingest_rejection');
        $db->close();
        $this->clock->advance(16);
        self::assertSame(200, $this->get($this->wu($this->keys['wunderground']))->status);
        $store = $this->runtime->ingest();
        self::assertSame($keys, $store->credentials());
        self::assertCount(1, $store->senders());
        self::assertSame('Garden', $store->sender($sender->id)?->name);
        self::assertSame(2, $store->sender($sender->id)->received);
        $diagnostics = $store->diagnostics($sender->id);
        self::assertNotNull($diagnostics);
        self::assertSame(self::NOW + 16, $diagnostics->since);
        self::assertSame(1, $diagnostics->received);
        self::assertSame(1, $diagnostics->stored);
        self::assertSame(0, $diagnostics->pending);
    }

    public function testPeriodicMaintenanceDoesNotRescanValuesBeforeItsNextWindow(): void
    {
        $this->post($this->ecowitt());
        [$sender] = $this->runtime->ingest()->senders();
        $store = $this->runtime->ingest();
        $store->prune(self::NOW + 86300);
        $store->prune(self::NOW + 86401);
        self::assertNotNull($store->sender($sender->id)?->sample);
        self::assertSame(3, count(array_filter($store->fields($sender->id), static fn(array $field): bool => $field['value'] !== null)));
        $store->prune(self::NOW + 86900);
        self::assertNull($store->sender($sender->id)?->sample);
        self::assertSame(0, count(array_filter($store->fields($sender->id), static fn(array $field): bool => $field['value'] !== null)));
    }

    private function ecowitt(): string
    {
        return 'PASSKEY=' . self::PASSKEY . '&dateutc=' . urlencode(gmdate('Y-m-d H:i:s', self::NOW)) . '&tempf=68&humidity=50&dailyrainin=0.1';
    }

    private function wu(string $password): string
    {
        return 'ID=SAME&PASSWORD=' . $password . '&tempf=68&dateutc=' . urlencode(gmdate('Y-m-d H:i:s', self::NOW)) . '&action=updateraw';
    }

    private function post(string $body): Response
    {
        return $this->receiver->handle('POST', '/' . $this->keys['ecowitt'] . '/ecowitt/', '', static fn(): string => $body, '192.0.2.1', false);
    }

    private function get(string $query): Response
    {
        return $this->receiver->handle('GET', self::WU, $query, static fn(): string => '', '192.0.2.1', true);
    }
}
