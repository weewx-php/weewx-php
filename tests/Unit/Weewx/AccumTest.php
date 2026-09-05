<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Weewx;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\AccumError;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\VecStats;

/**
 * The semantics that decide what lands in a record. The arithmetic itself
 * is compared against WeeWX by the conformance check `accum`.
 */
final class AccumTest extends TestCase
{
    public function testAveragesSumsAndTakesTheLastAsWeewxDoes(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 1, 'outTemp' => 60.0, 'rain' => 0.01, 'dayRain' => 0.5]);
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 1, 'outTemp' => 62.0, 'rain' => 0.0, 'dayRain' => 0.51]);
        $accum->addRecord(['dateTime' => 1300, 'usUnits' => 1, 'outTemp' => null, 'rain' => 0.02, 'dayRain' => 0.53]);

        $record = $accum->record();

        self::assertSame(1300, $record['dateTime']);
        self::assertSame(1, $record['usUnits']);
        self::assertSame(61.0, $record['outTemp']);
        self::assertEqualsWithDelta(0.03, $record['rain'], 1e-12);
        self::assertSame(0.53, $record['dayRain']);
        self::assertSame(UnitSystem::US, $accum->unitSystem());

        $outTemp = $accum->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame([60.0, 1100, 62.0, 1200, 122.0, 2, 122.0, 2], $outTemp->statsTuple());
    }

    public function testASumOfNothingIsNullNotZero(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 1, 'rain' => null]);

        self::assertNull($accum->record()['rain']);
    }

    public function testWindIsAVectorReadBackIntoFourColumns(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 17, 'windSpeed' => 2.0, 'windDir' => 90.0, 'windGust' => 5.0, 'windGustDir' => 100.0]);
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 17, 'windSpeed' => 4.0, 'windDir' => 90.0, 'windGust' => 3.0]);

        $record = $accum->record();

        self::assertSame(3.0, $record['windSpeed']);
        self::assertEqualsWithDelta(90.0, $record['windDir'], 1e-9);
        self::assertSame(5.0, $record['windGust']);
        self::assertSame(100.0, $record['windGustDir']);

        $wind = $accum->get('wind');
        self::assertInstanceOf(VecStats::class, $wind);
        self::assertSame(5.0, $wind->max);
        self::assertSame(1100, $wind->maxtime);
        self::assertSame([4.0, 90.0], $wind->last);
        self::assertSame(2, $wind->count);
    }

    public function testACalmWithoutADirectionKeepsTheLastDirection(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 17, 'windSpeed' => 0.0, 'windDir' => 180.0]);
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 17, 'windSpeed' => 0.0, 'windDir' => null]);

        $record = $accum->record();

        self::assertSame(0.0, $record['windSpeed']);
        self::assertNull($record['windDir']);
        $wind = $accum->get('wind');
        self::assertInstanceOf(VecStats::class, $wind);
        self::assertSame(2, $wind->dirsumtime);
    }

    public function testIgnoresWhatIsNotANumber(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 1, 'outTemp' => 'None', 'inTemp' => NAN, 'model' => 'HP2561AE']);
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 1, 'outTemp' => '61.5', 'inTemp' => 70.0, 'model' => 'HP2561AE']);

        $record = $accum->record();

        self::assertSame(61.5, $record['outTemp']);
        self::assertSame(70.0, $record['inTemp']);
        self::assertNull($record['model']);
    }

    public function testRefusesARecordOutsideTheSpanOrInAnotherUnitSystem(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1300, 'usUnits' => 1, 'outTemp' => 60.0]);

        try {
            $accum->addRecord(['dateTime' => 1000, 'usUnits' => 1, 'outTemp' => 60.0]);
            self::fail('a record on the start boundary belongs to the previous span');
        } catch (AccumError $error) {
            self::assertStringContainsString('outside span', $error->getMessage());
        }

        $this->expectException(AccumError::class);
        $this->expectExceptionMessage('Unit system mismatch');
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 16, 'outTemp' => 15.0]);
    }

    public function testMergesExtremesAndUsesTheMeanForWindSpeed(): void
    {
        $day = new Accum(0, 86400, UnitSystem::US);
        $day->setStats('outTemp', [50.0, 100, 55.0, 200, 105.0, 2, 630.0, 12]);
        $day->setStats('windSpeed');

        $interval = new Accum(3600, 3900);
        $interval->addRecord(['dateTime' => 3700, 'usUnits' => 1, 'outTemp' => 40.0, 'windSpeed' => 2.0, 'windDir' => 10.0]);
        $interval->addRecord(['dateTime' => 3800, 'usUnits' => 1, 'outTemp' => 70.0, 'windSpeed' => 10.0, 'windDir' => 10.0]);

        $day->mergeHilo($interval);

        $outTemp = $day->get('outTemp');
        self::assertInstanceOf(ScalarStats::class, $outTemp);
        self::assertSame([40.0, 3700, 70.0, 3800, 105.0, 2, 630.0, 12], $outTemp->statsTuple());

        $windSpeed = $day->get('windSpeed');
        self::assertInstanceOf(ScalarStats::class, $windSpeed);
        self::assertSame(6.0, $windSpeed->max);
        self::assertSame(3900, $windSpeed->maxtime);
        self::assertSame(2.0, $windSpeed->min);

        $wind = $day->get('wind');
        self::assertInstanceOf(VecStats::class, $wind);
        self::assertSame(10.0, $wind->max);
    }

    public function testAPolicyOverrideChangesTheExtractor(): void
    {
        $accum = new Accum(1000, 1300, null, new Policy(['lightning_num' => Extractor::Last]));
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 1, 'lightning_num' => 3]);
        $accum->addRecord(['dateTime' => 1200, 'usUnits' => 1, 'lightning_num' => 5]);

        self::assertSame(5, $accum->record()['lightning_num']);
    }

    public function testAugmentLeavesWhatTheRecordAlreadyHolds(): void
    {
        $accum = new Accum(1000, 1300);
        $accum->addRecord(['dateTime' => 1100, 'usUnits' => 1, 'outTemp' => 60.0, 'inTemp' => 70.0]);

        $record = $accum->augment(['dateTime' => 1300, 'usUnits' => 1, 'outTemp' => 99.0]);

        self::assertSame(99.0, $record['outTemp']);
        self::assertSame(70.0, $record['inTemp']);
    }
}
