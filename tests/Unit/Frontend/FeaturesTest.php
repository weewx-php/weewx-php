<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Query;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\ResultCodec;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Value;
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

final class FeaturesTest extends TestCase
{
    private string $dir;
    private Config $config;
    private ArchiveDb $db;
    private FixedClock $clock;
    /** @var list<Weather> */
    private array $handles = [];

    protected function setUp(): void
    {
        $this->dir = TempDir::create('frontend-features');
        $archive = Archives::config(database: $this->dir . '/weather.sdb');
        $this->config = new Config(Archives::settings($this->dir), [], [$archive->id => $archive], []);
        $this->db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        foreach (['2022-09-01' => 1, '2023-09-01' => 2, '2024-09-01' => 3, '2024-09-02' => 0, '2024-09-04' => 0, '2024-09-05' => 0] as $date => $rain) {
            $this->day($date, $rain);
        }
        $this->clock = new FixedClock($this->ts('2024-09-06 12:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->handles as $wx) {
            $wx->close();
        }
        $this->db->close();
        TempDir::remove($this->dir);
    }

    private function ts(string $date): int
    {
        return Span::timestamp($date, new DateTimeZone('Europe/Berlin'));
    }

    private function day(string $date, float $rain): void
    {
        $start = $this->ts($date);
        foreach ([1, 2, 3, 4] as $i) {
            $this->db->addRecord(['dateTime' => $start + $i * 21600, 'usUnits' => 17, 'interval' => 360,
                'rain' => $rain, 'outTemp' => $i * 10, 'ET' => 0.001]);
        }
    }

    private function wx(): Weather
    {
        $wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(maxRows: 100000, maxStatements: 10000, milliseconds: 10000));
        $this->handles[] = $wx;
        return $wx;
    }

