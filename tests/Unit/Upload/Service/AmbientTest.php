<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Ambient;
use WeewxPhp\Weewx\Units;

final class AmbientTest extends TestCase
{
    public function testTheQueryIsWeewxsParameterForParameterInUsUnits(): void
    {
        $http = new FakeHttpClient();
        $upload = new Ambient(Uploads::config(Kind::Wunderground, ['station' => 'IBAYERN123', 'password' => 'sec ret&']), $http);
        $query = self::parameters($upload->url(Uploads::metricRecord()));

        self::assertSame('updateraw', $query['action']);
        self::assertSame('IBAYERN123', $query['ID']);
        self::assertSame('sec ret&', $query['PASSWORD']);
        self::assertSame('weewx-php', $query['softwaretype']);
        self::assertSame('2026-08-26 08:50:00', $query['dateutc']);
        self::assertSame('68.0', $query['tempf']);
        self::assertSame('061', $query['humidity']);
        self::assertSame('180', $query['winddir']);
        self::assertSame('190', $query['windgustdir']);
        self::assertSame(sprintf('%03.1f', Units::mpsToMph(5.0)), $query['windspeedmph']);
        self::assertSame(sprintf('%.3f', (float) Units::convert(1013.25, 'mbar', 'inHg')), $query['baromin']);
        self::assertSame(sprintf('%.2f', 0.4 / 25.4), $query['rainin']);
        self::assertSame(sprintf('%.2f', 1.2 / 25.4), $query['dailyrainin']);
        self::assertSame('300.50', $query['solarradiation']);
        self::assertSame('4.00', $query['UV']);
        // What the record does not hold is not in the query, and the house stays private.
        self::assertArrayNotHasKey('soilmoisture', $query);
        self::assertArrayNotHasKey('indoortempf', $query);
        self::assertArrayNotHasKey('AqPM2.5', $query);
        self::assertStringStartsWith('https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php?action=updateraw&ID=IBAYERN123&PASSWORD=sec%20ret%26&softwaretype=weewx-php&', $upload->url(Uploads::metricRecord()));
    }

    public function testIndoorReadingsGoOnlyWhenAsked(): void
    {
        $upload = new Ambient(Uploads::config(Kind::PwsWeather, ['station' => 'S', 'password' => 'p', 'indoor' => true]), new FakeHttpClient());
        $query = self::parameters($upload->url(Uploads::metricRecord()));
        self::assertSame('71.6', $query['indoortempf']);
        self::assertSame('44', $query['indoorhumidity']);
        self::assertStringStartsWith('https://www.pwsweather.com/pwsupdate/pwsupdate.php?', $upload->url(Uploads::metricRecord()));
    }

    public function testWowRenamesTheCredentialsAndSendsItsShorterList(): void
    {
        $upload = new Ambient(Uploads::config(Kind::Wow, ['station' => '12345', 'password' => '654321']), new FakeHttpClient());
        $query = self::parameters($upload->url(Uploads::metricRecord()));
        self::assertSame('12345', $query['siteid']);
        self::assertSame('654321', $query['siteAuthenticationKey']);
        self::assertArrayNotHasKey('ID', $query);
        self::assertSame('61', $query['humidity']);
        self::assertSame(sprintf('%.0f', Units::mpsToMph(5.0)), $query['windspeedmph']);
        self::assertSame(sprintf('%.3f', 1.2 / 25.4), $query['dailyrainin']);
        self::assertArrayNotHasKey('solarradiation', $query);
        self::assertArrayNotHasKey('UV', $query);
    }

    public function testPostsEachRecordAndStopsAtTheFirstRefusal(): void
    {
        $http = (new FakeHttpClient())->answer(200, 'success')->answer(500, 'oops')->answer(200, 'success');
        $upload = new Ambient(Uploads::config(Kind::Wunderground, ['station' => 'S', 'password' => 'p']), $http);
        $records = [Uploads::metricRecord(['dateTime' => 100]), Uploads::metricRecord(['dateTime' => 200]), Uploads::metricRecord(['dateTime' => 300])];

        $posted = $upload->post($records);
        self::assertSame(1, $posted->sent);
        self::assertSame(100, $posted->through);
        self::assertSame([[200, 'Weather Underground answered 500: oops']], $posted->failures);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertSame(10, $http->requests[0]->timeout);
    }

    public function testAWrongPasswordIsPermanentWhateverTheStatusSays(): void
    {
        $http = (new FakeHttpClient())->answer(200, "INVALIDPASSWORDID\nPassword and/or id are incorrect");
        $upload = new Ambient(Uploads::config(Kind::Wunderground, ['station' => 'S', 'password' => 'p']), $http);
        try {
            $upload->post([Uploads::metricRecord()]);
            self::fail('a bad password must be refused for good');
        } catch (Rejected $error) {
            self::assertTrue($error->permanent);
            self::assertStringContainsString('rejected the credentials for station S', $error->getMessage());
        }

        $wow = new Ambient(Uploads::config(Kind::Wow, ['station' => '1', 'password' => 'p']), (new FakeHttpClient())->answer(403, ''));
        try {
            $wow->post([Uploads::metricRecord()]);
            self::fail('WOW says 403');
        } catch (Rejected $error) {
            self::assertTrue($error->permanent);
        }

        // A name that does not resolve is a typo, and a typo is permanent.
        $lost = new Ambient(Uploads::config(Kind::PwsWeather, ['station' => 'S', 'password' => 'p']), (new FakeHttpClient())->fail('no such host', permanent: true));
        $this->expectException(Rejected::class);
        $lost->post([Uploads::metricRecord()]);
    }

    public function testCheckPostsNothingButTheTimestamp(): void
    {
        $http = (new FakeHttpClient())->answer(200, 'success');
        $upload = new Ambient(Uploads::config(Kind::Wunderground, ['station' => 'S', 'password' => 'p']), $http);
        self::assertSame('Weather Underground accepted the credentials for S.', $upload->check());
        $query = self::parameters($http->last()->url);
        self::assertSame(['action', 'ID', 'PASSWORD', 'softwaretype', 'dateutc'], array_keys($query));

        $refused = new Ambient(Uploads::config(Kind::Wunderground, ['station' => 'S', 'password' => 'p']), (new FakeHttpClient())->answer(200, 'badauth'));
        self::assertStringStartsWith('refused: ', $refused->check());
        self::assertSame(['host' => 'weatherstation.wunderground.com', 'station' => 'S'], $refused->describe());
    }

    /** @return array<string, string> */
    private static function parameters(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $found = [];
        foreach (explode('&', $query) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $found[rawurldecode($name)] = rawurldecode($value);
        }
        return $found;
    }
}
