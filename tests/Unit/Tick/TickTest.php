<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Tick;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\Settings;
use WeewxPhp\Config\StationConfig;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Tick\Outcome;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

final class TickTest extends TestCase
{
    private const T0 = 1_787_734_200;

    private string $dir;
    private Settings $settings;
    private MemoryLogger $log;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('tick');
        $this->settings = Archives::settings($this->dir . '/data');
        $this->log = new MemoryLogger();
        $this->clock = new FixedClock(self::T0 + 3600);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testRunsEveryArchiveJudgesTheStationsAndRecordsTheRun(): void
    {
        $runtime = $this->runtime([$this->kirchdorf(), $this->shed()]);
        $this->add($runtime, self::T0 + 10, ['outTemp' => 10.0]);
        $this->add($runtime, self::T0 + 20, ['outTemp' => 3.0], 'dwd');

        $outcome = (new Tick($runtime))->run('cli');
        self::assertSame(Outcome::OK, $outcome->status);
        self::assertSame(1, $outcome->archives['kirchdorf']['records']);
        self::assertTrue($outcome->archives['kirchdorf']['caught_up']);
        self::assertSame(1, $outcome->archives['shed']['records']);
        self::assertSame('down', $outcome->stations['ecowitt']['status']);
        self::assertSame('ok', $outcome->stations['dwd']['status']);
        self::assertSame('kirchdorf,shed', $runtime->live()->getMeta(LiveDb::ARCHIVES_KEY));
        self::assertFileExists($this->dir . '/data/.htaccess');
        self::assertFileExists($this->dir . '/data/archives/kirchdorf.sdb');

        $state = $runtime->state();
        self::assertNotNull($state->archive('kirchdorf')->caughtUpAt);
        self::assertSame(1, $state->archive('shed')->recordsTotal);
        self::assertCount(1, $state->runs());
        self::assertSame('cli', $state->runs()[0]['trigger']);

        // Nothing new: nothing written, and the run still recorded.
        $again = (new Tick($runtime))->run('cron');
        self::assertSame(0, $again->archives['kirchdorf']['records']);
        self::assertCount(2, $state->runs());
        $runtime->close();
    }

    public function testAnotherTickHoldingTheLockMakesThisOneBusy(): void
    {
        $runtime = $this->runtime([$this->kirchdorf()]);
        $runtime->ensureDataDir();
        $held = Lock::tryAcquire($this->settings->lockPath());
        self::assertNotNull($held);
        try {
            $outcome = (new Tick($runtime))->run('http');
            self::assertSame(Outcome::BUSY, $outcome->status);
            self::assertSame([], $outcome->archives);
        } finally {
            $held->release();
        }
        self::assertSame(Outcome::OK, (new Tick($runtime))->run('http')->status);
        $runtime->close();
    }

    public function testActiveAnalysisDoesNotPreventTheStationTickFromArchiving(): void
    {
        $runtime = $this->runtime([$this->kirchdorf()]);
        $this->add($runtime, self::T0 + 10, ['outTemp' => 10.0]);
        $analysis = Lock::tryAcquire($runtime->config->settings->dataDir . '/analytics.lock');
        self::assertNotNull($analysis);
        $cache = new \WeewxPhp\Frontend\Cache($runtime->config->settings);
        try {
            $outcome = (new Tick($runtime))->run('ingest');
            self::assertSame(Outcome::OK, $outcome->status);
            self::assertSame(1, $outcome->archives['kirchdorf']['records']);
            self::assertSame(1, $outcome->analytics['busy']);
            self::assertSame(0, $outcome->analytics['failed']);
        } finally {
            $cache->close();
            $analysis->release();
            $runtime->close();
        }
    }

    public function testOneArchivesFailureLeavesTheOthersDone(): void
    {
        $runtime = $this->runtime([$this->kirchdorf(), $this->shed()]);
        $runtime->ensureDataDir();
        mkdir($this->dir . '/data/archives');
        // A database this application did not create, and no mapping for it.
        ArchiveDb::open($this->dir . '/data/archives/kirchdorf.sdb', JournalMode::Wal, new Policy(), $this->settings->timezone, create: true)->close();
        $this->add($runtime, self::T0 + 10, ['outTemp' => 10.0]);
        $this->add($runtime, self::T0 + 20, ['outTemp' => 3.0], 'dwd');

        $outcome = (new Tick($runtime))->run('cli');
        self::assertSame(Outcome::ERROR, $outcome->status);
        $error = $outcome->archives['kirchdorf']['error'];
        self::assertTrue(is_string($error) && str_contains($error, 'auto_mapping'), var_export($error, true));
        self::assertSame(1, $outcome->archives['shed']['records']);
        self::assertNotNull($runtime->state()->archive('kirchdorf')->lastError);
        self::assertNull($runtime->state()->archive('shed')->lastError);
        self::assertCount(1, $this->log->messages(LogLevel::Error));
        $runtime->close();
    }

