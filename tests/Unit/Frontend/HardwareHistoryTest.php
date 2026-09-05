<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\ArchiveReader;
use WeewxPhp\Frontend\Computation;
use WeewxPhp\Frontend\Deferred;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\ResultCodec;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Spec;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;

final class HardwareHistoryTest extends TestCase
{
    private const START = 1788566400;
    private string $dir;
    private ArchiveDb $db;
    private Config $config;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('hardware-history');
        $archive = Archives::config(database: $this->dir . '/archive.sdb', timezone: 'UTC');
        $this->config = new Config(Archives::settings($this->dir, archiveInterval: 60), [], [$archive->id => $archive], []);
        $this->db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        TempDir::remove($this->dir);
    }

    /** @param array<string, mixed> $data */
    private function hardware(int $end, int $seconds, array $data, string $source = 'station'): void
    {
        $this->db->preserveHardware($source, ['dateTime' => self::START + $end, 'usUnits' => 17, 'interval' => $seconds / 60] + $data);
    }

    private function weather(): Weather
    {
        return new Weather($this->config, clock: new FixedClock(self::START + 86401), budget: new ReadBudget(maxRows: 100000, maxStatements: 10000, milliseconds: 10000));
    }

    public function testCoarseHistoryFillsTotalsWithoutCreatingFineMeasurements(): void
    {
        $this->hardware(300, 300, ['rain' => 0.6, 'outTemp' => 10]);
        $this->hardware(600, 300, ['rain' => 0.4, 'outTemp' => 20]);
        self::assertSame(0, $this->db->count());
        $wx = $this->weather();
        try {
            $rain = $wx->between(self::START, self::START + 600)->sum('rain')->value();
            self::assertSame(1.0, $rain->raw);
            self::assertSame(1.0, $rain->coverage);
            self::assertSame(15.0, $wx->between(self::START, self::START + 600)->avg('outTemp')->raw());
            $fine = $wx->between(self::START, self::START + 600)->series('rain', 60, 'sum')->series();
            self::assertCount(10, $fine->points);
            self::assertSame(array_fill(0, 10, null), array_column($fine->points, 'value'));
            self::assertCount(2, $fine->fallback);
            self::assertSame(['start' => self::START, 'end' => self::START + 300, 'value' => 0.6, 'coverage' => 1.0], $fine->fallback[0]);
            $cached = ResultCodec::decode(ResultCodec::encode($fine), 'UTC');
            self::assertInstanceOf(Series::class, $cached);
            self::assertSame($fine->fallback, $cached->fallback);
            self::assertEqualsWithDelta(0.6 / 25.4, $fine->to('inch')->fallback[0]['value'], 1e-12);
            $again = $wx->between(self::START, self::START + 600)->series('rain', 60, 'sum')->series();
            self::assertSame($fine->fallback, $again->fallback);
            $cumulative = $wx->between(self::START, self::START + 600)->series('rain', 60, 'cumulative')->series();
            self::assertSame(array_fill(0, 10, null), array_column($cumulative->points, 'value'));
            self::assertSame($fine->fallback, $cumulative->fallback);
            // A rain amount must never masquerade as a count or timestamp.
            self::assertSame([], $wx->between(self::START, self::START + 600)->series('rain', 60, 'count')->series()->fallback);
            self::assertSame([], $wx->between(self::START, self::START + 600)->series('rain', 60, 'mintime')->series()->fallback);
        } finally {
            $wx->close();
        }
    }

    public function testHardwarePrecedenceIsPerFieldAndNeverAddsOverlappingRain(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->db->addRecord(['dateTime' => self::START + $i * 60, 'usUnits' => 17, 'interval' => 1, 'rain' => 0.1, 'outTemp' => 12]);
        }
        $this->hardware(300, 300, ['rain' => 0.6]);
        $this->hardware(600, 300, ['rain' => 0.4]);
        $wx = $this->weather();
        try {
            self::assertSame(1.0, $wx->between(self::START, self::START + 600)->sum('rain')->raw());
            self::assertSame(12.0, $wx->between(self::START, self::START + 600)->avg('outTemp')->raw());
            $fine = $wx->between(self::START, self::START + 600)->series('rain', 60, 'sum')->series();
            self::assertSame(array_merge(array_fill(0, 5, 0.1), array_fill(0, 5, null)), array_column($fine->points, 'value'));
            self::assertCount(1, $fine->fallback);
            self::assertSame(self::START + 300, $fine->fallback[0]['start']);
            self::assertSame(1.0, $wx->between(self::START, self::START + 86400)->sum('rain')->raw());
        } finally {
            $wx->close();
        }
    }

    public function testMisalignedWindowsKeepWholeIntervalsAndHonestCoverage(): void
    {
        $this->hardware(300, 300, ['rain' => 1]);
        $this->hardware(600, 300, ['rain' => 2]);
        $wx = $this->weather();
        try {
            $series = $wx->between(self::START, self::START + 840)->series('rain', 420, 'sum')->series();
            self::assertSame([1.0, null], array_column($series->points, 'value'));
            self::assertEqualsWithDelta(300 / 420, $series->points[0]['coverage'], 1e-12);
            self::assertCount(1, $series->fallback);
            self::assertSame(self::START + 300, $series->fallback[0]['start']);
            self::assertSame(3.0, $wx->between(self::START, self::START + 840)->sum('rain')->raw());
        } finally {
            $wx->close();
        }
    }

    public function testVariableIntervalsUseDurationWeightsAndConflictsAreNotDoubleCounted(): void
    {
        $this->hardware(300, 300, ['outTemp' => 10]);
        $this->hardware(900, 600, ['outTemp' => 25]);
        $wx = $this->weather();
        try {
            self::assertSame(20.0, $wx->between(self::START, self::START + 900)->avg('outTemp')->raw());
        } finally {
            $wx->close();
        }
        $this->hardware(450, 300, ['rain' => 100], 'a');
        $this->hardware(600, 300, ['rain' => 200], 'b');
        $wx = $this->weather();
        try {
            self::assertNull($wx->between(self::START, self::START + 900)->sum('rain')->raw());
        } finally {
            $wx->close();
        }
    }

    public function testDailyHistoryWithoutFixedRowsAndResumableLargeLoggerBacklog(): void
    {
        for ($i = 1; $i <= 600; ++$i) {
            $this->hardware($i * 60, 60, ['rain' => 0.1, 'outTemp' => 10]);
        }
        $reader = new ArchiveReader($this->config->archives['kirchdorf'], new ReadBudget(milliseconds: 10000));
        $spec = new Spec(period: 'between', start: self::START, end: self::START + 86400, observation: 'rain', aggregate: 'sum');
        $work = new Computation($spec, $reader, self::START + 86401);
        try {
            self::assertFalse($work->step(new ReadBudget(milliseconds: 10000)));
            $work = Computation::restore($work->save(), $spec, $reader);
            self::assertTrue($work->step(new ReadBudget(milliseconds: 10000)));
            $value = $work->result(self::START + 86401);
            self::assertInstanceOf(Value::class, $value);
            self::assertEqualsWithDelta(60, $value->raw, 1e-10);
            self::assertEqualsWithDelta(36000 / 86400, $value->coverage, 1e-10);
        } finally {
            $reader->close();
        }
    }

    public function testCrossMidnightHardwareIsNeverDividedBetweenDays(): void
    {
        $this->hardware(60, 300, ['rain' => 5]);
        $wx = $this->weather();
        try {
            self::assertNull($wx->between(self::START, self::START + 86400)->sum('rain')->raw());
            self::assertSame(5.0, $wx->between(self::START - 300, self::START + 300)->sum('rain')->raw());
        } finally {
            $wx->close();
        }
    }

    public function testDailyQueryResumesAfterBudgetExhaustionInsideOneDay(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->hardware($i * 300, 300, ['rain' => 1]);
        }
        $reader = new ArchiveReader($this->config->archives['kirchdorf'], new ReadBudget(milliseconds: 10000));
        $spec = new Spec(period: 'between', start: self::START, end: self::START + 86400, observation: 'rain', aggregate: 'sum');
        $work = new Computation($spec, $reader, self::START + 86401);
        $done = false;
        $interruptions = 0;
        try {
            for ($attempt = 0; $attempt < 20 && !$done; ++$attempt) {
                try {
                    $done = $work->step(new ReadBudget(maxRows: 10, milliseconds: 10000));
                } catch (Deferred) {
                    ++$interruptions;
                    $work = Computation::restore($work->save(), $spec, $reader);
                }
            }
            self::assertTrue($done);
            self::assertGreaterThan(1, $interruptions);
            $value = $work->result(self::START + 86401);
            self::assertInstanceOf(Value::class, $value);
            self::assertSame(30.0, $value->raw);
        } finally {
            $reader->close();
        }
    }
}
