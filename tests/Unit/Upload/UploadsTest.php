<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\Settings;
use WeewxPhp\Config\StationConfig;
use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\State\StateDb;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\FakeConnection;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\FakeSocketFactory;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tests\Support\Uploads as UploadConfigs;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Trigger;
use WeewxPhp\Upload\Uploads;
use WeewxPhp\Weewx\UnitSystem;

final class UploadsTest extends TestCase
{
    /** 2026-08-26 10:50:00 in Berlin, on a five-minute boundary. */
    private const T0 = 1_787_734_200;

    private string $dir;
    private Settings $settings;
    private LiveDb $live;
    private StateDb $state;
    private MemoryLogger $log;
    private FixedClock $clock;
    private FakeHttpClient $http;
    private FakeSocketFactory $sockets;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('uploads');
        $this->settings = Archives::settings($this->dir);
        $this->live = LiveDb::open($this->dir . '/live.sdb', JournalMode::Wal);
        $this->state = StateDb::open($this->dir . '/state.sdb', JournalMode::Wal);
        $this->log = new MemoryLogger();
        $this->clock = new FixedClock(self::T0 + 900);
        $this->http = new FakeHttpClient();
        $this->sockets = new FakeSocketFactory();
    }

    protected function tearDown(): void
    {
        $this->live->close();
        $this->state->close();
        TempDir::remove($this->dir);
    }

    public function testSendsWhatTheArchiveHoldsSinceTheLastTimeAndRemembersIt(): void
    {
        $this->records(3);
        $uploads = $this->uploads([$this->wu()]);
        $archivers = $this->archivers();

        // First run: nothing sent yet, so only the newest goes.
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(1, $outcome['wu']['sent']);
        self::assertCount(1, $this->http->requests);
        self::assertStringContainsString('dateutc=2026-08-26%2009%3A05%3A00', $this->http->last()->url);
        self::assertSame(self::T0 + 900, $this->state->upload('wu')->through);

        // Two more records: both go, oldest first.
        $this->clock->advance(600);
        $this->records(5);
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(2, $outcome['wu']['sent']);
        self::assertCount(3, $this->http->requests);
        self::assertStringContainsString('09%3A10%3A00', $this->http->requests[1]->url);
        self::assertStringContainsString('09%3A15%3A00', $this->http->requests[2]->url);
        self::assertSame(self::T0 + 1500, $this->state->upload('wu')->through);

        // Nothing new: nothing sent, and said so.
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(['sent' => 0], $outcome['wu']);
        self::assertSame('nothing new', $this->state->upload('wu')->lastSummary);
        $this->close($archivers);
    }

    public function testACatchUpIsCappedAndNeverReachesPastTheHorizon(): void
    {
        $this->records(100);
        $this->state->noteUploadRun('wu', self::T0, 1, self::T0 + 300, 'seeded');
        $this->clock->set(self::T0 + 100 * 300 + 60);
        $uploads = $this->uploads([$this->wu(catchUp: 3)]);
        $archivers = $this->archivers();

        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(3, $outcome['wu']['sent']);
        // The horizon is six hours: the first record sent is the first inside it, not the one after the mark.
        $horizon = $this->clock->now() - 6 * 3600;
        self::assertStringContainsString(rawurlencode(gmdate('Y-m-d H:i:s', $this->firstRecordAfter($horizon))), $this->http->requests[0]->url);
        $this->close($archivers);
    }

    public function testAServiceWithoutBackfillGetsOnlyTheNewestAndOnlyWhileItIsFresh(): void
    {
        $this->records(3);
        $this->http->answer(200, '200');
        $uploads = $this->uploads([$this->weathercloud()]);
        $archivers = $this->archivers();

        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(1, $outcome['weathercloud']['sent']);
        self::assertCount(1, $this->http->requests);
        // Sent already: not again.
        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['weathercloud']);
        // Stale: an hour later the newest record is not what it is like now.
        $this->records(4);
        $this->clock->advance(3600);
        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['weathercloud']);
        self::assertCount(1, $this->http->requests);
        $this->close($archivers);
    }

    public function testAnIntervalUploadRunsOnTheHoursGrid(): void
    {
        $this->records(3);
        $this->sockets->queue(new FakeConnection("# hello\r\n"))->queue(new FakeConnection("# hello\r\n"));
        $uploads = $this->uploads([$this->cwop()]);
        $archivers = $this->archivers();

        // 11:05 Berlin: due, never run.
        self::assertSame(1, $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['cwop']['sent']);
        // 11:09: the ten-minute slot has not passed.
        $this->clock->advance(240);
        $this->records(4);
        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['cwop']);
        // 11:10: due again.
        $this->clock->advance(60);
        self::assertSame(1, $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['cwop']['sent']);
        self::assertCount(2, $this->sockets->opened);
        $this->close($archivers);
    }

    public function testARefusedPasswordSwitchesTheUploadOffForAnHourAndIsSaidOnce(): void
    {
        $this->records(2);
        $this->http->answer(200, 'badauth')->answer(200, 'success');
        $uploads = $this->uploads([$this->wu()]);
        $archivers = $this->archivers();

        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertTrue($outcome['wu']['blocked']);
        self::assertNotNull($this->state->upload('wu')->blocked);
        self::assertCount(1, $this->log->messages(LogLevel::Error));

        $this->clock->advance(1800);
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(['blocked' => $this->state->upload('wu')->blocked], $outcome['wu']);
        self::assertCount(1, $this->http->requests);

        // An hour later it is tried again, and works.
        $this->clock->advance(1800);
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(1, $outcome['wu']['sent']);
        self::assertNull($this->state->upload('wu')->blocked);
        self::assertContains('upload wu is working again', $this->log->messages(LogLevel::Info));
        $this->close($archivers);
    }

    public function testATransientFailureIsCountedAndTheRecordKept(): void
    {
        $this->records(2);
        $this->http->answer(503, 'later')->answer(200, 'success');
        $uploads = $this->uploads([$this->wu()]);
        $archivers = $this->archivers();

        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(1, $outcome['wu']['failed']);
        self::assertSame(1, $this->state->upload('wu')->failures);
        self::assertSame(0, $this->state->upload('wu')->through);
        self::assertCount(1, $this->log->messages(LogLevel::Warning));

        self::assertSame(1, $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['wu']['sent']);
        self::assertSame(0, $this->state->upload('wu')->failures);
        $this->close($archivers);
    }

    public function testTheBudgetDecidesWhoRunsAndWhoWaitedLongestGoesFirst(): void
    {
        $this->records(2);
        $this->state->noteUploadRun('wu', self::T0, 0, null, '');
        $uploads = $this->uploads([$this->wu(), $this->wu('pws', Kind::PwsWeather)]);
        $archivers = $this->archivers();

        // No time at all: both wait.
        $spent = Budget::of($this->clock, 5.0, 100);
        $this->clock->advance(6);
        $outcome = $uploads->run($spent, $archivers, $this->clock->now());
        self::assertSame(['skipped' => 'no time left in this tick'], $outcome['wu']);
        self::assertSame(['skipped' => 'no time left in this tick'], $outcome['pws']);
        self::assertCount(0, $this->http->requests);

        // Never run goes before last run at T0.
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertSame(['pws', 'wu'], array_keys($outcome));
        $this->close($archivers);
    }

    public function testAManualUploadRunsOnlyWhenForcedAndAgainAfterARewind(): void
    {
        $this->records(2);
        $uploads = $this->uploads([$this->wu(trigger: Trigger::Manual)]);
        $archivers = $this->archivers();

        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['wu']);
        self::assertSame(1, $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now(), forced: true)['wu']['sent']);
        // Forcing does not resend what the service has; a rewound mark does.
        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now(), forced: true, only: ['wu'])['wu']);
        $this->state->rewindUpload('wu', self::T0);
        self::assertSame(2, $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now(), forced: true, only: ['wu'])['wu']['sent']);
        self::assertSame([], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now(), forced: true, only: ['nobody']));
        $this->close($archivers);
    }

    public function testTheLiveTriggerPublishesTheNewestPacketOnce(): void
    {
        $this->records(2);
        $this->sockets->queue(new FakeConnection("\x20\x02\x00\x00"))->queue(new FakeConnection("\x20\x02\x00\x00"));
        $uploads = $this->uploads([$this->mqtt()]);
        $archivers = $this->archivers();

        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        self::assertGreaterThan(2, $outcome['broker']['sent']);
        self::assertSame(self::T0 + 2 * 300 - 10, $this->state->upload('broker')->through);
        self::assertSame(['sent' => 0], $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now())['broker']);
        $this->close($archivers);
    }

    public function testAnUploadThatCannotBeBuiltIsReportedNotFatal(): void
    {
        $this->records(1);
        $uploads = $this->uploads([UploadConfigs::config(Kind::Cwop, ['station' => 'DW1'], id: 'cwop'), $this->wu()], noPlace: true);
        $archivers = $this->archivers(noPlace: true);
        $outcome = $uploads->run(Budget::unlimited($this->clock), $archivers, $this->clock->now());
        $error = $outcome['cwop']['error'];
        self::assertTrue(is_string($error) && str_contains($error, 'needs a latitude'), var_export($error, true));
        self::assertSame(1, $outcome['wu']['sent']);
        $this->close($archivers);
    }

    // -- helpers ----------------------------------------------------------

    /** @param list<UploadConfig> $uploads */
    private function uploads(array $uploads, bool $noPlace = false): Uploads
    {
        $byId = [];
        foreach ($uploads as $upload) {
            $byId[$upload->id] = $upload;
        }
        $archive = $noPlace
            ? Archives::config(database: $this->dir . '/kirchdorf.sdb', latitude: null, longitude: null)
            : Archives::config(database: $this->dir . '/kirchdorf.sdb');
        $config = new Config($this->settings, ['ecowitt' => new StationConfig('ecowitt', 'E', 16, 3, 20)], ['kirchdorf' => $archive], [], $byId);
        return new Uploads($config, $this->state, $this->log, $this->http, $this->sockets);
    }

    /** @return array<string, Archiver> */
    private function archivers(bool $noPlace = false): array
    {
        $archive = $noPlace
            ? Archives::config(database: $this->dir . '/kirchdorf.sdb', latitude: null, longitude: null)
            : Archives::config(database: $this->dir . '/kirchdorf.sdb');
        $archiver = Archiver::open($archive, $this->settings, $this->live, $this->state, $this->log, $this->clock->now());
        $archiver->catchUp(null, null, Budget::unlimited($this->clock));
        return ['kirchdorf' => $archiver];
    }

    /** @param array<string, Archiver> $archivers */
    private function close(array $archivers): void
    {
        foreach ($archivers as $archiver) {
            $archiver->close();
        }
    }

    /** Packets for the first `count` intervals after T0, and the records built from them. */
    private function records(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $when = self::T0 + ($i + 1) * 300 - 10;
            $packet = new Packet($when, UnitSystem::METRICWX, ['outTemp' => 20.0 + $i, 'outHumidity' => 50.0, 'windSpeed' => 2.0, 'windDir' => 90.0, 'dayRain' => 0.2 * $i], 'ecowitt', 'ecowitt', 'E');
            $this->live->add($packet, ['kirchdorf'], 300, now: $when + 1);
        }
        $archiver = Archiver::open(Archives::config(database: $this->dir . '/kirchdorf.sdb'), $this->settings, $this->live, $this->state, $this->log, self::T0 + $count * 300 + 600);
        $archiver->catchUp(null, null, Budget::unlimited($this->clock));
        $archiver->close();
    }

    private function firstRecordAfter(int $moment): int
    {
        return (intdiv($moment, 300) + 1) * 300;
    }

    private function wu(string $id = 'wu', Kind $kind = Kind::Wunderground, ?int $catchUp = null, ?Trigger $trigger = null): UploadConfig
    {
        return UploadConfigs::config($kind, ['station' => 'S', 'password' => 'p'], id: $id, catchUp: $catchUp, trigger: $trigger);
    }

    private function weathercloud(): UploadConfig
    {
        return UploadConfigs::config(Kind::Weathercloud, ['wid' => 'w', 'key' => 'k'], id: 'weathercloud');
    }

    private function cwop(): UploadConfig
    {
        return UploadConfigs::config(Kind::Cwop, ['station' => 'DW1234'], id: 'cwop');
    }

    private function mqtt(): UploadConfig
    {
        return UploadConfigs::config(Kind::Mqtt, ['host' => 'broker', 'client_id' => 'c'], id: 'broker');
    }
}
