<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\State;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\State\StateDb;
use WeewxPhp\State\StationStatus;
use WeewxPhp\Tests\Support\TempDir;

final class StateDbTest extends TestCase
{
    private string $dir;
    private StateDb $state;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('state');
        $this->state = StateDb::open($this->dir . '/state.sdb', JournalMode::Wal);
    }

    protected function tearDown(): void
    {
        $this->state->close();
        TempDir::remove($this->dir);
    }

    public function testAnUnknownArchiveIsNotOneWeMade(): void
    {
        $archive = $this->state->archive('kirchdorf');

        self::assertFalse($archive->createdByApp);
        self::assertNull($archive->dbCreatedAt);
        self::assertSame(0, $archive->recordsTotal);
    }

    public function testRemembersCreationRunsAndErrors(): void
    {
        $this->state->markCreated('kirchdorf', 1000);
        $this->state->noteRun('kirchdorf', 2000, 3, 1900);
        $this->state->noteRun('kirchdorf', 2300, 0, null);
        $this->state->noteError('kirchdorf', 2600, 'no such column');
        $this->state->noteCaughtUp('kirchdorf', 2700);

        $archive = $this->state->archive('kirchdorf');
        self::assertTrue($archive->createdByApp);
        self::assertSame(1000, $archive->dbCreatedAt);
        self::assertSame(2300, $archive->lastRunAt);
        self::assertSame(1900, $archive->lastRecordAt);
        self::assertSame(3, $archive->recordsTotal);
        self::assertSame('no such column', $archive->lastError);
        self::assertSame(2600, $archive->lastErrorAt);
        self::assertSame(2700, $archive->caughtUpAt);

        $this->state->noteRun('kirchdorf', 2900, 1, 2800);
        self::assertNull($this->state->archive('kirchdorf')->lastError);
    }

    public function testAStationKeepsTheMomentItsStatusChanged(): void
    {
        $unknown = $this->state->station('ecowitt');
        self::assertSame(StationStatus::Unknown, $unknown->status);

        $this->state->setStation('ecowitt', 100, StationStatus::Ok, 110);
        $this->state->setStation('ecowitt', 100, StationStatus::Ok, 120);
        $still = $this->state->station('ecowitt');
        self::assertSame(110, $still->statusSince);

        $changed = $this->state->setStation('ecowitt', 100, StationStatus::Stale, 200);
        self::assertSame(200, $changed->statusSince);
        self::assertSame(100, $changed->lastSeen);
    }

    public function testKeepsTheLatestRunsOnly(): void
    {
        for ($run = 1; $run <= 505; $run++) {
            $this->state->addRun($run, $run + 1, 'cli', ['run' => $run]);
        }

        $runs = $this->state->runs(3);
        self::assertSame([505, 504, 503], array_column($runs, 'started_at'));
        self::assertSame(['run' => 505], $runs[0]['summary']);
        self::assertSame('cli', $runs[0]['trigger']);
        self::assertCount(500, $this->state->runs(1000));
    }
}
