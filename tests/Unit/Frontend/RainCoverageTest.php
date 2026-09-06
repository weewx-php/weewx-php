<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\ArchiveReader;
use WeewxPhp\Frontend\Computation;
use WeewxPhp\Frontend\Deferred;
use WeewxPhp\Frontend\RainCoverage;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Policy;

final class RainCoverageTest extends TestCase
{
    public function testMonthResetOutsideMeasurementGapsStillCountsFourDryDaysInChartAndSpell(): void
    {
        $dir = TempDir::create('rain-month-boundary');
        $archive = Archives::config(database: $dir . '/archive.sdb');
        $config = new Config(Archives::settings($dir), [], [$archive->id => $archive], []);
        $start = Span::timestamp('2026-09-01', $archive->timezone);
        $clock = new FixedClock($start + 132 * 3600);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $db->addColumn('monthRain', ColumnType::Real);
        $db->addColumn('dayRain', ColumnType::Real);
        $db->transaction(function () use ($db, $start): void {
            // Midnight closes August; the reset itself is followed by a measured interval.
            $db->addRecord(['dateTime' => $start, 'usUnits' => 17, 'interval' => 1440, 'rain' => 3.9, 'monthRain' => 19.1, 'dayRain' => 3.9]);
            for ($hour = 1; $hour <= 132; ++$hour) {
                $db->addRecord(['dateTime' => $start + $hour * 3600, 'usUnits' => 17,
                    'interval' => match ($hour) {
                        5 => 58, 19 => 59, default => 60
                    },
                    'rain' => $hour === 97 ? 2.5 : 0.0, 'monthRain' => $hour >= 97 ? 2.5 : 0.0,
                    'dayRain' => $hour >= 97 && $hour <= 120 ? 2.5 : 0.0]);
            }
        });
        $wx = (new Weather($config, clock: $clock, budget: new ReadBudget(maxStatements: 1024, milliseconds: 10000)))->reference('archive');
        $runtime = Runtime::of($config, $clock, new MemoryLogger());
        try {
            $series = $wx->days(7)->series('rain', 'day')->series();
            self::assertSame([3.9, 0.0, 0.0, 0.0, 0.0, 2.5, 0.0], array_column($series->points, 'value'));
            self::assertSame(1.0, $series->points[1]['coverage'], 'Counter readings cover the three missing minutes on September 1');
            self::assertSame(0.5, $series->points[6]['coverage'], 'Today remains a partial day');
            $query = $wx->alltime()->series('rain', 'day')->longestSpell()->nightly();
            $query->register();
            $result = (new Worker($runtime))->run(Budget::of($clock, 10, 1000), drain: true);
            self::assertSame(0, $result['failed']);
            self::assertSame(0, $result['pending']);
            self::assertSame(4, $query->report()->value('intervals')->raw);
            self::assertSame($start, $query->report()->value('start')->raw);
            self::assertSame($start + 4 * 86400, $query->report()->value('end')->raw);
        } finally {
            $wx->close();
            $runtime->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testUnprovenMissingRainDoesNotBecomeAZeroInTheDailyChart(): void
    {
        $dir = TempDir::create('rain-chart-gap');
        $archive = Archives::config(database: $dir . '/archive.sdb');
        $start = Span::timestamp('2026-09-01', $archive->timezone);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        foreach ([1, 24] as $hour) {
            $db->addRecord(['dateTime' => $start + $hour * 3600, 'usUnits' => 17, 'interval' => 60, 'rain' => 0.0]);
        }
        $wx = new Weather(
            new Config(Archives::settings($dir), [], [$archive->id => $archive], []),
            clock: new FixedClock($start + 86401),
            budget: new ReadBudget(milliseconds: 10000),
        );
        try {
            $series = $wx->between($start, $start + 86400)->series('rain', 'day')->series();
            self::assertNull($series->points[0]['value']);
            self::assertLessThan(1.0, $series->points[0]['coverage']);
        } finally {
            $wx->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testCounterProofCannotOverrideRainInAnImportedLoggerInterval(): void
    {
        $dir = TempDir::create('rain-counter-hardware');
        $archive = Archives::config(database: $dir . '/archive.sdb');
        $start = Span::timestamp('2026-09-02', $archive->timezone);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $db->addColumn('monthRain', ColumnType::Real);
        foreach ([0, 24] as $hour) {
            $db->addRecord(['dateTime' => $start + $hour * 3600, 'usUnits' => 17, 'interval' => 1, 'rain' => 0.0, 'monthRain' => 0.0]);
        }
        $db->preserveHardware('logger', ['dateTime' => $start + 86460, 'usUnits' => 17, 'interval' => 5, 'rain' => 2.0]);
        $reader = new ArchiveReader($archive, new ReadBudget(milliseconds: 10000));
        try {
            self::assertFalse(RainCoverage::dry($reader, new Span($start, $start + 86400), new ReadBudget(milliseconds: 10000)));
        } finally {
            $reader->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testGapProofResumesAcrossSmallWorkerBudgets(): void
    {
        $dir = TempDir::create('rain-resume');
        $archive = Archives::config(database: $dir . '/archive.sdb');
        $start = Span::timestamp('2026-09-01', $archive->timezone);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $db->addColumn('monthRain', ColumnType::Real);
        $db->transaction(function () use ($db, $start): void {
            $db->addRecord(['dateTime' => $start, 'usUnits' => 17, 'interval' => 1, 'rain' => 0.0, 'monthRain' => 10.0]);
            for ($hour = 1; $hour <= 24; ++$hour) {
                $db->addRecord(['dateTime' => $start + $hour * 3600, 'usUnits' => 17,
                    'interval' => $hour > 1 ? 59 : 60, 'rain' => 0.0, 'monthRain' => 0.0]);
            }
        });
        $reader = new ArchiveReader($archive, new ReadBudget(milliseconds: 10000));
        $wx = new Weather(new Config(Archives::settings($dir), [], [$archive->id => $archive], []));
        try {
            $spec = $wx->between($start, $start + 86400)->series('rain', 'day')->definition()['spec'];
            $work = new Computation($spec, $reader, $start + 86400);
            $passes = 0;
            do {
                self::assertLessThan(40, ++$passes, 'A finished gap must not restart on every worker slice');
                try {
                    $finished = $work->step(new ReadBudget(maxStatements: 12, milliseconds: 10000));
                } catch (Deferred) {
                    $finished = false;
                }
                $work = Computation::restore($work->save(), $spec, $reader);
            } while (!$finished);
            self::assertGreaterThan(1, $passes);
            $result = $work->result($start + 86400);
            self::assertInstanceOf(\WeewxPhp\Frontend\Series::class, $result);
            self::assertSame(0.0, $result->points[0]['value']);
            self::assertSame(1.0, $result->points[0]['coverage']);
        } finally {
            $reader->close();
            $wx->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testGapEvidenceWorksWithoutCounterColumnsAndVetoesUncertainDays(): void
    {
        $dir = TempDir::create('rain-evidence');
        $config = Archives::config(database: $dir . '/archive.sdb');
        $db = ArchiveDb::open($config->database, JournalMode::Wal, new Policy(), $config->timezone, create: true);
        $start = Span::timestamp('2026-09-02', $config->timezone);
        foreach ([60, 86400] as $offset) {
            $db->addRecord(['dateTime' => $start + $offset, 'usUnits' => 17, 'interval' => 1, 'rain' => 0]);
        }
        $db->close();
        $writer = \WeewxPhp\Db\Sqlite::open($config->database, false, JournalMode::Wal);
        $writer->exec('CREATE TABLE weewx_rain_evidence(start INTEGER, stop INTEGER, amount REAL, status TEXT)');
        $writer->exec("INSERT INTO weewx_rain_evidence VALUES (?, ?, 0, 'complete')", [$start + 60, $start + 86400]);
        $reader = new ArchiveReader($config, new ReadBudget(milliseconds: 10000));
        try {
            $span = new Span($start, $start + 86400);
            self::assertTrue(RainCoverage::dry($reader, $span, new ReadBudget(milliseconds: 10000)));
            self::assertFalse(RainCoverage::uncertain($reader, $span, new ReadBudget(milliseconds: 10000)));
            $writer->exec('UPDATE weewx_rain_evidence SET amount = 3, stop = ?', [$start + 2 * 86400]);
            self::assertTrue(RainCoverage::uncertain($reader, $span, new ReadBudget(milliseconds: 10000)));
            self::assertFalse(RainCoverage::dry($reader, $span, new ReadBudget(milliseconds: 10000)));
            $writer->exec('INSERT INTO archive(dateTime, usUnits, interval, rain) VALUES (?, 17, 1, 3)', [$start + 2 * 86400]);
            $wx = new \WeewxPhp\Frontend\Weather(
                new \WeewxPhp\Config\Config(Archives::settings($dir), [], [$config->id => $config], []),
                clock: new \WeewxPhp\Time\FixedClock($start + 2 * 86400 + 1),
                budget: new ReadBudget(milliseconds: 10000),
            );
            try {
                self::assertNull($wx->between($start, $start + 86400)->sum('rain')->raw());
                self::assertSame(3.0, $wx->between($start, $start + 2 * 86400)->sum('rain')->raw());
            } finally {
                $wx->close();
            }
            $writer->exec("UPDATE weewx_rain_evidence SET amount = NULL, status = 'reset'");
            self::assertTrue(RainCoverage::uncertain($reader, $span, new ReadBudget(milliseconds: 10000)));
        } finally {
            $reader->close();
            $writer->close();
            TempDir::remove($dir);
        }
    }

    public function testUnchangedMonthCounterProvesDryDaysAcrossLongGapsButRainAndResetsDoNot(): void
    {
        $dir = TempDir::create('rain-coverage');
        $config = Archives::config(database: $dir . '/archive.sdb');
        $db = ArchiveDb::open($config->database, JournalMode::Wal, new Policy(), $config->timezone, create: true);
        $db->addColumn('monthRain', ColumnType::Real);
        $start = Span::timestamp('2026-08-25', $config->timezone);
        foreach ([0, 12, 36, 60, 72] as $hour) {
            $db->addRecord(['dateTime' => $start + $hour * 3600, 'usUnits' => 17, 'interval' => 5, 'rain' => null, 'monthRain' => 10.0]);
        }
        $reader = new ArchiveReader($config, new ReadBudget(milliseconds: 10000));
        try {
            foreach ([0, 1, 2] as $day) {
                self::assertTrue(RainCoverage::dry($reader, new Span($start + $day * 86400, $start + ($day + 1) * 86400), new ReadBudget(milliseconds: 10000)));
            }
            $db->addRecord(['dateTime' => $start + 60 * 3600, 'usUnits' => 17, 'interval' => 5, 'monthRain' => 11.0], replace: true);
            $db->addRecord(['dateTime' => $start + 72 * 3600, 'usUnits' => 17, 'interval' => 5, 'monthRain' => 11.0], replace: true);
            self::assertFalse(RainCoverage::dry($reader, new Span($start + 86400, $start + 2 * 86400), new ReadBudget(milliseconds: 10000)));
            // Equal values separated by a monthly reset do not prove a dry interval.
            $september = Span::timestamp('2026-09-01', $config->timezone);
            foreach ([-3600, 86400] as $offset) {
                $db->addRecord(['dateTime' => $september + $offset, 'usUnits' => 17, 'interval' => 5, 'monthRain' => 10.0]);
            }
            self::assertFalse(RainCoverage::dry($reader, new Span($september, $september + 86400), new ReadBudget(milliseconds: 10000)));
        } finally {
            $reader->close();
            $db->close();
            TempDir::remove($dir);
        }
    }
}
