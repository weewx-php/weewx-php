<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Mapping;
use WeewxPhp\Archive\MappingError;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Live\SenderIdentity;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\Wview;

final class MappingTest extends TestCase
{
    private const T0 = 1_787_734_200;

    public function testAmbiguousGaugeSnapshotsAreNeverCombinedIntoRain(): void
    {
        $config = Archives::config(fields: ['ecowitt' => ['dayRain' => 'dayRain'], 'dwd' => ['monthRain' => 'monthRain']]);
        $mapping = new Mapping($config, 'ecowitt');
        self::assertSame([], $mapping->rainSources('ecowitt'));
        self::assertSame([], $mapping->rainSources('dwd'));
        $chosen = new Mapping(Archives::config(fields: ['ecowitt' => ['yearRain' => 'rain'], 'dwd' => ['monthRain' => 'monthRain']]), 'ecowitt');
        self::assertContains('yearRain', $chosen->rainSources('ecowitt'));
        self::assertSame([], $chosen->rainSources('dwd'));
        $placed = $chosen->place($this->packet('ecowitt', ['yearRain' => 200]));
        self::assertNotNull($placed);
        self::assertArrayHasKey('rain', $placed);
        self::assertNull($placed['rain']);
    }

    public function testThePrimaryIsPlacedByNameWithFieldsMovingAndDroppingReadings(): void
    {
        $mapping = new Mapping(Archives::config(fields: ['ecowitt' => ['inTemp' => '-', 'tf_ch1' => 'soilTemp1']]), 'ecowitt');
        $packet = $this->packet('ecowitt', ['outTemp' => 20.5, 'inTemp' => 22.0, 'tf_ch1' => 14.0, 'unknown' => 1, 'rain' => null, 'dateTime' => 1]);

        self::assertSame(
            ['outTemp' => 20.5, 'soilTemp1' => 14.0, 'unknown' => 1, 'dateTime' => self::T0, 'usUnits' => 17],
            $mapping->place($packet),
        );
        self::assertSame([], $mapping->dropped());
    }

    public function testAnArchiveKindPacketCarriesItsInterval(): void
    {
        $mapping = new Mapping(Archives::config(), 'ecowitt');
        $placed = $mapping->place($this->packet('ecowitt', ['outTemp' => 1.0], kind: PacketKind::Archive, interval: 5.0));
        self::assertSame(['outTemp' => 1.0, 'dateTime' => self::T0, 'usUnits' => 17, 'interval' => 5.0], $placed);
    }

    public function testAnAdditionalSenderWritesOnlyWhatWasPlacedAndItsHousekeeping(): void
    {
        $mapping = new Mapping(Archives::config(fields: ['dwd' => ['outTemp' => 'extraTemp1']]), 'ecowitt');
        $packet = $this->packet('dwd', ['outTemp' => 3.0, 'outHumidity' => 80.0, 'wh31_ch1_batt' => 1, 'rssi' => 5, 'inTemp' => 20.0]);

        $placed = $mapping->place($packet);
        self::assertSame(['extraTemp1' => 3.0, 'wh31_ch1_batt' => 1, 'dateTime' => self::T0, 'usUnits' => 17], $placed);
        self::assertSame(['outHumidity' => 1, 'rssi' => 1, 'inTemp' => 1], $mapping->dropped());

        // Nothing placed and nothing kept: the packet said nothing this archive holds.
        self::assertNull($mapping->place($this->packet('dwd', ['windSpeed' => 2.0])));
    }

    public function testIndoorReadingsGoUnlessSomebodyPlacedThem(): void
    {
        $config = Archives::config(indoor: ['ecowitt' => false, 'dwd' => false], fields: ['dwd' => ['inTemp' => 'extraTemp2', 'inHumidity' => 'inHumidity']]);
        $mapping = new Mapping($config, 'ecowitt');

        $primary = $mapping->place($this->packet('ecowitt', ['outTemp' => 1.0, 'inTemp' => 20.0, 'inHumidity' => 40.0, 'inDewpoint' => 6.0]));
        self::assertSame(['outTemp' => 1.0, 'dateTime' => self::T0, 'usUnits' => 17], $primary);

        // Placed by hand, a room reading goes where it was sent, even to the very column the rule would clear.
        $extra = $mapping->place($this->packet('dwd', ['inTemp' => 18.0, 'inHumidity' => 55.0, 'inDewpoint' => 9.0]));
        self::assertSame(['extraTemp2' => 18.0, 'inHumidity' => 55.0, 'dateTime' => self::T0, 'usUnits' => 17], $extra);
    }

