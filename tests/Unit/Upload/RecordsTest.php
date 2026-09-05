<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Upload\Records;
use WeewxPhp\Weewx\Policy;

final class RecordsTest extends TestCase
{
    private string $dir;
    private DateTimeZone $berlin;
    private ArchiveDb $archive;
    private int $midnight;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('records');
        $this->berlin = new DateTimeZone('Europe/Berlin');
        $this->archive = ArchiveDb::open($this->dir . '/a.sdb', JournalMode::Wal, new Policy(), $this->berlin, create: true);
        $this->midnight = (new DateTimeImmutable('2026-08-26 00:00:00', $this->berlin))->getTimestamp();
        // Every five minutes from 22:00 the evening before to 02:00, a tenth of a millimetre each.
        $records = [];
        for ($ts = $this->midnight - 7200; $ts <= $this->midnight + 7200; $ts += 300) {
            $records[] = ['dateTime' => $ts, 'usUnits' => 17, 'interval' => 5, 'outTemp' => 10.0, 'rain' => 0.1];
        }
        $this->archive->addRecords($records);
    }

    protected function tearDown(): void
    {
        $this->archive->close();
        TempDir::remove($this->dir);
    }

    public function testSumsTheHourTheDayAndTheDayBeforeTheWayWeewxDoes(): void
    {
        $records = new Records($this->archive, $this->berlin);

        // 01:00: twelve records in the hour before it, exclusive on the left.
        $one = $records->augment(['dateTime' => $this->midnight + 3600, 'usUnits' => 17, 'rain' => 0.1]);
        self::assertEqualsWithDelta(1.2, $one['hourRain'], 1e-9);
        // The day so far: midnight itself counts, so thirteen.
        self::assertEqualsWithDelta(1.3, $one['dayRain'], 1e-9);
        // The last day reaches back to 22:00 the evening before: 37 records up to 01:00.
        self::assertEqualsWithDelta(3.7, $one['rain24'], 1e-9);

        // The midnight record begins the day, as Weather Underground has it: its own rain only.
        $midnight = $records->augment(['dateTime' => $this->midnight, 'usUnits' => 17, 'rain' => 0.1]);
        self::assertEqualsWithDelta(0.1, $midnight['dayRain'], 1e-9);
        self::assertEqualsWithDelta(1.2, $midnight['hourRain'], 1e-9);
    }

    public function testATotalAlreadyThereIsKeptAndMixedUnitsGiveNone(): void
    {
        $records = new Records($this->archive, $this->berlin);
        $kept = $records->augment(['dateTime' => $this->midnight + 3600, 'usUnits' => 17, 'hourRain' => 9.9]);
        self::assertSame(9.9, $kept['hourRain']);

        // A record in another system than the archive: the sums are not its to have.
        $foreign = $records->augment(['dateTime' => $this->midnight + 3600, 'usUnits' => 1]);
        self::assertArrayNotHasKey('hourRain', $foreign);
        self::assertArrayNotHasKey('dayRain', $foreign);
    }

    public function testFetchesWhatComesAfterAMomentAndOnlyTheNewestFromNothing(): void
    {
        $records = new Records($this->archive, $this->berlin);

        $fresh = $records->after(0, 12);
        self::assertCount(1, $fresh);
        self::assertSame($this->midnight + 7200, $fresh[0]['dateTime']);
        self::assertArrayHasKey('dayRain', $fresh[0]);

        $some = $records->after($this->midnight + 6000, 12);
        self::assertSame([$this->midnight + 6300, $this->midnight + 6600, $this->midnight + 6900, $this->midnight + 7200], array_column($some, 'dateTime'));
        self::assertCount(2, $records->after($this->midnight + 6000, 2));
        self::assertSame([], $records->after($this->midnight + 7200, 12));
        self::assertArrayNotHasKey('windSpeed', $some[0]);
    }
}
