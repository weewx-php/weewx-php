<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Mapping;
use WeewxPhp\Archive\MappingError;
use WeewxPhp\Ingest\EcowittFields;
use WeewxPhp\Ingest\Observation;
use WeewxPhp\Ingest\Parser;
use WeewxPhp\Ingest\Protocol;
use WeewxPhp\Measurement\Catalog;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

final class EcowittFieldsTest extends TestCase
{
    public function testRealConsoleCaptureHasTypedMeasurementsAndSeparateDiagnostics(): void
    {
        $body = file_get_contents(dirname(__DIR__, 2) . '/uploads/hp2561ae_pro.txt');
        self::assertNotFalse($body);
        $obs = Parser::observation(Protocol::Ecowitt, Parser::form(trim($body)), 1787656002);
        self::assertSame(5.0, $obs->data['lightning_Batt']);
        self::assertSame(0.0, $obs->data['lightningBatteryStatus']);
        self::assertSame(30.0, $obs->data['soilMoistPct1']);
        self::assertSame(60.0, $obs->data['soilEC1']);
        self::assertSame(212.0, $obs->data['windDir10']);
        self::assertSame(8.05, $obs->data['maxdailygust']);
        self::assertSame(0.02, $obs->data['rain24']);
        self::assertEqualsWithDelta(0.1591602, $obs->data['vpd'], 0.000001);
        self::assertArrayNotHasKey('soilMoist1', $obs->data);
        self::assertArrayNotHasKey('lightning_strike_count', $obs->data);
        self::assertSame(0.0, $obs->data['lightningDayCount']);
        foreach (['soil_ec_hum_ad1' => 931.0, 'soil_ec_ad1' => 410.0] as $name => $value) {
            self::assertSame(['observation' => null, 'value' => $value, 'unit' => null], $obs->fields[$name]);
            self::assertArrayNotHasKey($name, $obs->data);
        }
        self::assertSame('percent', $obs->fields['soil_ec_hum1']['unit']);
        self::assertSame('microsiemens_per_centimeter', $obs->fields['soil_ec1']['unit']);
        self::assertSame('volt', $obs->fields['soil_ec_batt1']['unit']);
        foreach (UnitSystem::cases() as $system) {
            $record = Units::toSystem($obs->data + ['usUnits' => 1], $system);
            self::assertSame(30.0, $record['soilMoistPct1']);
            self::assertSame(60.0, $record['soilEC1']);
            self::assertSame($obs->data['vpd'], $record['vpd']);
            self::assertSame('centibar', Units::unitOf($system, 'soilMoist1')[0]);
        }
    }

    public function testBatteryLevelsStatusesAndVoltagesCannotOverwriteEachOther(): void
    {
        $obs = $this->parse('wh57batt=1&wh65batt=0&wh80batt=3.06&wh90batt=2.8');
        self::assertSame(1.0, $obs->data['lightningBatteryStatus']);
        self::assertSame(0.0, $obs->data['outTempBatteryStatus']);
        self::assertSame(3.06, $obs->data['wh80_batt']);
        self::assertSame(2.8, $obs->data['wh90_batt']);
        self::assertSame(1.0, $this->parse('wh57batt=0')->data['lightningBatteryStatus']);
        self::assertSame(0.0, $this->parse('wh57batt=2')->data['lightningBatteryStatus']);
        foreach (['9', '-1', '2.5', '--', '-9999', '1e999'] as $value) {
            $bad = $this->parse('humidity=50&wh57batt=' . $value);
            self::assertNull($bad->data['lightning_Batt'] ?? null);
            self::assertNull($bad->data['lightningBatteryStatus'] ?? null);
            self::assertNull($bad->fields['wh57batt']['value']);
        }
        $bad = $this->parse('humidity=50&soil_ec1=10001&soilmoisture1=101&vpd=1e308&wh65batt=5');
        foreach (['soilEC1', 'soilMoistPct1', 'vpd', 'outTempBatteryStatus'] as $name) {
            self::assertNull($bad->data[$name]);
        }
    }