    public function testStationsAreJudgedByTheirSilenceAndTransitionsLogged(): void
    {
        $runtime = $this->runtime([$this->kirchdorf()]);
        $this->add($runtime, self::T0, ['outTemp' => 10.0]);
        $stations = static fn(Outcome $outcome): mixed => $outcome->stations['ecowitt']['status'];

        $this->clock->set(self::T0 + 10);
        self::assertSame('ok', $stations((new Tick($runtime))->run('cli')));
        // Three intervals of sixteen seconds is 48: sixty is stale, four hundred is down.
        $this->clock->set(self::T0 + 60);
        self::assertSame('stale', $stations((new Tick($runtime))->run('cli')));
        $this->clock->set(self::T0 + 400);
        $outcome = (new Tick($runtime))->run('cli');
        self::assertSame('down', $stations($outcome));
        self::assertSame('unknown', $outcome->stations['dwd']['status']);
        self::assertSame(self::T0 + 400, $outcome->stations['ecowitt']['since']);

        $transitions = array_values(array_filter($this->log->messages(LogLevel::Info), static fn(string $line): bool => str_starts_with($line, 'station ecowitt')));
        self::assertSame([
            'station ecowitt: ok, was unknown (last heard 10 s ago)',
            'station ecowitt: stale, was ok (last heard 60 s ago)',
            'station ecowitt: down, was stale (last heard 400 s ago)',
        ], $transitions);
        $runtime->close();
    }

    public function testPrunesTheJournalEveryTenMinutes(): void
    {
        $this->settings = Archives::settings($this->dir . '/data', liveRetention: 3600);
        $runtime = $this->runtime([$this->kirchdorf()]);
        $this->add($runtime, self::T0 - 7200, ['outTemp' => 1.0]);
        $this->add($runtime, self::T0 + 3000, ['outTemp' => 2.0]);

        (new Tick($runtime))->run('cli');
        self::assertSame(1, $runtime->live()->count());

        $this->add($runtime, self::T0 - 7100, ['outTemp' => 1.5]);
        $this->clock->advance(599.0);
        (new Tick($runtime))->run('cli');
        self::assertSame(2, $runtime->live()->count());
        $this->clock->advance(1.0);
        (new Tick($runtime))->run('cli');
        self::assertSame(1, $runtime->live()->count());
        $runtime->close();
    }

    // -- helpers ----------------------------------------------------------

    /** @param list<ArchiveConfig> $archives */
    private function runtime(array $archives): Runtime
    {
        $byId = [];
        foreach ($archives as $archive) {
            $byId[$archive->id] = $archive;
        }
        $stations = [
            'ecowitt' => new StationConfig('ecowitt', 'HP2561AE Pro', 16, 3, 20),
            'dwd' => new StationConfig('dwd', 'DWD Freising', null, 3, 20),
        ];
        return Runtime::of(new Config($this->settings, $stations, $byId, ['colour: unknown setting, ignored']), $this->clock, $this->log);
    }

    private function kirchdorf(): ArchiveConfig
    {
        return Archives::config(database: $this->dir . '/data/archives/kirchdorf.sdb');
    }

    private function shed(): ArchiveConfig
    {
        return Archives::config(primary: 'dwd', senders: ['dwd'], database: $this->dir . '/data/archives/shed.sdb', id: 'shed');
    }

    /** @param array<string, mixed> $data */
    private function add(Runtime $runtime, int $when, array $data, string $sender = 'ecowitt'): void
    {
        $packet = new Packet($when, UnitSystem::METRICWX, $data, $sender, $sender, strtoupper($sender));
        $runtime->live()->add($packet, ['kirchdorf', 'shed'], $this->settings->archiveInterval, now: $when + 1);
    }
}
