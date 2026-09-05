<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Archive\MappingError;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Config\Settings;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Log\LogLevel;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\State\StateDb;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\UnitSystem;

final class ArchiverTest extends TestCase
{
    /** 2026-08-26 10:50:00 in Berlin, on a five-minute boundary. */
    private const T0 = 1_787_734_200;

    private string $dir;
    private Settings $settings;
    private LiveDb $live;
    private StateDb $state;
    private MemoryLogger $log;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('archiver');
        $this->settings = Archives::settings($this->dir);
        $this->live = LiveDb::open($this->dir . '/live.sdb', JournalMode::Wal);
        $this->state = StateDb::open($this->dir . '/state.sdb', JournalMode::Wal);
        $this->log = new MemoryLogger();
        $this->clock = new FixedClock(self::T0 + 3600);
    }

    protected function tearDown(): void
    {
        $this->live->close();
        $this->state->close();
        TempDir::remove($this->dir);
    }

    public function testBuildsARecordFromTheIntervalsPacketsAndSharpensTheDay(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0, 'outHumidity' => 50.0, 'windSpeed' => 2.0, 'windDir' => 90, 'dayRain' => 1.0]);
        $this->add(self::T0 + 100, ['outTemp' => 12.0, 'outHumidity' => 50.0, 'windSpeed' => 0.0, 'windDir' => 90, 'dayRain' => 1.2]);
        $this->add(self::T0 + 300, ['outTemp' => 14.0, 'outHumidity' => 50.0, 'windSpeed' => 4.0, 'windDir' => 180, 'dayRain' => 1.2]);
        $this->add(self::T0 + 301, ['outTemp' => 99.0]);
        $archiver = $this->archiver();

        $built = $archiver->build(self::T0 + 300);
        self::assertNotNull($built);
        self::assertSame(3, $built->packets);
        self::assertFalse($built->fromHardware);
        $record = $built->record;
        self::assertSame(self::T0 + 300, $record['dateTime']);
        self::assertSame(17, $record['usUnits']);
        self::assertSame(5, $record['interval']);
        self::assertSame(12.0, $record['outTemp']);
        self::assertSame(4.0, $record['windGust']);
        // The calm packet lost its direction, so the vector average is of the other two.
        self::assertNotNull($record['windDir']);
        self::assertEqualsWithDelta(0.2, $record['rain'], 1e-12);
        self::assertNotNull($record['dewpoint']);
        self::assertNotNull($record['windrun']);
        self::assertArrayHasKey('ET', $record);

        self::assertTrue($archiver->store($built));
        self::assertFalse($archiver->store($built));
        $archive = $archiver->archive();
        self::assertSame(1, $archive->count());
        $day = $archive->loadDay(self::T0 - 39_000, UnitSystem::METRICWX);
        $outTemp = $day->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame(14.0, $outTemp->max);
        self::assertSame(self::T0 + 300, $outTemp->maxtime);
        self::assertSame(10.0, $outTemp->min);
        self::assertSame(12.0 * 300, $outTemp->wsum);
        self::assertSame((string) (self::T0 + 300), $archive->getMeta('lastUpdate'));
    }

    public function testAnIntervalWithoutPacketsIsNoRecord(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);
        self::assertNull($this->archiver()->build(self::T0 + 600));
        self::assertNull($this->archiver()->build(self::T0));
    }

    public function testDueIntervalsWaitForTheGraceAndAreClearedOnceStored(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);
        $archiver = $this->archiver();
        $budget = Budget::unlimited($this->clock);

        self::assertSame(0, $archiver->processDue(self::T0 + 300 + 14, $budget));
        self::assertSame(1, $archiver->processDue(self::T0 + 300 + 15, $budget));
        self::assertSame(0, $archiver->processDue(self::T0 + 300 + 15, $budget));
        self::assertSame([], $this->live->due(self::T0 + 3600, 0, 'kirchdorf'));
        self::assertTrue($archiver->archive()->exists(self::T0 + 300));
    }

    public function testALatePacketIsLeftAloneUnlessToldToRebuild(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);
        $archiver = $this->archiver();
        $budget = Budget::unlimited($this->clock);
        self::assertSame(1, $archiver->processDue(self::T0 + 3600, $budget));

        // The interval is marked again by a packet that arrives after it was archived.
        $this->add(self::T0 + 200, ['outTemp' => 20.0]);
        self::assertCount(1, $this->live->due(self::T0 + 3600, 15, 'kirchdorf'));
        self::assertSame(0, $archiver->processDue(self::T0 + 3600, $budget));
        self::assertSame([], $this->live->due(self::T0 + 3600, 15, 'kirchdorf'));
        self::assertSame(10.0, $archiver->archive()->record(self::T0 + 300)['outTemp'] ?? null);

        $this->add(self::T0 + 250, ['outTemp' => 30.0]);
        self::assertSame(1, $archiver->processDue(self::T0 + 3600, $budget, replace: true));
        self::assertSame(20.0, $archiver->archive()->record(self::T0 + 300)['outTemp'] ?? null);
        // The day's sums followed the replacement rather than counting both.
        $outTemp = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame(20.0 * 300, $outTemp->wsum);
        // An integer column in WeeWX's day tables, so an integer once read back.
        self::assertEquals(300, $outTemp->sumtime);
    }

    public function testCatchUpFillsWhatIsMissingJumpsGapsAndStopsAtTheBudget(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);
        $this->add(self::T0 + 400, ['outTemp' => 11.0]);
        $this->add(self::T0 + 2 * 86400, ['outTemp' => 12.0]);
        $archiver = $this->archiver();

        $short = Budget::of($this->clock, 60.0, 1);
        $progress = $archiver->catchUp(null, null, $short);
        self::assertSame(1, $progress->built);
        self::assertFalse($progress->finished);
        self::assertSame(self::T0 + 600, $progress->stoppedBefore);

        $progress = $archiver->catchUp(null, null, Budget::unlimited($this->clock));
        self::assertSame(2, $progress->built);
        self::assertTrue($progress->finished);
        self::assertNull($progress->stoppedBefore);
        $archive = $archiver->archive();
        self::assertSame(3, $archive->count());
        self::assertTrue($archive->exists(self::T0 + 300));
        self::assertTrue($archive->exists(self::T0 + 600));
        self::assertTrue($archive->exists(self::T0 + 2 * 86400));
        self::assertSame([], $this->live->due(self::T0 + 3 * 86400, 0, 'kirchdorf'));

        // Once more: nothing to do, and quickly.
        self::assertSame(0, $archiver->catchUp(null, null, Budget::unlimited($this->clock))->built);
    }

    public function testASecondSenderCannotDoubleTheRain(): void
    {
        $this->add(self::T0 - 100, ['dayRain' => 1.0, 'outTemp' => 5.0]);
        $this->add(self::T0 - 100, ['dayRain' => 7.0, 'rain' => 3.0, 'outTemp' => 8.0], sender: 'dwd');
        $this->add(self::T0 + 100, ['dayRain' => 1.5, 'outTemp' => 5.0]);
        $this->add(self::T0 + 100, ['dayRain' => 9.0, 'rain' => 2.0, 'outTemp' => 8.0, 'wh31_batt' => 1], sender: 'dwd');
        $archiver = $this->archiver(Archives::config(fields: ['dwd' => ['outTemp' => 'extraTemp1']], database: $this->dir . '/kirchdorf.sdb'));

        $built = $archiver->build(self::T0 + 300);
        self::assertNotNull($built);
        self::assertSame(2, $built->packets);
        self::assertSame(0.5, $built->record['rain']);
        self::assertSame(5.0, $built->record['outTemp']);
        self::assertSame(8.0, $built->record['extraTemp1']);
        self::assertSame(1.0, $built->record['wh31_batt']);
        // Counted per placement, the run-up included: twice each here.
        self::assertSame(['dayRain' => 2, 'rain' => 2], $archiver->mapping()->dropped());
    }

    public function testAConsolesOwnRecordWinsAndIsFilledInFromThePackets(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0, 'windSpeed' => 3.0]);
        $this->add(self::T0 + 300, ['outTemp' => 20.0, 'outHumidity' => 40.0], kind: PacketKind::Archive, interval: 5.0);
        $built = $this->archiver()->build(self::T0 + 300);

        self::assertNotNull($built);
        self::assertTrue($built->fromHardware);
        self::assertSame(1, $built->packets);
        self::assertSame(20.0, $built->record['outTemp']);
        self::assertSame(3.0, $built->record['windSpeed']);
        self::assertSame(5.0, $built->record['interval']);
        // Derived on the console's readings, not on the packets' average.
        self::assertNotNull($built->record['dewpoint']);
    }

    public function testAForeignDatabaseIsRefusedUntilTheMappingSaysHow(): void
    {
        $path = $this->dir . '/foreign.sdb';
        ArchiveDb::open($path, JournalMode::Wal, new Policy(), $this->settings->timezone, create: true)->close();
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);

        try {
            $this->archiver(Archives::config(database: $path));
            self::fail('a foreign database must be refused');
        } catch (MappingError $error) {
            self::assertStringContainsString('auto_mapping', $error->getMessage());
        }
        $told = $this->archiver(Archives::config(database: $path, autoMapping: true));
        self::assertSame(1, $told->processDue(self::T0 + 3600, Budget::unlimited($this->clock)));

        // A database this application made is its own, now and on the next tick.
        $ours = $this->archiver();
        self::assertTrue($this->state->archive('kirchdorf')->createdByApp);
        $ours->close();
        $this->archiver();
    }

    public function testRebuildReplacesRecordsAndTheirDays(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0, 'outHumidity' => 50.0]);
        $this->add(self::T0 + 400, ['outTemp' => 20.0, 'outHumidity' => 50.0]);
        $archiver = $this->archiver();
        self::assertSame(2, $archiver->processDue(self::T0 + 3600, Budget::unlimited($this->clock)));

        // A correction: the first interval's packet was wrong, and somebody fixed the journal.
        $this->add(self::T0 + 20, ['outTemp' => 30.0, 'outHumidity' => 50.0]);
        self::assertSame(2, $archiver->rebuild(self::T0, self::T0 + 600));
        $archive = $archiver->archive();
        self::assertSame(20.0, $archive->record(self::T0 + 300)['outTemp'] ?? null);
        self::assertSame(20.0, $archive->record(self::T0 + 600)['outTemp'] ?? null);
        $outTemp = $archive->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame(2, $outTemp->count);
        self::assertSame(40.0 * 300, $outTemp->wsum);
        // The loop packets sharpened the day again: 30 was never a record's value.
        self::assertSame(30.0, $outTemp->max);
        self::assertSame(10.0, $outTemp->min);
    }

    public function testUntranslatedPacketsAndHomelessReadingsAreSaidOnce(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0, 'unicorns' => 3]);
        $this->add(self::T0 + 20, ['outTemp' => 11.0, 'unicorns' => 4]);
        $this->add(self::T0 + 30, ['tempf' => 50.0], dialect: 'ecowitt');
        $this->add(self::T0 + 40, ['tempf' => 51.0], dialect: 'ecowitt');
        $archiver = $this->archiver();
        self::assertSame(1, $archiver->processDue(self::T0 + 3600, Budget::unlimited($this->clock)));

        $errors = $this->log->messages(LogLevel::Error);
        self::assertCount(1, $errors);
        self::assertStringContainsString('ecowitt', $errors[0]);
        $homeless = array_values(array_filter($this->log->messages(LogLevel::Info), static fn(string $line): bool => str_contains($line, 'unicorns')));
        self::assertCount(1, $homeless);
    }

    // -- helpers ----------------------------------------------------------

    private function archiver(?ArchiveConfig $config = null): Archiver
    {
        $config ??= Archives::config(database: $this->dir . '/kirchdorf.sdb');
        return Archiver::open($config, $this->settings, $this->live, $this->state, $this->log, $this->clock->now());
    }

    /** @param array<string, mixed> $data */
    private function add(int $when, array $data, string $sender = 'ecowitt', PacketKind $kind = PacketKind::Loop, ?float $interval = null, ?string $dialect = null): void
    {
        $packet = new Packet($when, UnitSystem::METRICWX, $data, $sender, $sender, strtoupper($sender), $dialect, null, $kind, $interval);
        $this->live->add($packet, ['kirchdorf'], $this->settings->archiveInterval, now: $when + 1);
    }
}
