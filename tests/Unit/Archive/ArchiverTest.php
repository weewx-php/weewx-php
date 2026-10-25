<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Archive\Built;
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
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\VecStats;

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

    public function testCatchUpWaitsForTheOpenIntervalAndArchiveDelay(): void
    {
        $this->add(self::T0 + 120, ['rain' => 0.7]);
        $archiver = $this->archiver();
        self::assertSame(0, $archiver->catchUp(null, null, Budget::unlimited($this->clock), now: self::T0 + 120)->built);
        $this->add(self::T0 + 290, ['rain' => 1.8]);
        self::assertSame(0, $archiver->catchUp(null, null, Budget::unlimited($this->clock), now: self::T0 + 314)->built);
        self::assertSame(1, $archiver->catchUp(null, null, Budget::unlimited($this->clock), now: self::T0 + 315)->built);
        self::assertEqualsWithDelta(2.5, $archiver->archive()->record(self::T0 + 300)['rain'] ?? null, 1e-12);
        $archiver->close();
    }

    public function testReplacementDoesNotRebuildThePrunedRetentionEdge(): void
    {
        $this->add(self::T0 + 10, ['rain' => 0.5]);
        $this->add(self::T0 + 200, ['rain' => 0.5]);
        $archiver = $this->archiver();
        $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock));
        self::assertSame(1, $this->live->prune(self::T0 + 100));
        $this->add(self::T0 + 250, ['rain' => 0.0]);
        self::assertSame(0, $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock), true));
        self::assertSame(1.0, $archiver->archive()->record(self::T0 + 300)['rain'] ?? null);
        $archiver->close();
    }

    public function testReplayCompletionRetainsNewerRecordsInTheSameDay(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0]);
        $this->add(self::T0 + 400, ['outTemp' => 20.0]);
        $archiver = $this->archiver();
        $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock));
        $this->live->replay()->mark($archiver->config(), self::T0 + 10, self::T0 + 300, 300);
        $archiver->processReplay(self::T0 + 1000, Budget::unlimited($this->clock));
        $day = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX);
        self::assertSame(2, $day->get('outTemp')->statsTuple()[5]);
        self::assertSame(2, $archiver->archive()->count());
        $archiver->close();
    }

    public function testPartialRebuildKeepsOtherIntervalsLoopExtremesAndPacketTimes(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 10.0, 'windSpeed' => 1.0]);
        $this->add(self::T0 + 100, ['outTemp' => 10.0, 'windSpeed' => 1.0]);
        $this->add(self::T0 + 400, ['outTemp' => 20.0, 'windSpeed' => 2.0]);
        $this->add(self::T0 + 500, ['outTemp' => 40.0, 'windSpeed' => 8.0]);
        $archiver = $this->archiver();
        $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock));
        $sod = self::T0 - 39_000;
        $day = $archiver->archive()->loadDay($sod, UnitSystem::METRICWX);
        self::assertSame(self::T0 + 10, $day->get('outTemp')->statsTuple()[1]);
        self::assertSame(self::T0 + 500, $day->get('wind')->statsTuple()[3]);
        $archiver->rebuild(self::T0, self::T0 + 300);
        $day = $archiver->archive()->loadDay($sod, UnitSystem::METRICWX);
        self::assertSame(40.0, $day->get('outTemp')->statsTuple()[2]);
        self::assertSame(self::T0 + 10, $day->get('outTemp')->statsTuple()[1]);
        $archiver->close();
    }

    public function testLateReplacementKeepsExtremesWhoseLoopInputWasPruned(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 40.0]);
        $this->add(self::T0 + 100, ['outTemp' => 20.0]);
        $this->add(self::T0 + 1200, ['outTemp' => 25.0]);
        $this->add(self::T0 + 2410, ['outTemp' => 10.0]);
        $this->add(self::T0 + 2500, ['outTemp' => 20.0]);
        $archiver = $this->archiver();
        try {
            $archiver->processDue(self::T0 + 3600, Budget::unlimited($this->clock));
            self::assertSame(30.0, $archiver->archive()->record(self::T0 + 300)['outTemp'] ?? null);
            self::assertSame(2, $this->live->prune(self::T0 + 900));
            $this->add(self::T0 + 2600, ['outTemp' => 30.0]);
            self::assertSame(1, $archiver->processDue(self::T0 + 3600, Budget::unlimited($this->clock), true));
            $stats = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('outTemp');
            self::assertInstanceOf(ScalarStats::class, $stats);
            self::assertSame(40.0, $stats->max);
            self::assertSame(self::T0 + 10, $stats->maxtime);
            self::assertSame(75.0 * 300, $stats->wsum);
            self::assertSame(3, $stats->count);
        } finally {
            $archiver->close();
        }
    }

    public function testReplacingHardwareOnlyTemperatureRemovesItsOldExtreme(): void
    {
        $archiver = $this->archiver();
        try {
            $this->storeHardware($archiver, self::T0 + 300, ['outTemp' => 40.0]);
            $this->storeHardware($archiver, self::T0 + 300, ['outTemp' => 20.0]);
            $stats = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('outTemp');
            self::assertInstanceOf(ScalarStats::class, $stats);
            self::assertSame(20.0, $stats->max);
            self::assertSame(20.0, $stats->min);
            self::assertSame(1, $stats->count);
            self::assertSame(20.0 * 300, $stats->wsum);
        } finally {
            $archiver->close();
        }
    }

    public function testReplacingHardwareGustUpdatesItsSpeedAndDirection(): void
    {
        $archiver = $this->archiver();
        try {
            $this->storeHardware($archiver, self::T0 + 300, ['windSpeed' => 4.0, 'windDir' => 90.0, 'windGust' => 20.0, 'windGustDir' => 90.0]);
            $this->storeHardware($archiver, self::T0 + 300, ['windSpeed' => 3.0, 'windDir' => 180.0, 'windGust' => 8.0, 'windGustDir' => 180.0]);
            $stats = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('wind');
            self::assertInstanceOf(VecStats::class, $stats);
            self::assertSame(8.0, $stats->max);
            self::assertSame(180.0, $stats->maxDir);
            self::assertSame(self::T0 + 300, $stats->maxtime);
            self::assertSame(1, $stats->count);
            self::assertSame(900.0, $stats->wsum);
            self::assertEqualsWithDelta(0.0, $stats->xsum, 1e-10);
            self::assertEqualsWithDelta(-900.0, $stats->ysum, 1e-10);
            self::assertSame(300, $stats->dirsumtime);
            self::assertSame(9.0, $stats->squaresum);
            self::assertSame(2700.0, $stats->wsquaresum);
        } finally {
            $archiver->close();
        }
    }

    public function testReplacementTieTakesTheNewIntervalTimeAndGustDirection(): void
    {
        $archiver = $this->archiver();
        try {
            $this->storeHardware($archiver, self::T0 + 300, ['outTemp' => 20.0, 'windSpeed' => 2.0, 'windGust' => 20.0, 'windGustDir' => 90.0]);
            $this->storeHardware($archiver, self::T0 + 600, ['outTemp' => 10.0, 'windSpeed' => 1.0, 'windGust' => 10.0, 'windGustDir' => 0.0]);
            $this->storeHardware($archiver, self::T0 + 600, ['outTemp' => 20.0, 'windSpeed' => 1.0, 'windGust' => 20.0, 'windGustDir' => 180.0]);
            $day = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX);
            self::assertSame(self::T0 + 600, $day->get('outTemp')->statsTuple()[3]);
            self::assertSame(self::T0 + 600, $day->get('wind')->statsTuple()[3]);
            self::assertSame(180.0, $day->get('wind')->statsTuple()[8]);
        } finally {
            $archiver->close();
        }
    }

    public function testReplacingAnAverageWithoutLoopHiloRemovesItsOldExtreme(): void
    {
        $this->settings = Archives::settings($this->dir, loopHilo: false);
        $this->add(self::T0 + 10, ['outTemp' => 40.0]);
        $this->add(self::T0 + 100, ['outTemp' => 20.0]);
        $archiver = $this->archiver();
        try {
            $built = $archiver->build(self::T0 + 300);
            self::assertNotNull($built);
            $archiver->store($built);
            $archiver->store(new Built($built->stop, $built->seconds, ['outTemp' => 20.0] + $built->record, $built->accumulator, $built->packets, false, []), true);
            $stats = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX)->get('outTemp');
            self::assertInstanceOf(ScalarStats::class, $stats);
            self::assertSame(20.0, $stats->max);
            self::assertSame(20.0, $stats->min);
            self::assertSame(1, $stats->count);
        } finally {
            $archiver->close();
        }
    }

    public function testReplacingTheWinnerRecomputesPrunedRunnerUpFromAvailableInput(): void
    {
        $this->add(self::T0 + 10, ['outTemp' => 40.0, 'inTemp' => -20.0]);
        $this->add(self::T0 + 100, ['outTemp' => 20.0, 'inTemp' => 20.0]);
        $this->add(self::T0 + 1200, ['outTemp' => 25.0, 'inTemp' => 10.0]);
        $this->add(self::T0 + 2410, ['outTemp' => 10.0, 'inTemp' => 10.0]);
        $archiver = $this->archiver();
        try {
            $archiver->processDue(self::T0 + 3600, Budget::unlimited($this->clock));
            $this->storeHardware($archiver, self::T0 + 2700, ['outTemp' => 60.0, 'inTemp' => -60.0]);
            self::assertSame(2, $this->live->prune(self::T0 + 900));
            $this->storeHardware($archiver, self::T0 + 2700, ['outTemp' => 25.0, 'inTemp' => 10.0]);
            $sod = self::T0 - 39_000;
            [$expected] = $archiver->archive()->dayFromRecords($sod);
            $retained = $archiver->build(self::T0 + 2700);
            self::assertNotNull($retained);
            $expected->mergeHilo($retained->accumulator);
            $actual = $archiver->archive()->loadDay($sod, UnitSystem::METRICWX);
            self::assertEquals(array_slice($expected->get('outTemp')->statsTuple(), 2), array_slice($actual->get('outTemp')->statsTuple(), 2));
            self::assertEquals(array_slice($expected->get('inTemp')->statsTuple(), 0, 2), array_slice($actual->get('inTemp')->statsTuple(), 0, 2));
            self::assertSame(30.0, $actual->get('outTemp')->statsTuple()[2]);
            self::assertSame(0.0, $actual->get('inTemp')->statsTuple()[0]);
        } finally {
            $archiver->close();
        }
    }

    public function testLateReplacementRemovesExtremesFromTheOldRecord(): void
    {
        $this->add(self::T0 + 10, ['windSpeed' => 10.0]);
        $archiver = $this->archiver();
        $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock));
        $this->add(self::T0 + 200, ['windSpeed' => 0.0]);
        $archiver->processDue(self::T0 + 1000, Budget::unlimited($this->clock), true);
        $day = $archiver->archive()->loadDay(self::T0 - 39_000, UnitSystem::METRICWX);
        self::assertSame(5.0, $day->get('windSpeed')->statsTuple()[2]);
        $archiver->close();
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

    public function testHardwareRainReplacesLoopRainAndKeepsLoopExtremes(): void
    {
        $this->add(self::T0 + 10, ['rain' => 0.2, 'outTemp' => 10.0]);
        $this->add(self::T0 + 20, ['rain' => 0.3, 'outTemp' => 30.0]);
        $this->add(self::T0 + 300, ['rain' => 0.6, 'outTemp' => 19.0], kind: PacketKind::Archive, interval: 5.0);
        $archiver = $this->archiver();
        $built = $archiver->build(self::T0 + 300);
        self::assertNotNull($built);
        self::assertSame(0.6, $built->record['rain']);
        self::assertSame(19.0, $built->record['outTemp']);
        $archiver->store($built);
        $day = $archiver->archive()->loadDay(self::T0 - 39000, UnitSystem::METRICWX);
        $rain = $day->get('rain');
        $temperature = $day->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $rain);
        self::assertInstanceOf(ScalarStats::class, $temperature);
        self::assertSame(0.6, $rain->sum);
        self::assertSame(30.0, $temperature->max);
    }

    public function testHardwareSpansAreNeverSplitOrOverlaidOnDifferentIntervals(): void
    {
        $this->add(self::T0 + 60, ['rain' => 99.0], kind: PacketKind::Archive, interval: 1.0);
        $this->add(self::T0 + 299, ['rain' => 99.0], kind: PacketKind::Archive, interval: 5.0);
        $this->add(self::T0 + 300, ['rain' => 99.0], kind: PacketKind::Archive, interval: 10.0);
        $archiver = $this->archiver();
        self::assertNull($archiver->build(self::T0 + 300));
        $this->add(self::T0 + 20, ['rain' => 0.2]);
        $built = $archiver->build(self::T0 + 300);
        self::assertNotNull($built);
        self::assertFalse($built->fromHardware);
        self::assertSame(0.2, $built->record['rain']);
        self::assertSame(self::T0 + 300, $built->record['dateTime']);
        self::assertSame(5, $built->record['interval']);
        // The original history remains available in the live journal.
        self::assertCount(3, iterator_to_array($this->live->packets(self::T0, self::T0 + 300, PacketKind::Archive)));
    }

    public function testHardwareKeepsStationSelectionAndConvertsOriginalUnits(): void
    {
        $this->add(self::T0 + 20, ['rain' => 0.2, 'outTemp' => 10]);
        $this->live->add(new Packet(
            self::T0 + 300,
            UnitSystem::US,
            ['rain' => 0.1, 'outTemp' => 68],
            'ecowitt',
            kind: PacketKind::Archive,
            interval: 5,
        ), ['kirchdorf'], 300);
        // A second station must not overwrite the primary's outdoor readings.
        $this->add(self::T0 + 300, ['rain' => 99, 'outTemp' => 99], 'other', PacketKind::Archive, 5);
        $archiver = $this->archiver(Archives::config(database: $this->dir . '/kirchdorf.sdb', primary: 'ecowitt', senders: null));
        $built = $archiver->build(self::T0 + 300);
        self::assertNotNull($built);
        self::assertEqualsWithDelta(2.54, $built->record['rain'], 0.000001);
        self::assertSame(20.0, $built->record['outTemp']);
        self::assertSame(17, $built->record['usUnits']);
    }

    public function testHardwareTilesLargerArchiveByFieldWithCorrectWeightAndGust(): void
    {
        $this->add(self::T0 + 30, ['rain' => 99, 'outTemp' => 99, 'outHumidity' => 50]);
        $this->add(self::T0 + 300, ['rain' => 1, 'outTemp' => 10, 'windSpeed' => 2, 'windDir' => 350, 'windGust' => 9, 'windGustDir' => 80], kind: PacketKind::Archive, interval: 5);
        $this->add(self::T0 + 900, ['rain' => 2, 'outTemp' => 25, 'windSpeed' => 2, 'windDir' => 10, 'windGust' => 8, 'windGustDir' => 180], kind: PacketKind::Archive, interval: 10);
        $built = $this->archiver()->build(self::T0 + 900, 900);
        self::assertNotNull($built);
        self::assertSame(3.0, $built->record['rain']);
        self::assertSame(20.0, $built->record['outTemp']);
        self::assertSame(50.0, $built->record['outHumidity']);
        self::assertSame(9.0, $built->record['windGust']);
        self::assertSame(80, $built->record['windGustDir']);
        self::assertLessThan(10, $built->record['windDir']);
        self::assertSame(15.0, $built->record['interval']);
    }

    public function testCoarseHardwareIsPreservedBeforeLiveInputIsPruned(): void
    {
        $this->add(self::T0 + 600, ['rain' => 2, 'outTemp' => 20], kind: PacketKind::Archive, interval: 10);
        $archiver = $this->archiver();
        $archiver->processReplay(self::T0 + 3616, Budget::unlimited($this->clock));
        $archiver->processDue(self::T0 + 3616, Budget::unlimited($this->clock));
        self::assertSame(0, $archiver->archive()->count());
        self::assertCount(1, $archiver->archive()->hardwareRecords(self::T0, self::T0 + 600));
        $this->live->prune(self::T0 + 10 * 86400);
        self::assertSame(0, $this->live->count());
        $built = $archiver->build(self::T0 + 600, 600);
        self::assertNotNull($built);
        self::assertSame(2, $built->record['rain']);
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

    public function testUnmappedEcowittAnnualCounterRecoversRainWithoutAddingColumns(): void
    {
        $config = Archives::config(database: $this->dir . '/counter.sdb', fields: ['ecowitt' => ['dayRain' => 'rain', 'outTemp' => 'outTemp']], explicitMapping: true);
        $t = strtotime('2026-09-05 18:00:00 +0200');
        foreach ([[$t, '1.000', '0.100'], [$t + 43200, '1.300', '0.150']] as [$when, $year, $day]) {
            $data = \WeewxPhp\Ingest\Parser::observation(
                \WeewxPhp\Ingest\Protocol::Ecowitt,
                ['PASSKEY' => str_repeat('A', 32), 'dateutc' => 'now', 'yearlyrainin' => $year, 'dailyrainin' => $day, 'tempf' => '70'],
                $when,
            );
            $this->live->add(new Packet($when, $data->units, $data->data, 'ecowitt', 'ecowitt', 'test'), ['kirchdorf'], 300, now: $when);
        }
        $archiver = $this->archiver($config);
        $built = $archiver->build($t + 43200);
        self::assertNotNull($built);
        self::assertEqualsWithDelta(7.62, $built->record['rain'], 1e-9);
        self::assertNull($built->record['rainRate']);
        self::assertArrayNotHasKey('yearRain', $built->record);
        self::assertFalse($archiver->archive()->schema()->hasColumn('yearRain'));
        self::assertTrue($archiver->store($built));
        self::assertFalse($archiver->store($built));
        $reader = \WeewxPhp\Db\Sqlite::readOnly($config->database);
        self::assertSame(1, $reader->scalar('SELECT COUNT(*) FROM weewx_rain_evidence'));
        self::assertSame('yearRain', $reader->scalar('SELECT counter FROM weewx_rain_evidence'));
        self::assertEqualsWithDelta(7.62, $reader->scalar('SELECT SUM(rain) FROM archive'), 1e-9);
        $reader->close();
        $archiver->close();
    }

    public function testRoundedEcowittCountersRemainVisibleInEveryArchiveUnitSystem(): void
    {
        $before = ['totalrainin' => '179.902', 'yearlyrainin' => '179.902', 'monthlyrainin' => '1.461',
            'weeklyrainin' => '0.012', 'dailyrainin' => '0.012', 'eventrainin' => '0.051'];
        $after = ['totalrainin' => '179.909', 'yearlyrainin' => '179.909', 'monthlyrainin' => '1.469',
            'weeklyrainin' => '0.020', 'dailyrainin' => '0.020', 'eventrainin' => '0.059'];
        foreach ([[self::T0 - 30, $before], [self::T0 + 20, $after]] as [$when, $fields]) {
            $data = \WeewxPhp\Ingest\Parser::observation(
                \WeewxPhp\Ingest\Protocol::Ecowitt,
                $fields + ['PASSKEY' => str_repeat('A', 32), 'dateutc' => 'now', 'tempf' => '70'],
                $when,
            );
            $this->live->add(new Packet($when, $data->units, $data->data, 'ecowitt', 'ecowitt'), ['kirchdorf'], 300, now: $when);
        }
        foreach (UnitSystem::cases() as $system) {
            $config = Archives::config(
                database: $this->dir . '/' . $system->name . '.sdb',
                unitSystem: $system,
                explicitMapping: true,
                fields: ['ecowitt' => ['dayRain' => 'rain', 'outTemp' => 'outTemp']],
            );
            $archiver = $this->archiver($config);
            $built = $archiver->build(self::T0 + 300);
            self::assertNotNull($built);
            $unit = \WeewxPhp\Weewx\Units::standardUnit($system, 'group_rain');
            self::assertEqualsWithDelta(\WeewxPhp\Weewx\Units::convert(0.007, 'inch', $unit), $built->record['rain'], 1e-9);
            self::assertSame([], $built->rainEvidence);
            self::assertTrue($archiver->store($built));
            $wx = new \WeewxPhp\Frontend\Weather(
                new \WeewxPhp\Config\Config($this->settings, [], [$config->id => $config], []),
                clock: $this->clock,
                budget: new \WeewxPhp\Frontend\ReadBudget(milliseconds: 10000),
            );
            try {
                self::assertEqualsWithDelta(0.1778, $wx->day()->sum('rain')->value()->to('mm')->raw, 1e-9);
            } finally {
                $wx->close();
                $archiver->close();
            }
        }
    }

    public function testCounterPrecisionFollowsCalibrationAndLeavesOtherDriversStrict(): void
    {
        foreach (['ecowitt', 'native'] as $driver) {
            $sender = $driver;
            foreach ([[self::T0 - 30, 179.902, 0.012], [self::T0 + 20, 179.909, 0.020]] as [$when, $year, $day]) {
                $this->live->add(
                    new Packet($when, UnitSystem::US, ['yearRain' => $year, 'dayRain' => $day], $sender, $driver),
                    ['kirchdorf'],
                    300,
                    now: $when,
                );
            }
            $config = Archives::config(
                database: $this->dir . '/' . $driver . '.sdb',
                senders: [$sender],
                primary: $sender,
                explicitMapping: true,
                fields: [$sender => ['dayRain' => 'rain']],
                calibrate: [$sender => ['yearRain' => new \WeewxPhp\Config\Calibration(0, 2), 'dayRain' => new \WeewxPhp\Config\Calibration(0, 2)]],
            );
            $archiver = $this->archiver($config);
            try {
                $built = $archiver->build(self::T0 + 300);
                self::assertNotNull($built);
                if ($driver === 'ecowitt') {
                    self::assertEqualsWithDelta(0.3556, $built->record['rain'], 1e-9);
                    self::assertSame([], $built->rainEvidence);
                } else {
                    self::assertNull($built->record['rain']);
                    self::assertSame('conflict', $built->rainEvidence[0]['status']);
                }
            } finally {
                $archiver->close();
            }
        }
    }

    public function testRebuildReplacesObsoleteRainEvidence(): void
    {
        $this->add(self::T0 - 30, ['dayRain' => 1.0]);
        $this->add(self::T0 + 20, ['dayRain' => 1.2]);
        $archiver = $this->archiver();
        try {
            $built = $archiver->build(self::T0 + 300);
            self::assertNotNull($built);
            $legacy = new \WeewxPhp\Archive\Built(
                $built->stop,
                $built->seconds,
                ['rain' => null] + $built->record,
                $built->accumulator,
                $built->packets,
                $built->fromHardware,
                $built->dropped,
                [['start' => self::T0 - 30, 'stop' => self::T0 + 20, 'amount' => null,
                    'status' => 'conflict', 'counter' => 'dayRain', 'source' => 'ecowitt']],
            );
            self::assertTrue($archiver->store($legacy));
            $reader = \WeewxPhp\Db\Sqlite::readOnly($archiver->config()->database);
            try {
                self::assertSame(1, $reader->scalar('SELECT COUNT(*) FROM weewx_rain_evidence'));
                self::assertSame(1, $archiver->rebuild(self::T0, self::T0 + 300));
                self::assertSame(0, $reader->scalar('SELECT COUNT(*) FROM weewx_rain_evidence'));
                self::assertEqualsWithDelta(0.2, $reader->scalar('SELECT SUM(rain) FROM archive'), 1e-12);
            } finally {
                $reader->close();
            }
        } finally {
            $archiver->close();
        }
    }

    // -- helpers ----------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function storeHardware(Archiver $archiver, int $stop, array $data): void
    {
        $record = ['dateTime' => $stop, 'usUnits' => 17, 'interval' => 5] + $data;
        $archiver->store(new Built($stop, 300, $record, new Accum($stop - 300, $stop, UnitSystem::METRICWX), 0, true, []), true);
    }

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
