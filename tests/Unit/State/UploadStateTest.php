<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\State;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\State\StateDb;
use WeewxPhp\Tests\Support\TempDir;

final class UploadStateTest extends TestCase
{
    private string $dir;
    private StateDb $state;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('uploadstate');
        $this->state = StateDb::open($this->dir . '/state.sdb', JournalMode::Wal);
    }

    protected function tearDown(): void
    {
        $this->state->close();
        TempDir::remove($this->dir);
    }

    public function testRemembersHowFarAnUploadGotAndNeverMovesItBack(): void
    {
        $blank = $this->state->upload('wu');
        self::assertSame(0, $blank->through);
        self::assertNull($blank->lastRunAt);
        self::assertSame(0, $blank->runs);

        $this->state->noteUploadRun('wu', 1000, 2, 900, '2 sent');
        $this->state->noteUploadRun('wu', 1300, 0, null, 'nothing new');
        $this->state->noteUploadRun('wu', 1600, 1, 800, '1 sent');
        $wu = $this->state->upload('wu');
        self::assertSame(900, $wu->through);
        self::assertSame(1600, $wu->lastRunAt);
        self::assertSame(1600, $wu->lastSentAt);
        self::assertSame(3, $wu->runs);
        self::assertSame(3, $wu->sent);
        self::assertSame('1 sent', $wu->lastSummary);

        $this->state->rewindUpload('wu', 500);
        self::assertSame(500, $this->state->upload('wu')->through);
    }

    public function testCountsFailuresInARowAndABlockUntilARunGetsThrough(): void
    {
        $this->state->noteUploadFailure('wu', 100, 'timeout');
        $this->state->noteUploadFailure('wu', 200, 'timeout');
        self::assertSame(2, $this->state->upload('wu')->failures);

        $this->state->blockUpload('wu', 300, 'bad password');
        $blocked = $this->state->upload('wu');
        self::assertSame('bad password', $blocked->blocked);
        self::assertSame(300, $blocked->blockedAt);
        self::assertSame(3, $blocked->failures);
        self::assertSame(3, $blocked->runs);

        $this->state->unblockUpload('wu');
        self::assertNull($this->state->upload('wu')->blocked);
        $this->state->blockUpload('wu', 400, 'bad password');
        $this->state->noteUploadRun('wu', 500, 1, 450, '1 sent');
        $again = $this->state->upload('wu');
        self::assertNull($again->blocked);
        self::assertSame(0, $again->failures);

        $this->state->noteAnnounced('wu', 600);
        self::assertSame(600, $this->state->upload('wu')->announcedAt);
    }
}
