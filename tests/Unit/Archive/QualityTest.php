<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Archive;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Quality;
use WeewxPhp\Config\Calibration;
use WeewxPhp\Config\QcRule;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Weewx\UnitSystem;

final class QualityTest extends TestCase
{
    public function testNegativeRainAmountsAndRatesAreRejectedWithoutOptionalQcRules(): void
    {
        $quality = new Quality([], null, []);
        $record = $quality->check(['usUnits' => 1, 'rain' => -1, 'yearRain' => -2, 'rainRate' => -3, 'outTemp' => -4]);
        self::assertNull($record['rain']);
        self::assertNull($record['yearRain']);
        self::assertNull($record['rainRate']);
        self::assertSame(-4, $record['outTemp']);
        self::assertSame(['rain' => 1, 'yearRain' => 1, 'rainRate' => 1], $quality->dropped());
    }

    public function testCorrectionsFollowTheSenderAndTouchOnlyNumbers(): void
    {
        $quality = new Quality([], null, [
            'ecowitt' => ['outTemp' => new Calibration(-0.4, 1.0), 'outHumidity' => new Calibration(0.0, 1.01)],
        ]);
        $record = ['usUnits' => 17, 'outTemp' => 20.0, 'outHumidity' => 50, 'inTemp' => 21.0, 'model' => 'HP2561', 'rain' => null];

        $corrected = $quality->calibrate($record, 'ecowitt');
        self::assertSame(19.6, $corrected['outTemp']);
        self::assertSame(50.5, $corrected['outHumidity']);
        self::assertSame(21.0, $corrected['inTemp']);
        self::assertSame('HP2561', $corrected['model']);
        self::assertNull($corrected['rain']);
        self::assertSame(2, $quality->adjusted());

        // Another sender's thermometer is another thermometer.
        self::assertSame($record, $quality->calibrate($record, 'dwd'));
        self::assertSame(2, $quality->adjusted());
    }

    public function testALimitInItsOwnUnitIsConvertedIntoTheRecords(): void
    {
        $quality = new Quality(['outTemp' => new QcRule('outTemp', -40.0, 60.0, 'degree_C')], null, []);

        // 60 C is 140 F: 139 passes, 141 does not.
        $kept = $quality->check(['usUnits' => 1, 'dateTime' => 1, 'outTemp' => 139.0]);
        self::assertSame(139.0, $kept['outTemp']);
        $refused = $quality->check(['usUnits' => 1, 'dateTime' => 2, 'outTemp' => 141.0]);
        self::assertArrayHasKey('outTemp', $refused);
        self::assertNull($refused['outTemp']);
        // The same figure is a fine temperature in Celsius... but not one below the floor.
        $metric = $quality->check(['usUnits' => 17, 'dateTime' => 3, 'outTemp' => 60.0]);
        self::assertSame(60.0, $metric['outTemp']);
        $cold = $quality->check(['usUnits' => 17, 'dateTime' => 4, 'outTemp' => -40.5]);
        self::assertNull($cold['outTemp']);
        self::assertSame(['outTemp' => 2], $quality->dropped());
        self::assertSame('outTemp x2', $quality->summary());
    }

    public function testALimitWithoutAUnitIsInTheRuleSystemOrElseTakenAsWritten(): void
    {
        $withSystem = new Quality(['outTemp' => new QcRule('outTemp', -40.0, 60.0, null)], UnitSystem::METRIC, []);
        self::assertNull($withSystem->check(['usUnits' => 1, 'outTemp' => 141.0])['outTemp']);
        self::assertSame(139.0, $withSystem->check(['usUnits' => 1, 'outTemp' => 139.0])['outTemp']);

        $asWritten = new Quality(['outTemp' => new QcRule('outTemp', -40.0, 60.0, null)], null, []);
        self::assertNull($asWritten->check(['usUnits' => 1, 'outTemp' => 61.0])['outTemp']);
        self::assertSame(60.0, $asWritten->check(['usUnits' => 1, 'outTemp' => 60.0])['outTemp']);

        // A type WeeWX knows no unit group for is compared as written, whatever the system.
        $groupless = new Quality(['wh65_sig' => new QcRule('wh65_sig', 0.0, 4.0, null)], UnitSystem::METRIC, []);
        self::assertSame(4, $groupless->check(['usUnits' => 1, 'wh65_sig' => 4])['wh65_sig']);
        self::assertNull($groupless->check(['usUnits' => 1, 'wh65_sig' => 5])['wh65_sig']);
    }

    public function testWhatIsNotThereIsNotJudged(): void
    {
        $quality = new Quality(['outTemp' => new QcRule('outTemp', 0.0, 10.0, null)], null, []);

        $absent = $quality->check(['usUnits' => 1, 'inTemp' => 99.0]);
        self::assertArrayNotHasKey('outTemp', $absent);
        $nothing = $quality->check(['usUnits' => 1, 'outTemp' => null]);
        self::assertNull($nothing['outTemp']);
        $text = $quality->check(['usUnits' => 1, 'outTemp' => 'warm']);
        self::assertSame('warm', $text['outTemp']);
        self::assertSame([], $quality->dropped());
        self::assertSame('', $quality->summary());

        // Not a number is not within any limits, as it is not in WeeWX.
        self::assertNull($quality->check(['usUnits' => 1, 'outTemp' => NAN])['outTemp']);
    }

    public function testComesOutOfTheArchiveConfiguration(): void
    {
        $config = Archives::config(
            qcUnitSystem: UnitSystem::METRIC,
            qc: ['outHumidity' => new QcRule('outHumidity', 0.0, 100.0, null)],
            calibrate: ['dwd' => ['outHumidity' => new Calibration(2.0, 1.0)]],
        );
        $quality = Quality::fromConfig($config);

        $record = $quality->calibrate(['usUnits' => 17, 'outHumidity' => 99.0], 'dwd');
        self::assertSame(101.0, $record['outHumidity']);
        self::assertNull($quality->check($record)['outHumidity']);
    }
}