    public function testWhatIsNotSelectedOrNotTranslatedIsNotPlaced(): void
    {
        $mapping = new Mapping(Archives::config(senders: ['ecowitt']), 'ecowitt');
        self::assertNull($mapping->place($this->packet('dwd', ['outTemp' => 1.0])));
        self::assertNull($mapping->place($this->packet('ecowitt', ['tempf' => 70.0], dialect: 'ecowitt')));
        self::assertNull($mapping->place($this->packet('ecowitt', ['outTemp' => null])));

        $all = new Mapping(Archives::config(senders: null), 'ecowitt');
        self::assertNotNull($all->place($this->packet('anybody', ['wh65_batt' => 0])));
    }

    public function testThePrimaryIsTheConfiguredSenderOrElseTheOneHeardFirst(): void
    {
        $heard = [new SenderIdentity('dwd', 'dwd', '', '', 100), new SenderIdentity('ecowitt', 'ecowitt', 'ID', '', 200)];

        self::assertSame('ecowitt', Mapping::resolvePrimary(Archives::config(primary: 'ecowitt'), $heard));
        self::assertSame('dwd', Mapping::resolvePrimary(Archives::config(primary: null), $heard));
        self::assertSame('ecowitt', Mapping::resolvePrimary(Archives::config(primary: null, senders: ['ecowitt']), $heard));
        self::assertNull(Mapping::resolvePrimary(Archives::config(primary: null, senders: ['ecowitt']), []));
    }

    public function testAForeignDatabaseIsNotWrittenByNameUnlessTold(): void
    {
        $schema = $this->schema();

        $mapping = new Mapping(Archives::config(), 'ecowitt');
        $mapping->verify($schema, foreign: false);
        try {
            $mapping->verify($schema, foreign: true);
            self::fail('a foreign database without fields must be refused');
        } catch (MappingError $error) {
            self::assertStringContainsString('auto_mapping', $error->getMessage());
        }

        (new Mapping(Archives::config(autoMapping: true), 'ecowitt'))->verify($schema, foreign: true);
        (new Mapping(Archives::config(fields: ['ecowitt' => ['outTemp' => 'outTemp']]), 'ecowitt'))->verify($schema, foreign: true);
        // Nobody heard from yet: nothing to refuse.
        (new Mapping(Archives::config(primary: null), null))->verify($schema, foreign: true);
    }

    public function testAPlacementNeedsItsColumn(): void
    {
        $mapping = new Mapping(Archives::config(fields: ['dwd' => ['outTemp' => 'extraTemp1', 'soil' => 'soilMoist9', 'x' => '-']]), 'ecowitt');
        $this->expectException(MappingError::class);
        $this->expectExceptionMessage('dwd.soil = soilMoist9');
        $mapping->verify($this->schema(), foreign: false);
    }

    public function testHousekeepingSuffixes(): void
    {
        self::assertTrue(Mapping::keeps('wh65_batt'));
        self::assertTrue(Mapping::keeps('outTempBatteryStatus'));
        self::assertTrue(Mapping::keeps('txBatteryStatus'));
        self::assertTrue(Mapping::keeps('wh31_ch1_rssi'));
        self::assertTrue(Mapping::keeps('wh31_ch1_sig'));
        self::assertFalse(Mapping::keeps('outTemp'));
        self::assertFalse(Mapping::keeps('battery'));
        // The suffixes are weewx-evo's, and a voltage is not among them.
        self::assertFalse(Mapping::keeps('consBatteryVoltage'));
    }

    private function schema(): Schema
    {
        $columns = [];
        $types = [];
        foreach (Wview::ARCHIVE_TABLE as [$name, $type]) {
            $columns[] = $name;
            $types[$name] = $type;
        }
        return new Schema('archive', $columns, $types, [], []);
    }

    /** @param array<string, mixed> $data */
    private function packet(string $sender, array $data, ?string $dialect = null, PacketKind $kind = PacketKind::Loop, ?float $interval = null): Packet
    {
        return new Packet(self::T0, UnitSystem::METRICWX, $data, $sender, $sender, strtoupper($sender), $dialect, null, $kind, $interval);
    }
}
