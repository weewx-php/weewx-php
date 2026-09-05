<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Live;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Weewx\UnitSystem;

final class LiveDbTest extends TestCase
{
    private const T0 = 1_787_734_200;

    private string $dir;
    private LiveDb $live;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('live');
        $this->live = LiveDb::open($this->dir . '/live.sdb', JournalMode::Wal);
    }

    protected function tearDown(): void
    {
        $this->live->close();
        TempDir::remove($this->dir);
    }

    public function testARetryIsNotASecondPacketButANewReadingIs(): void
    {
        $packet = $this->packet(self::T0 + 10, ['outTemp' => 20.5, 'runtime' => 1000], volatile: ['runtime']);
        $retry = $this->packet(self::T0 + 10, ['outTemp' => 20.5, 'runtime' => 1016], volatile: ['runtime']);
        $other = $this->packet(self::T0 + 10, ['outTemp' => 20.6]);

        self::assertTrue($this->live->add($packet, ['a'], 300, now: self::T0 + 11));
        self::assertFalse($this->live->add($retry, ['a'], 300, now: self::T0 + 12));
        self::assertTrue($this->live->add($other, ['a'], 300, now: self::T0 + 13));

        self::assertSame(2, $this->live->count());
        self::assertSame([self::T0 + 10, self::T0 + 10], $this->live->span());
        $senders = $this->live->senders();
        self::assertCount(1, $senders);
        self::assertSame('ecowitt', $senders[0]->sender);
        self::assertSame(self::T0 + 11, $senders[0]->firstSeen);
    }

    public function testMarksTheIntervalForEveryArchiveAndClearsThemOneByOne(): void
    {
        $this->live->add($this->packet(self::T0 + 10, ['outTemp' => 1.0]), ['a', 'b'], 300);
        $stop = self::T0 + 300;

        self::assertSame([], $this->live->due($stop + 10, 15, 'a'));
        self::assertSame([['stop' => $stop, 'seconds' => 300]], $this->live->due($stop + 15, 15, 'a'));
        self::assertSame([['stop' => $stop, 'seconds' => 300]], $this->live->due($stop + 15, 15, 'b'));
        self::assertSame([], $this->live->due($stop + 15, 15, 'c'));

        $this->live->clearPending($stop, 'a');

        self::assertSame([], $this->live->due($stop + 15, 15, 'a'));
        self::assertSame([['stop' => $stop, 'seconds' => 300]], $this->live->due($stop + 15, 15, 'b'));
    }

    public function testReadsPacketsInTimeOrderWithinTheSpanAndBySender(): void
    {
        $this->live->add($this->packet(self::T0 + 300, ['outTemp' => 3.0]), ['a'], 300);
        $this->live->add($this->packet(self::T0 + 1, ['outTemp' => 1.0]), ['a'], 300);
        $this->live->add($this->packet(self::T0 + 200, ['outTemp' => 2.0], sender: 'dwd'), ['a'], 300);
        $this->live->add($this->packet(self::T0 + 301, ['outTemp' => 4.0]), ['a'], 300);
        $this->live->add($this->packet(self::T0, ['outTemp' => 0.0]), ['a'], 300);
        $this->live->add($this->packet(self::T0 + 250, ['outTemp' => 9.0], kind: PacketKind::Archive, interval: 5.0), ['a'], 300);

        $all = iterator_to_array($this->live->packets(self::T0, self::T0 + 300), false);
        self::assertSame([1.0, 2.0, 9.0, 3.0], array_map(static fn(Packet $p): mixed => $p->data['outTemp'], $all));

        $loop = iterator_to_array($this->live->packets(self::T0, self::T0 + 300, PacketKind::Loop, ['ecowitt']), false);
        self::assertSame([1.0, 3.0], array_map(static fn(Packet $p): mixed => $p->data['outTemp'], $loop));
        self::assertSame([], iterator_to_array($this->live->packets(self::T0, self::T0 + 300, null, []), false));

        $archive = iterator_to_array($this->live->packets(self::T0, self::T0 + 300, PacketKind::Archive), false);
        self::assertSame(5.0, $archive[0]->interval);
        self::assertSame(['outTemp' => 9.0, 'dateTime' => self::T0 + 250, 'usUnits' => 17, 'interval' => 5.0], $archive[0]->record());

        self::assertSame(self::T0 + 301, $this->live->nextPacketAfter(self::T0 + 300));
        self::assertNull($this->live->nextPacketAfter(self::T0 + 300, ['dwd']));
        self::assertSame(['dwd' => self::T0 + 200, 'ecowitt' => self::T0 + 301], $this->live->lastSeen());
    }

    public function testPrunesOldPacketsAndForgetsRawUploadsByArrival(): void
    {
        $this->live->add($this->packet(self::T0, ['outTemp' => 1.0], raw: 'tempf=33.8'), ['a'], 300, now: self::T0 + 1);
        $this->live->add($this->packet(self::T0 + 3600, ['outTemp' => 2.0], raw: 'tempf=35.6'), ['a'], 300, now: self::T0 + 3601);

        self::assertSame(1, $this->live->forgetRaw(self::T0 + 1800));
        $packets = iterator_to_array($this->live->packets(self::T0 - 1, self::T0 + 3600, withRaw: true), false);
        self::assertNull($packets[0]->raw);
        self::assertSame('tempf=35.6', $packets[1]->raw);

        self::assertSame(1, $this->live->prune(self::T0 + 1800));
        self::assertSame(1, $this->live->count());
        // The pruned packet's interval went with it; the other one is still due.
        self::assertSame([], $this->live->due(self::T0 + 1800, 0, 'a'));
        self::assertSame([['stop' => self::T0 + 3600, 'seconds' => 300]], $this->live->due(self::T0 + 3600, 0, 'a'));
    }

    public function testKeepsADialectDescriptionOnceAndHandsItBack(): void
    {
        $mapping = ['version' => 1, 'fields' => ['tempf' => 'outTemp']];
        $this->live->add($this->packet(self::T0 + 1, ['tempf' => 70.0], dialect: 'ecowitt', mapping: $mapping), ['a'], 300);
        $this->live->add($this->packet(self::T0 + 2, ['tempf' => 71.0], dialect: 'ecowitt', mapping: $mapping), ['a'], 300);

        $packets = iterator_to_array($this->live->packets(self::T0, self::T0 + 300), false);
        self::assertSame('ecowitt', $packets[0]->dialect);
        // Stored in canonical, sorted form; the content is what matters.
        self::assertEquals($mapping, $packets[1]->mapping);

        $this->live->setMeta(LiveDb::ARCHIVES_KEY, 'a,b');
        self::assertSame('a,b', $this->live->getMeta(LiveDb::ARCHIVES_KEY));
        self::assertNull($this->live->getMeta('nothing'));
    }

    public function testTheDigestIsWhatWeewxEvoWouldHash(): void
    {
        $packet = $this->packet(self::T0, ['tempf' => 70.5, 'a/b' => 'x', 'n' => 1.0, 'z' => null]);

        self::assertSame('{"a/b":"x","n":1.0,"tempf":70.5,"z":null}', Packet::canonical($packet->data));
        self::assertSame(substr(hash('sha256', '{"a/b":"x","n":1.0,"tempf":70.5,"z":null}'), 0, 16), $packet->digest());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $mapping
     * @param list<string> $volatile
     */
    private function packet(
        int $when,
        array $data,
        string $sender = 'ecowitt',
        PacketKind $kind = PacketKind::Loop,
        ?float $interval = null,
        ?string $raw = null,
        ?string $dialect = null,
        ?array $mapping = null,
        array $volatile = [],
    ): Packet {
        // The hardware identity follows the sender: one console is one sender.
        return new Packet($when, UnitSystem::METRICWX, $data, $sender, 'ecowitt', strtoupper($sender), $dialect, $mapping, $kind, $interval, null, $raw, $volatile);
    }
}