    public function testChannelBoundariesAliasesAndUnknownFieldsKeepTheirIdentity(): void
    {
        $obs = $this->parse('soil_ec16=120&soil_ec_hum16=40&tf_ch16=68&soil_ec17=999&newprobe=2&soilmoisture1=20&soil_ec_hum1=30');
        self::assertSame(120.0, $obs->data['soilEC16']);
        self::assertSame(40.0, $obs->data['soilMoistPct16']);
        self::assertSame(68.0, $obs->data['extraTemp24']);
        self::assertArrayNotHasKey('soilEC17', $obs->data);
        self::assertNull($obs->fields['soil_ec17']['observation']);
        self::assertNull($obs->fields['newprobe']['observation']);
        self::assertSame(20.0, $obs->data['soilMoistPct1']);
        self::assertSame(30.0, $obs->fields['soil_ec_hum1']['value']);
        foreach (EcowittFields::all() as $field) {
            foreach (UnitSystem::cases() as $system) {
                $target = Units::unitOf($system, $field->observation)[0];
                self::assertNotNull($target, $field->observation);
                self::assertTrue(Units::canConvert($field->unit, $target), $field->observation);
            }
        }
    }

    public function testSnapshotsRemainLastAfterRenamingAndDoNotBecomeIntervalAmounts(): void
    {
        $config = Archives::config(fields: ['ecowitt' => ['lightning_Batt' => 'gardenBattery']]);
        $accum = new Accum(1000, 1300, policy: $config->policy());
        foreach ([1100 => 'wh57batt=0&winddir_avg10m=359&last24hrainin=0.8&maxdailygust=20&lightning_num=6',
            1200 => 'wh57batt=5&winddir_avg10m=1&last24hrainin=0.1&maxdailygust=2&lightning_num=6'] as $time => $wire) {
            $data = $this->parse($wire)->data;
            $data['gardenBattery'] = $data['lightning_Batt'];
            $accum->addRecord($data + ['dateTime' => $time, 'usUnits' => 1]);
        }
        $record = $accum->record();
        self::assertSame(5.0, $record['gardenBattery']);
        self::assertSame(0.0, $record['lightningBatteryStatus']);
        self::assertSame(1.0, $record['windDir10']);
        self::assertSame(0.1, $record['rain24']);
        self::assertSame(2.0, $record['maxdailygust']);
        self::assertSame(6.0, $record['lightningDayCount']);
        self::assertArrayNotHasKey('rain', $record);
        self::assertArrayNotHasKey('windGust', $record);
        self::assertArrayNotHasKey('lightning_strike_count', $record);
        self::assertFalse(Catalog::compatible('soilMoistPct1', 'soilMoist1'));
        self::assertFalse(Catalog::compatible('rain24', 'rain'));
        self::assertFalse(Catalog::compatible('maxdailygust', 'windGust'));
        self::assertFalse(Catalog::compatible('vpd', 'barometer'));
    }

    public function testLegacyMappingAlsoRefusesPercentIntoCentibar(): void
    {
        $config = Archives::config(fields: ['ecowitt' => ['soilMoistPct1' => 'soilMoist1']]);
        $this->expectException(MappingError::class);
        (new Mapping($config, 'ecowitt'))->verify(new Schema('archive', ['soilMoist1'], ['soilMoist1' => 'REAL'], [], []), false);
    }

    public function testSnapshotMappingCannotOverrideLastWithSum(): void
    {
        $config = Archives::config(
            fields: ['ecowitt' => ['rain24' => 'rain24']],
            columns: ['rain24' => ColumnType::Real],
            extractors: ['rain24' => Extractor::Sum],
        );
        $this->expectException(MappingError::class);
        (new Mapping($config, 'ecowitt'))->verify(new Schema('archive', ['rain24'], ['rain24' => 'REAL'], [], []), false);
    }

    private function parse(string $body): Observation
    {
        return Parser::observation(Protocol::Ecowitt, Parser::form('PASSKEY=' . str_repeat('A', 32) . '&' . $body), 1787656002);
    }
}
