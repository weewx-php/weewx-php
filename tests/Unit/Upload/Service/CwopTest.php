<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeConnection;
use WeewxPhp\Tests\Support\FakeSocketFactory;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Cwop;
use WeewxPhp\Upload\UploadError;
use WeewxPhp\Version;
use WeewxPhp\Weewx\Units;

final class CwopTest extends TestCase
{
    public function testThePacketIsWeewxsCharacterForCharacter(): void
    {
        $upload = $this->cwop();
        $record = ['dateTime' => 1_787_734_200, 'usUnits' => 1, 'windDir' => 180.0, 'windSpeed' => 11.2, 'windGust' => 17.9, 'outTemp' => 68.0,
            'hourRain' => 0.01, 'rain24' => 0.05, 'dayRain' => 0.05, 'altimeter' => 29.921, 'outHumidity' => 61.0, 'radiation' => 300.5];
        $mbar = (float) Units::convert(29.921, 'inHg', 'mbar');

        self::assertSame(
            sprintf("DW1234>APZPHP,TCPIP*:@260850z4827.58N/01139.23E_180/011g018t068r001p005P005b%05dh61L301.weewx-php-%s\r\n", (int) ($mbar * 10 + 0.5), Version::STRING),
            $upload->packet($record),
        );
        self::assertSame('user DW1234 pass -1 vers weewx-php ' . Version::STRING . "\r\n", $upload->login());
    }

    public function testAMissingReadingIsDotsOfTheSameWidth(): void
    {
        $packet = $this->cwop()->packet(['dateTime' => 1_787_734_200, 'usUnits' => 1]);
        self::assertStringEndsWith('_.../...g...t...r...p...P...b.....h...weewx-php-' . Version::STRING . "\r\n", $packet);
    }

    public function testTheEdgesOfTheFormat(): void
    {
        $upload = $this->cwop();
        // 100 % humidity has no third digit; a temperature below zero keeps its sign in the three characters.
        $packet = $upload->packet(['dateTime' => 1_787_734_200, 'usUnits' => 1, 'outHumidity' => 99.6, 'outTemp' => -5.4, 'radiation' => 1200.0]);
        self::assertStringContainsString('t-04', $packet);
        self::assertStringContainsString('h00', $packet);
        self::assertStringContainsString('l200', $packet);
        // Beyond what the protocol can say, the radiation is left out.
        self::assertStringNotContainsString('L', substr($this->cwop()->packet(['dateTime' => 1_787_734_200, 'usUnits' => 1, 'radiation' => 2500.0]), 30));
        // A metric record is converted first.
        $metric = $upload->packet(Uploads::metricRecord());
        self::assertStringContainsString('t068', $metric);
        self::assertStringContainsString(sprintf('_180/%03dg%03d', (int) (Units::mpsToMph(5.0) + 0.5), (int) (Units::mpsToMph(8.0) + 0.5)), $metric);
    }

    public function testPositionsAreDegreesAndDecimalMinutes(): void
    {
        self::assertSame('4827.58N', Cwop::latlon(48.4596, ['N', 'S'], true));
        self::assertSame('01139.23E', Cwop::latlon(11.6539, ['E', 'W'], false));
        self::assertSame('2218.00S', Cwop::latlon(-22.3, ['N', 'S'], true));
        self::assertSame('09530.00W', Cwop::latlon(-95.5, ['E', 'W'], false));
    }

    public function testLogsInOnTheFirstServerThatAnswersAndSendsOnlyTheNewest(): void
    {
        $sockets = (new FakeSocketFactory())
            ->queue(new Rejected('refused'))
            ->queue($connection = new FakeConnection("# aprsc 2.1.4\r\n# logresp DW1234 unverified, server T2XX\r\n"));
        $upload = $this->cwop($sockets);

        $posted = $upload->post([Uploads::metricRecord(['dateTime' => 100]), Uploads::metricRecord(['dateTime' => 200])]);
        self::assertSame(1, $posted->sent);
        self::assertSame(1, $posted->skipped);
        self::assertSame(200, $posted->through);
        self::assertSame('cwop.aprs.net:23', $posted->note);
        self::assertSame(['cwop.aprs.net', 'cwop.aprs.net'], array_column($sockets->opened, 'host'));
        self::assertSame([14580, 23], array_column($sockets->opened, 'port'));
        self::assertStringStartsWith('user DW1234 pass -1 vers weewx-php ' . Version::STRING . "\r\nDW1234>APZPHP,TCPIP*:@", $connection->written);
        self::assertTrue($connection->closed);

        $nobody = $this->cwop((new FakeSocketFactory())->queue(new Rejected('a'))->queue(new Rejected('b')));
        $failed = $nobody->post([Uploads::metricRecord()]);
        self::assertSame(0, $failed->sent);
        self::assertSame([[1_787_734_200, 'no CWOP server answered: b']], $failed->failures);
        self::assertStringStartsWith('no CWOP server answered', $nobody->check());
    }

    public function testNeedsAStationAndUsableServers(): void
    {
        $this->expectException(UploadError::class);
        new Cwop(Uploads::config(Kind::Cwop, ['station' => 'DW1', 'servers' => ['nowhere:port']]), 48.0, 11.0, new FakeSocketFactory());
    }

    private function cwop(?FakeSocketFactory $sockets = null): Cwop
    {
        return new Cwop(Uploads::config(Kind::Cwop, ['station' => 'dw1234']), 48.4596, 11.6539, $sockets ?? new FakeSocketFactory());
    }
}
