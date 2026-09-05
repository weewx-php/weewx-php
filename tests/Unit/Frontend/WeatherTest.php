<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\ArchiveChanges;
use WeewxPhp\Frontend\ArchiveReader;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Refresh;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;

final class WeatherTest extends TestCase
{
    private string $dir;
    private Config $config;
    private ArchiveDb $db;
    private FixedClock $clock;
    private Weather $wx;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('frontend');
        $archive = Archives::config(database: $this->dir . '/weather.sdb');
        $this->config = new Config(Archives::settings($this->dir), [], [$archive->id => $archive], []);
        $this->db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        foreach (['2023-01-01' => 1.0, '2024-01-01' => 2.0, '2024-01-02' => 0.0] as $day => $rain) {
            $start = $this->ts($day);
            foreach ([1, 2, 3, 4] as $i) {
                $this->db->addRecord(['dateTime' => $start + $i * 21600, 'usUnits' => 17, 'interval' => 360,
                    'rain' => $rain, 'outTemp' => $i * 10.0, 'windSpeed' => 2.0, 'windDir' => 270.0, 'windGust' => 4.0, 'windGustDir' => 260.0]);
            }
        }
        $this->clock = new FixedClock($this->ts('2024-01-03') + 60);
        $this->wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(milliseconds: 2000));
    }

    protected function tearDown(): void
    {
        $this->wx->close();
        $this->db->close();
        TempDir::remove($this->dir);
    }

    private function ts(string $date): int
    {
        return Span::timestamp($date, new DateTimeZone('Europe/Berlin'));
    }

    public function testScalarQueriesUnitsAndDailySummaries(): void
    {
        self::assertSame(40.0, $this->wx->current('outTemp')->raw());
        self::assertSame(8.0, $this->wx->on('2024-01-01')->sum('rain')->raw());
        self::assertSame(25.0, $this->wx->on('2024-01-01')->avg('outTemp')->raw());
        self::assertSame(77.0, $this->wx->on('2024-01-01')->avg('outTemp')->value()->to('degree_F')->raw);
        self::assertSame(4, $this->wx->on('2024-01-01')->count('outTemp')->raw());
        self::assertSame(40.0, $this->wx->alltime()->max('outTemp')->raw());
        self::assertSame(270.0, $this->wx->on('2024-01-01')->aggregate('wind', 'vecdir')->raw());
    }

    public function testAllXaggsHistoricalStatisticsAndThresholdUnits(): void
    {
        $day = $this->wx->on('2024-01-01');
        foreach (['historical_min' => 10.0, 'historical_max' => 40.0, 'historical_min_avg' => 10.0, 'historical_max_avg' => 40.0, 'historical_avg' => 25.0] as $aggregate => $expected) {
            self::assertSame($expected, $day->aggregate('outTemp', $aggregate)->raw(), $aggregate);
        }
        self::assertSame($this->ts('2023-01-01') + 21600, $day->aggregate('outTemp', 'historical_mintime')->raw());
        self::assertSame(2, $this->wx->on('2024-01')->aggregate('outTemp', 'avg_ge', 77, 'degree_F')->raw());
        self::assertSame(0, $this->wx->on('2024-01')->aggregate('outTemp', 'avg_gt', 77, 'degree_F')->raw());
        self::assertSame(2, $this->wx->on('2024-01')->aggregate('outTemp', 'avg_lt', 26)->raw());
    }

    public function testSeriesContainBoundariesGapsAndCoverage(): void
    {
        $series = $this->wx->between('2024-01-01', '2024-01-04')->series('rain', 'day', 'sum')->get();
        self::assertInstanceOf(Series::class, $series);
        self::assertSame([8.0, 0.0, null], array_column($series->points, 'value'));
        self::assertSame([1.0, 1.0, 0.0], array_column($series->points, 'coverage'));
        self::assertSame($this->ts('2024-01-01') * 1000, $series->pairs('start', true)[0][0]);
        $completed = $this->wx->between('2024-01-01', '2024-01-04')->series('rain', 'day', 'sum')->completed()->get();
        self::assertInstanceOf(Series::class, $completed);
        self::assertCount(2, $completed->points);
    }

    public function testCumulativeSeriesAndRawRecords(): void
    {
        $series = $this->wx->between('2024-01-01', '2024-01-04')->series('rain', 'day', 'cumulative')->get();
        self::assertInstanceOf(Series::class, $series);
        self::assertSame([8.0, 8.0, 8.0], array_column($series->points, 'value'));
        $raw = $this->wx->on('2024-01-01')->records('outTemp')->get();
        self::assertInstanceOf(Series::class, $raw);
        self::assertSame([10.0, 20.0, 30.0, 40.0], array_column($raw->points, 'value'));
    }

    public function testColdQueryDefersAndResumesInWorker(): void
    {
        $limited = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(maxRows: 1, milliseconds: 2000));
        try {
            self::assertSame('pending', $limited->alltime()->sum('rain')->value()->status);
        } finally {
            $limited->close();
        }
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger());
        $answer = (new Worker($runtime))->run(Budget::of($this->clock, 10, 100));
        self::assertSame(1, $answer['completed']);
        self::assertSame(12.0, $this->wx->alltime()->sum('rain')->raw());
        $runtime->close();
    }

    public function testNoSqlExpressionsAreAccepted(): void
    {
        $this->expectException(QueryError::class);
        $this->wx->day()->sum('rain); DROP TABLE archive; --')->get();
    }

    public function testSeriesPointLimitAppliesBeforeReadingArchiveData(): void
    {
        $this->expectException(QueryError::class);
        $this->wx->between('2000-01-01', '2024-01-01')->series('outTemp', 'hour')->get();
    }

    public function testMissingIsDistinctFromZeroAndUnknown(): void
    {
        self::assertNull($this->wx->on('2024-01-03')->sum('rain')->raw());
        self::assertSame(0.0, $this->wx->on('2024-01-02')->sum('rain')->raw());
        self::assertFalse($this->wx->day()->aggregate('unknownSensor', 'exists')->raw());
        self::assertSame('—', $this->wx->on('2024-01-03')->sum('rain')->format());
    }

    public function testNightlyResultKeepsScheduleAfterSourceChange(): void
    {
        $query = $this->wx->alltime()->sum('rain')->nightly();
        self::assertSame(12.0, $query->raw());
        $cache = new Cache($this->config->settings);
        $archive = $this->config->archives['kirchdorf'];
        $changes = new ArchiveChanges($cache, $archive, new MemoryLogger());
        $start = $this->ts('2024-01-03');
        $changes->before($start, $start + 300);
        $this->db->addRecord(['dateTime' => $start + 300, 'usUnits' => 17, 'interval' => 5, 'rain' => 5.0]);
        $changes->after($start, $start + 300);
        $id = Cache::key('kirchdorf', $query->spec());
        $job = $cache->request($id);
        self::assertNotNull($job);
        self::assertSame($start + 3 * 3600, $job['due']);
        self::assertSame(1, $job['dirty']);
        $cache->close();
        $this->wx->close();
        $later = new FixedClock($start + 3600);
        $this->wx = new Weather($this->config, clock: $later, budget: new ReadBudget(milliseconds: 2000));
        self::assertSame(12.0, $this->wx->alltime()->sum('rain')->nightly()->raw());
        $runtime = Runtime::of($this->config, new FixedClock($start + 3 * 3600), new MemoryLogger());
        self::assertSame(1, (new Worker($runtime))->run(Budget::of($runtime->clock, 10, 100))['completed']);
        $runtime->close();
    }

    public function testForeignCorrectionInvalidatesClosedResults(): void
    {
        self::assertSame(8.0, $this->wx->on('2024-01-01')->sum('rain')->raw());
        $before = ArchiveReader::fingerprint($this->config->archives['kirchdorf']->database);
        $this->wx->close();
        $this->db->addRecord(['dateTime' => $this->ts('2024-01-01') + 21600, 'usUnits' => 17, 'interval' => 360, 'rain' => 8.0], replace: true);
        self::assertNotSame($before, ArchiveReader::fingerprint($this->config->archives['kirchdorf']->database));
        $this->wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(milliseconds: 2000));
        self::assertSame('stale', $this->wx->on('2024-01-01')->sum('rain')->value()->status);
    }

    public function testCalendarDaysRespectDstAndArchiveMidnight(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        self::assertSame(23 * 3600, Span::calendar('day', $this->ts('2024-04-01'), $zone)->length());
        self::assertSame(25 * 3600, Span::calendar('day', $this->ts('2024-10-28'), $zone)->length());
        self::assertCount(25, Span::calendar('day', $this->ts('2024-10-28'), $zone)->buckets('hour', $zone));
        self::assertSame($this->ts('2023-12-01'), Span::calendar('season', $this->ts('2024-01-03'), $zone)->start);
    }

    public function testOutputEscapesAndRejectsDangerousFormats(): void
    {
        self::assertSame('&lt;script&gt;', (new Value(null))->html(missing: '<script>'));
        $this->expectException(QueryError::class);
        (new Value(1.0))->format('%1000000000f');
    }

    public function testRefreshCanBeMonthlyOrOnce(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        self::assertSame($this->ts('2024-02-01'), (new Refresh('monthly'))->next($this->ts('2024-01-05'), $zone));
        self::assertSame(PHP_INT_MAX, (new Refresh('once'))->next($this->ts('2024-01-05'), $zone));
    }

    public function testAlmanacUsesWallClockAndReusesExplicitDateWithoutArchiveReads(): void
    {
        $at = $this->ts('2026-06-21 12:00:00');
        $sky = $this->wx->almanac($at);
        self::assertTrue($sky->hasExtras);
        $altitude = $sky->sun()->altitude()->raw();
        self::assertIsFloat($altitude);
        self::assertGreaterThan(50, $altitude);
        $moon = $sky->tag('nextFullMoon')->raw();
        self::assertIsInt($moon);
        self::assertGreaterThan($at, $moon);
        $this->wx->close();
        $this->wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(maxRows: 0, maxStatements: 0));
        self::assertSame($altitude, $this->wx->almanac($at)->sun()->altitude()->raw());
    }

    public function testAstronomySeriesAndObserverAreSerializedForWorker(): void
    {
        $this->wx->close();
        $this->wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(maxRows: 0));
        $query = $this->wx->almanac()->observer(horizon: -6, pressure: 0)->sun()->center()->series('altitude', '2026-06-21', '2026-06-22', '1h');
        self::assertSame('pending', $query->get()->status);
        $spec = \WeewxPhp\Frontend\Spec::fromJson($query->spec()->json());
        self::assertSame(-6.0, $spec->horizon);
        self::assertTrue($spec->useCenter);
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger());
        self::assertSame(1, (new Worker($runtime))->run(Budget::of($this->clock, 10, 100))['completed']);
        $runtime->close();
        $this->wx->close();
        $this->wx = new Weather($this->config, clock: $this->clock);
        $result = $this->wx->almanac()->observer(horizon: -6, pressure: 0)->sun()->center()->series('altitude', '2026-06-21', '2026-06-22', '1h')->get();
        self::assertInstanceOf(Series::class, $result);
        self::assertCount(24, $result->points);
        self::assertSame('ready', $result->status);
    }

    public function testPolarDayAndNightHaveRealDurationsAndNoInventedSunrise(): void
    {
        $day = $this->wx->almanac($this->ts('2026-06-21 12:00'))->observer(latitude: 69.65, longitude: 18.96);
        self::assertNull($day->sun()->rise()->raw());
        self::assertSame(86400, $day->sun()->visible()->raw());
        $night = $this->wx->almanac($this->ts('2026-12-21 12:00'))->observer(latitude: 69.65, longitude: 18.96);
        self::assertSame(0, $night->sun()->visible()->raw());
    }

    public function testAstronomicalTimesConvertBeforeFormattingAndJsonIsSafeForScripts(): void
    {
        $time = $this->wx->time($this->ts('2024-01-01 12:00'));
        self::assertSame('12:00', $time->to('dublin_jd')->format('H:i'));
        self::assertStringNotContainsString('</script>', \WeewxPhp\Frontend\ResultCodec::encode(new Value('</script>')));
    }

    public function testWorkerResumesInsideAReadPageWithoutDoubleCounting(): void
    {
        $budget = new ReadBudget(maxRows: 3, milliseconds: 2000);
        $reader = new ArchiveReader($this->config->archives['kirchdorf'], new ReadBudget(milliseconds: 2000));
        $spec = new \WeewxPhp\Frontend\Spec(period: 'between', start: $this->ts('2024-01-01') + 1, end: $this->ts('2024-01-03') - 1, observation: 'rain', aggregate: 'sum');
        $work = new \WeewxPhp\Frontend\Computation($spec, $reader, $this->clock->now());
        try {
            $work->step($budget);
            self::fail('Expected a bounded partial read');
        } catch (\WeewxPhp\Frontend\Deferred) {
        }
        $saved = $work->save();
        $restored = \WeewxPhp\Frontend\Computation::restore($saved, $spec, $reader);
        while (!$restored->step(new ReadBudget(milliseconds: 2000))) {
        }
        $result = $restored->result($this->clock->now());
        self::assertInstanceOf(Value::class, $result);
        self::assertSame(8.0, $result->raw);
        $reader->close();
    }

    public function testClosedSeriesBucketsAreSharedAcrossQueryCadences(): void
    {
        $a = $this->wx->between('2024-01-01', '2024-01-03')->series('rain', 'day', 'sum')->get();
        self::assertInstanceOf(Series::class, $a);
        $this->wx->close();
        // Schema reads fit, but a second source scan would exceed this budget.
        $this->wx = new Weather($this->config, clock: $this->clock, budget: new ReadBudget(maxRows: 1000, maxStatements: 5, milliseconds: 2000));
        $b = $this->wx->between('2024-01-01', '2024-01-03')->series('rain', 'day', 'sum')->nightly()->get();
        self::assertInstanceOf(Series::class, $b);
        self::assertSame($a->points, $b->points);
    }

    public function testRainfallRankAndComparisonExcludeGapsAndRetainZero(): void
    {
        $rows = [];
        foreach (['2021-09-01' => 10.0, '2022-09-01' => 0.0, '2023-09-01' => null, '2024-09-01' => 30.0] as $date => $rain) {
            $start = $this->ts($date);
            $rows[] = ['start' => $start, 'end' => Span::date($start, new DateTimeZone('Europe/Berlin'))->modify('+1 month')->getTimestamp(), 'value' => $rain, 'coverage' => $rain === null ? 0.0 : 1.0];
        }
        $series = (new Series($rows, 'mm', 'group_rain'))->calendarMonth(9, 'Europe/Berlin');
        self::assertSame(0.0, $series->rank(1, ascending: true)->points[0]['value']);
        self::assertSame(30.0, $series->rank(1)->points[0]['value']);
        self::assertSame(10.0, $series->quantile(0.5)->raw);
        $comparison = $series->compare(new Value(10.0, 'mm', 'group_rain'));
        self::assertSame(3, $comparison['count']);
        self::assertSame(50.0, $comparison['percentile']->raw);
        self::assertSame(75.0, $comparison['percentOfMean']->raw);
    }

    public function testCompletedMonthlySeriesExcludesThePartialCurrentMonth(): void
    {
        $series = $this->wx->alltime()->series('rain', 'month', 'sum')->completed(0)->series();
        foreach ($series->points as $point) {
            self::assertLessThanOrEqual($this->ts('2024-01-01'), $point['end']);
        }
        self::assertCount(12, $series->points);
        self::assertCount(1, $series->calendarMonth(1, 'Europe/Berlin')->points);
    }

    public function testAppendPreservesAnOlderSnapshotButRejectsPublicationFromOldRevision(): void
    {
        $archive = $this->config->archives['kirchdorf'];
        $reader = new ArchiveReader($archive, new ReadBudget(milliseconds: 2000));
        $spec = new \WeewxPhp\Frontend\Spec(period: 'alltime', observation: 'rain', aggregate: 'sum');
        $work = new \WeewxPhp\Frontend\Computation($spec, $reader, $this->ts('2024-01-03'));
        $cache = new Cache($this->config->settings);
        $revision = $cache->observe($archive);
        $id = $cache->register($archive->id, $spec, $this->clock->now());
        $cache->progress($id, $work, $this->clock->now(), $revision);
        $cache->invalidate($archive->id, new Span($this->ts('2024-01-04'), $this->ts('2024-01-05')));
        self::assertIsString($cache->request($id)['work'] ?? null);
        while (!$work->step(new ReadBudget(milliseconds: 2000))) {
        }
        self::assertFalse($cache->publish($id, $work, $this->clock->now(), PHP_INT_MAX, $revision));
        self::assertNull($cache->request($id)['payload'] ?? null);
        $cache->invalidate($archive->id, new Span($this->ts('2024-01-01'), $this->ts('2024-01-02')));
        self::assertNull($cache->request($id)['work'] ?? null);
        $reader->close();
        $cache->close();
    }
}
