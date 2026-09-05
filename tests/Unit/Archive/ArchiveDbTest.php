<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\IntervalError;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\DbError;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\SchemaError;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\VecStats;

final class ArchiveDbTest extends TestCase
{
    private string $dir;
    private DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('archive');
        $this->berlin = new DateTimeZone('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testCreatesTheTablesWeewxCreatesWithTheSameWords(): void
    {
        $archive = $this->open('new.sdb', create: true);

        self::assertTrue($archive->created());
        $schema = $archive->schema();
        self::assertCount(115, $schema->columns);
        self::assertSame('dateTime', $schema->columns[0]);
        self::assertSame('windSpeed', $schema->columns[114]);
        self::assertCount(113, $schema->dayTypes);
        self::assertSame('4.0', $schema->version());
        self::assertNull($archive->getMeta('lastUpdate'));
        self::assertNull($archive->unitSystem());

        $raw = Sqlite::open($this->dir . '/new.sdb', false, JournalMode::Wal);
        $ddl = [];
        foreach ($raw->query("SELECT name, sql FROM sqlite_master WHERE type = 'table'") as $row) {
            $ddl[Sqlite::text($row['name'])] = Sqlite::text($row['sql']);
        }
        self::assertStringStartsWith(
            'CREATE TABLE archive (dateTime INTEGER NOT NULL PRIMARY KEY, usUnits INTEGER NOT NULL, interval INTEGER NOT NULL, altimeter REAL, appTemp REAL,',
            $ddl['archive'],
        );
        self::assertStringEndsWith('windrun REAL, windSpeed REAL)', $ddl['archive']);
        self::assertSame(
            'CREATE TABLE archive_day_outTemp (dateTime INTEGER NOT NULL PRIMARY KEY, min REAL, mintime INTEGER, max REAL, maxtime INTEGER, sum REAL, count INTEGER, wsum REAL, sumtime INTEGER)',
            $ddl['archive_day_outTemp'],
        );
        self::assertSame(
            'CREATE TABLE archive_day_wind (dateTime INTEGER NOT NULL PRIMARY KEY, min REAL, mintime INTEGER, max REAL, maxtime INTEGER, sum REAL, count INTEGER, wsum REAL, sumtime INTEGER, max_dir REAL, xsum REAL, ysum REAL, dirsumtime INTEGER, squaresum REAL, wsquaresum REAL)',
            $ddl['archive_day_wind'],
        );
        self::assertSame(
            'CREATE TABLE archive_day__metadata (name CHAR(20) NOT NULL PRIMARY KEY, value TEXT)',
            $ddl['archive_day__metadata'],
        );
    }

    public function testRefusesToInventAnArchiveUnlessAsked(): void
    {
        $this->expectException(DbError::class);
        $this->open('missing.sdb', create: false);
    }

    public function testWritesARecordAndFoldsItIntoItsDay(): void
    {
        $archive = $this->open('day.sdb', create: true);
        $noon = $this->local('2026-05-14 12:00:00');
        $record = ['dateTime' => $noon, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 68.0, 'windSpeed' => 4.0,
            'windDir' => 90.0, 'windGust' => 7.0, 'rain' => 0.01, 'vpd' => 0.5];

        self::assertTrue($archive->addRecord($record));
        self::assertFalse($archive->addRecord($record));

        self::assertSame(1, $archive->count());
        self::assertSame(UnitSystem::US, $archive->unitSystem());
        self::assertSame($noon, $archive->lastTimestamp());
        self::assertSame(['vpd' => 2], $archive->homeless());
        self::assertSame((string) $noon, $archive->getMeta('lastUpdate'));
        self::assertSame(['dateTime' => $noon, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 68.0, 'rain' => 0.01,
            'windDir' => 90.0, 'windGust' => 7.0, 'windSpeed' => 4.0], $archive->record($noon));

        $sod = $this->local('2026-05-14 00:00:00');
        $day = $archive->loadDay($sod, UnitSystem::US);
        $outTemp = $day->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame([68.0, $noon, 68.0, $noon, 68.0, 1, 68.0 * 300.0, 300], $outTemp->statsTuple());
        $wind = $day->get('wind');
        self::assertInstanceOf(VecStats::class, $wind);
        self::assertSame(7.0, $wind->max);
        self::assertSame(4.0, $wind->min);
        self::assertSame(300, $wind->dirsumtime);
        // A type nothing measured still has its row, empty.
        $inTemp = $day->get('inTemp');
        self::assertInstanceOf(ScalarStats::class, $inTemp);
        self::assertSame(0, $inTemp->count);
        self::assertSame([68.0, 68.0], [$outTemp->min, $outTemp->max]);
        self::assertSame(['outTemp' => [1, $noon], 'rain' => [1, $noon], 'windDir' => [1, $noon],
            'windGust' => [1, $noon], 'windSpeed' => [1, $noon]], $archive->occupied());
    }

    public function testMidnightBelongsToThePreviousDayInTheArchivesZone(): void
    {
        $archive = $this->open('midnight.sdb', create: true);
        $midnight = $this->local('2026-05-15 00:00:00');
        $archive->addRecord(['dateTime' => $midnight, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 50.0]);

        $previous = $archive->loadDay($this->local('2026-05-14 00:00:00'), UnitSystem::US)->get('outTemp');
        $next = $archive->loadDay($midnight, UnitSystem::US)->get('outTemp');

        self::assertInstanceOf(ScalarStats::class, $previous);
        self::assertInstanceOf(ScalarStats::class, $next);
        self::assertSame(1, $previous->count);
        self::assertSame(0, $next->count);
        self::assertSame([$this->local('2026-05-14 00:00:00')], iterator_to_array($archive->days(), false));
    }

    public function testReplacingARecordTakesItsSumsBackOutFirst(): void
    {
        $archive = $this->open('replace.sdb', create: true);
        $noon = $this->local('2026-05-14 12:00:00');
        $archive->addRecord(['dateTime' => $noon, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 60.0]);
        $archive->addRecord(['dateTime' => $noon + 300, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 70.0]);

        self::assertTrue($archive->addRecord(['dateTime' => $noon, 'usUnits' => 1, 'interval' => 5, 'outTemp' => 62.0], replace: true));

        $outTemp = $archive->loadDay($this->local('2026-05-14 00:00:00'), UnitSystem::US)->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame(2, $outTemp->count);
        self::assertSame(132.0, $outTemp->sum);
        self::assertSame(132.0 * 300.0, $outTemp->wsum);
        self::assertSame(600, $outTemp->sumtime);
        self::assertSame(62.0, $archive->record($noon)['outTemp'] ?? null);
    }

    public function testRebuildingADayReproducesTheIncrementalSums(): void
    {
        $archive = $this->open('rebuild.sdb', create: true);
        $start = $this->local('2026-05-14 00:05:00');
        $records = [];
        for ($step = 0; $step < 288; $step++) {
            $records[] = ['dateTime' => $start + $step * 300, 'usUnits' => 1, 'interval' => 5,
                'outTemp' => 55.0 + 12.0 * sin($step / 24.0), 'windSpeed' => 4.0 + abs(sin($step / 24.0)) * 6.0,
                'windDir' => ($step * 7) % 360, 'windGust' => 6.0 + abs(sin($step / 24.0)) * 9.0,
                'rain' => $step % 40 === 0 ? 0.01 : 0.0];
        }
        self::assertSame(288, $archive->addRecords($records));
        $sod = $this->local('2026-05-14 00:00:00');
        $before = [];
        foreach (['outTemp', 'wind', 'windSpeed', 'rain', 'inTemp'] as $obsType) {
            $before[$obsType] = $archive->loadDay($sod, UnitSystem::US)->get($obsType)->statsTuple();
        }
        self::assertSame((string) ($start + 287 * 300), $archive->getMeta('lastUpdate'));

        self::assertSame(288, $archive->rebuildDay($sod));

        foreach ($before as $obsType => $tuple) {
            self::assertSame($tuple, $archive->loadDay($sod, UnitSystem::US)->get($obsType)->statsTuple(), $obsType);
        }
    }

    public function testAddsAColumnWithItsDailyTableAndRefusesBadNames(): void
    {
        $archive = $this->open('column.sdb', create: true);

        self::assertTrue($archive->addColumn('extraTemp9', ColumnType::Real));
        self::assertFalse($archive->addColumn('extraTemp9', ColumnType::Real));
        self::assertTrue($archive->addColumn('lightning_num', ColumnType::Integer));

        $schema = $archive->schema();
        self::assertSame('REAL', $schema->columnTypes['extraTemp9']);
        self::assertSame('INTEGER', $schema->columnTypes['lightning_num']);
        self::assertArrayHasKey('extraTemp9', $schema->dayTypes);
        self::assertArrayHasKey('lightning_num', $schema->dayTypes);

        $archive->addRecord(['dateTime' => $this->local('2026-05-14 12:00:00'), 'usUnits' => 1, 'interval' => 5, 'lightning_num' => 3]);
        self::assertSame([], $archive->homeless());

        $this->expectException(DbError::class);
        $archive->addColumn('drop table', ColumnType::Real);
    }

    public function testRefusesSummariesAtAnOlderVersion(): void
    {
        $archive = $this->open('old.sdb', create: true);
        $archive->setMeta('Version', '2.0');
        $archive->close();

        $this->expectException(SchemaError::class);
        $this->expectExceptionMessage('rebuild-daily');
        $this->open('old.sdb', create: false);
    }

    public function testARecordWithoutAnIntervalStandsButWeighsNothing(): void
    {
        $archive = $this->open('interval.sdb', create: true);
        $noon = $this->local('2026-05-14 12:00:00');

        self::assertTrue($archive->addRecord(['dateTime' => $noon, 'usUnits' => 1, 'interval' => 0, 'outTemp' => 60.0]));

        self::assertSame(1, $archive->count());
        $outTemp = $archive->loadDay($this->local('2026-05-14 00:00:00'), UnitSystem::US)->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame(0, $outTemp->count);

        $this->expectException(IntervalError::class);
        ArchiveDb::weightOf(['dateTime' => $noon]);
    }

    private function open(string $file, bool $create): ArchiveDb
    {
        return ArchiveDb::open($this->dir . '/' . $file, JournalMode::Wal, new Policy(), $this->berlin, $create);
    }

    private function local(string $text): int
    {
        return (new DateTimeImmutable($text, $this->berlin))->getTimestamp();
    }
}