    private function prepare(Query $query): void
    {
        $query->register();
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger());
        try {
            for ($i = 0; $i < 40; ++$i) {
                $answer = (new Worker($runtime))->run(Budget::of($this->clock, 10, 100000));
                self::assertSame(0, $answer['failed']);
                if ($answer['pending'] === 0) {
                    return;
                }
            }
            self::fail('Worker did not finish');
        } finally {
            $runtime->close();
        }
    }

    public function testOutputProfileConvertsValuesSeriesAndPendingWithoutSharingPresentation(): void
    {
        $wx = $this->wx();
        $de = $wx->output(new Output('de', ['group_temperature' => 'degree_F'], ['degree_F' => 2], missing: '<off>'));
        try {
            self::assertSame('104,00 °F', $de->current('outTemp')->format());
            self::assertSame(40.0, $wx->current('outTemp')->raw());
            $series = $de->on('2024-09-01')->series('outTemp', '6h')->series();
            self::assertSame([50.0, 68.0, 86.0, 104.0], array_column($series->points, 'value'));
            self::assertSame('50,00 °F', $series->formatted()[0]['value']);
            $cache = $de->cacheOnly();
            try {
                self::assertSame('&lt;off&gt;', $cache->on('2020')->min('outTemp')->value()->to('degree_C')->html());
                self::assertSame('pending', $cache->on('2020')->series('outTemp')->to('degree_C')->status);
                self::assertSame(0, array_sum(array_column($cache->diagnostics(), 'rows')));
            } finally {
                $cache->close();
            }
            self::assertSame('—', (new Value(null, status: 'pending'))->to('degree_C')->format());
        } finally {
            $de->close();
        }
    }

    public function testCalendarDatesAndElapsedDurationsHaveDifferentMeaningAtMidnightAndDst(): void
    {
        $wx = $this->wx();
        self::assertSame($this->ts('2024-09-05'), $wx->reference('archive')->day()->span()->start);
        self::assertSame($this->ts('2024-09-06'), $wx->today()->span()->start);
        $fixed = $wx->reference('fixed', '2024-04-01 00:00:00');
        try {
            self::assertSame($this->ts('2024-04-01'), $fixed->day()->span()->start);
            self::assertSame(7 * 86400 - 3600, $fixed->days(7)->span()->length());
            self::assertSame(168 * 3600, $fixed->last('7d')->span()->length());
            self::assertCount(7, $fixed->days(7)->series('rain', 'day')->series()->points);
        } finally {
            $fixed->close();
        }
    }

    public function testAutomaticSemanticsSumEtAndWeightVariableArchiveIntervals(): void
    {
        self::assertSame(0.004, $this->wx()->on('2024-09-01')->series('ET', 'day')->series()->points[0]['value']);
        $start = $this->ts('2024-09-07');
        $this->db->addRecord(['dateTime' => $start + 300, 'interval' => 5, 'usUnits' => 17, 'outTemp' => 10]);
        $this->db->addRecord(['dateTime' => $start + 1200, 'interval' => 15, 'usUnits' => 17, 'outTemp' => 30]);
        $period = $this->wx()->between($start + 1, $start + 1200);
        self::assertSame(20.0, $period->avg('outTemp')->raw());
        self::assertSame(25.0, $period->series('outTemp', '20m')->series()->points[0]['value']);
    }

    public function testThemeOwnershipReleasesOnlyRemovedExclusiveQueries(): void
    {
        $wx = $this->wx();
        $one = $wx->day()->sum('rain');
        $two = $wx->day()->max('outTemp');
        $manual = $wx->day()->min('outTemp')->register();
        $a = $wx->syncTheme('a', ['rain' => $one, 'high' => $two]);
        $wx->syncTheme('b', ['rain' => $one]);
        $wx->syncTheme('a', []);
        $cache = new Cache($this->config->settings);
        try {
            self::assertNotNull($cache->request($a['rain']));
            self::assertNull($cache->request($a['high']));
            $wx->deactivateTheme('b');
            self::assertNull($cache->request($a['rain']));
            self::assertNotNull($cache->request($manual));
        } finally {
            $cache->close();
        }
    }

    public function testRankExcludesMissingDaysAndSharesClosedStateAcrossAggregates(): void
    {
        $wx = $this->wx();
        $wx->between('2024-09-01', '2024-09-06')->series('rain', 'day', 'sum')->get();
        $other = $this->wx();
        self::assertSame(12.0, $other->between('2024-09-01', '2024-09-06')->sum('rain')->raw());
        $sources = array_values($other->diagnostics())[0]['sources'];
        self::assertIsArray($sources);
        self::assertContains('aggregate_state', $sources);
        $query = $wx->between('2024-09-01', '2024-09-06')->series('rain', 'day')->completed(0.95)->rank(2, true)->nightly();
        $this->prepare($query);
        $report = $query->report();
        self::assertSame([0.0, 0.0], array_column($report->periods->points, 'value'));
        self::assertSame(4, $report->meta['populationCount']);
        $excluded = $report->meta['excluded'];
        self::assertIsArray($excluded);
        self::assertIsArray($excluded[0]);
        self::assertSame('coverage', $excluded[0]['reason']);
        self::assertSame($this->ts('2024-09-03'), $excluded[0]['start']);
        self::assertEquals($report, ResultCodec::decode(ResultCodec::encode($report), 'Europe/Berlin'));
    }

    public function testDrySpellsBreakAtMissingDaysAndEventTimeUsesExplicitResolution(): void
    {
        $query = $this->wx()->between('2024-09-01', '2024-09-06')->series('rain', 'day')->longestSpell()->nightly();
        $this->prepare($query);
        $report = $query->report();
        self::assertSame(2, $report->value('intervals')->raw);
        self::assertSame(2 * 86400, $report->value('duration')->raw);
        self::assertSame($this->ts('2024-09-04'), $report->value('start')->raw);
        self::assertSame(3 * 86400, $report->value('matchingDuration')->raw);
        self::assertSame('break', $report->meta['gapPolicy']);
        $rain = $this->wx()->between('2024-09-01', '2024-09-06')->series('rain', 'day')->lastEvent(0, 'gt', 'mm');
        $this->prepare($rain);
        self::assertSame($this->ts('2024-09-02'), $rain->report()->value('last')->raw);
    }

    public function testMonthToDateComparisonHasMatchedDaysAndReferenceYears(): void
    {
        $wx = $this->wx()->reference('fixed', '2024-09-02 00:00:00');
        try {
            $query = $wx->month()->sum('rain')->compareYears();
            $this->prepare($query);
            $report = $query->report();
            self::assertSame([2022, 2023], $report->meta['referenceYears']);
            self::assertSame(12.0, $report->value('current')->raw);
            self::assertSame(6.0, $report->value('mean')->raw);
            self::assertSame(200.0, $report->value('percentOfMean')->raw);
            foreach ($report->periods->points as $point) {
                self::assertSame(86400, $point['end'] - $point['start']);
            }
        } finally {
            $wx->close();
        }
    }

    public function testDatasetKeepsCalendarSlotsAndRejectsIncompatibleGrids(): void
    {
        $wx = $this->wx();
        $data = $wx->dataset(['rain' => $wx->on('2024')->series('rain', 'month'), 'temperature' => $wx->on('2024')->series('outTemp', 'month')]);
        $grid = $data->aligned();
        self::assertCount(12, $grid);
        self::assertNull($grid[0]['values']['rain']);
        self::assertSame(12.0, $grid[8]['values']['rain']);
        self::assertSame('month', $wx->on('2024')->series('rain', 'month')->spec()->every);
        $this->expectException(QueryError::class);
        $wx->dataset(['a' => $wx->on('2024-09-01')->series('rain', '6h'), 'b' => $wx->on('2024-09-01')->series('rain', '12h')])->aligned();
    }

    public function testPrioritiesServeCurrentDataBeforeHistory(): void
    {
        $wx = $this->wx();
        $historic = $wx->alltime()->max('outTemp')->priority(-5)->register();
        $current = $wx->current('outTemp')->register();
        $cache = new Cache($this->config->settings);
        try {
            $jobs = $cache->due($this->clock->now());
            self::assertSame($current, $jobs[0]['id']);
            self::assertSame($historic, $jobs[1]['id']);
        } finally {
            $cache->close();
        }
    }

    public function testLiveSnapshotIsMappedConvertedAndDoesNotChangeArchiveRain(): void
    {
        $live = LiveDb::open($this->config->settings->liveDbPath(), JournalMode::Wal);
        $live->add(new Packet($this->clock->now() - 10, UnitSystem::US, ['outTemp' => 68, 'rain' => 1.0], 'ecowitt'), [], 300);
        $wx = $this->wx();
        try {
            self::assertSame(20.0, $wx->live('outTemp')->raw);
            self::assertSame('stale', $wx->live('outTemp', 5)->status);
            self::assertSame(0.0, $wx->day()->sum('rain')->raw());
            self::assertSame('unavailable', $wx->live('soilTemp1')->status);
        } finally {
            $live->close();
        }
    }

    public function testLatestValidDiffersFromLastArchiveRecordAndSensorAvailability(): void
    {
        $this->db->addRecord(['dateTime' => $this->ts('2024-09-06 06:00:00'), 'interval' => 360, 'usUnits' => 17, 'outTemp' => null]);
        $wx = $this->wx();
        self::assertNull($wx->current('outTemp')->raw());
        self::assertSame(40.0, $wx->latest('outTemp')->raw());
        self::assertSame($this->ts('2024-09-06'), $wx->latest('outTemp')->value()->asOf);
        self::assertSame('missing', $wx->measurement('outTemp')['availability']);
        self::assertSame('unavailable', $wx->measurement('unknownProbe')['availability']);
    }

    public function testFixedReferenceExcludesLaterMeasurementsAndLeapYearsExplainExclusions(): void
    {
        $wx = $this->wx()->reference('fixed', '2024-09-01 12:00:00');
        try {
            self::assertSame(6.0, $wx->day()->sum('rain')->raw());
            self::assertSame(12.0, $wx->on('2024-09-01')->sum('rain')->raw());
        } finally {
            $wx->close();
        }
        $leap = $this->wx()->reference('fixed', '2024-02-29 12:00:00');
        try {
            $query = $leap->month()->sum('rain')->compareYears();
            $this->prepare($query);
            $excluded = $query->report()->meta['excluded'];
            self::assertIsArray($excluded);
            self::assertContains(['year' => 2022, 'reason' => 'invalid-leap-date'], $excluded);
            self::assertContains(['year' => 2023, 'reason' => 'invalid-leap-date'], $excluded);
        } finally {
            $leap->close();
        }
    }

    public function testQuantileUsesActualBucketPopulationAndDifferencesConvertWithoutOffset(): void
    {
        $query = $this->wx()->between('2024-09-01', '2024-09-06')->series('rain', 'day')->completed()->quantile(0.5);
        $this->prepare($query);
        $report = $query->report();
        self::assertSame(0.0, $report->value('quantile')->raw);
        self::assertSame('interval-aggregates', $report->meta['population']);
        $value = new Value(10.0, 'degree_C', 'group_temperature', delta: true);
        self::assertSame(18.0, $value->to('degree_F')->raw);
        $decoded = ResultCodec::decode(ResultCodec::encode($value), 'UTC');
        self::assertInstanceOf(Value::class, $decoded);
        self::assertSame(18.0, $decoded->to('degree_F')->raw);
        $series = new Series([['start' => 0, 'end' => 1, 'value' => 10.0, 'coverage' => 1.0]], 'degree_C', 'group_temperature', delta: true);
        self::assertSame(18.0, $series->to('degree_F')->value(0)->raw);
        self::assertEquals($series, ResultCodec::decode(ResultCodec::encode($series), 'UTC'));
    }

    public function testCorruptManifestRollsBackAndBackfillInvalidatesSharedStates(): void
    {
        $wx = $this->wx();
        $sum = $wx->on('2024-09-01')->sum('rain');
        self::assertSame(12.0, $sum->raw());
        $ids = $wx->syncTheme('stable', ['sum' => $sum]);
        try {
            $wx->syncTheme('stable', ['other' => $wx->day()->max('outTemp'), 'bad' => $wx->day()->aggregate('rain', 'invalid')]);
            self::fail('Invalid priority was accepted');
        } catch (QueryError) {
            $cache = new Cache($this->config->settings);
            try {
                self::assertNotNull($cache->request($ids['sum']));
            } finally {
                $cache->close();
            }
        }
        $this->db->addRecord(['dateTime' => $this->ts('2024-09-01 06:00:00'), 'interval' => 360, 'usUnits' => 17, 'rain' => 10.0], true);
        $updated = $this->wx()->on('2024-09-01')->sum('rain');
        $this->prepare($updated);
        self::assertSame(19.0, $updated->raw());
    }

    public function testMultipleArchivesAlignWithoutBorrowingEachOthersLastTimestamp(): void
    {
        $other = Archives::config(database: $this->dir . '/other.sdb', id: 'other');
        $db = ArchiveDb::open($other->database, JournalMode::Wal, new Policy(), $other->timezone, create: true);
        $db->addRecord(['dateTime' => $this->ts('2024-09-02'), 'interval' => 1440, 'usUnits' => 17, 'outTemp' => 12.0]);
        $config = new Config($this->config->settings, [], $this->config->archives + ['other' => $other], []);
        $wx = new Weather($config, clock: $this->clock, budget: new ReadBudget(milliseconds: 5000));
        try {
            $first = $wx->reference('clock');
            $second = $wx->archive('other')->reference('clock');
            $grid = $wx->dataset(['first' => $first->days(7)->series('outTemp', 'day'),
                'second' => $second->days(7)->series('outTemp', 'day')])->aligned();
            self::assertCount(7, $grid);
            self::assertSame(['first' => 25.0, 'second' => 12.0], $grid[1]['values']);
            self::assertSame(['first' => 25.0, 'second' => null], $grid[5]['values']);
            self::assertSame($this->ts('2024-09-06'), $first->days(7)->span()->end - 86400);
            $first->close();
            $second->close();
        } finally {
            $wx->close();
            $db->close();
        }
    }
}
