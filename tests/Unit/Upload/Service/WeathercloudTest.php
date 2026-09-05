<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Weathercloud;

final class WeathercloudTest extends TestCase
{
    public function testEveryValueIsAnIntegerInTenths(): void
    {
        $upload = new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => 'abc123', 'key' => 'k']), new FakeHttpClient());
        parse_str($upload->query(Uploads::metricRecord()), $query);

        self::assertSame('abc123', $query['wid']);
        self::assertSame('k', $query['key']);
        self::assertSame('251', $query['type']);
        self::assertSame('weewx-php', $query['ver']);
        self::assertSame('20260826', $query['date']);
        self::assertSame('08:50', $query['time']);
        self::assertSame('200', $query['temp']);
        self::assertSame('61', $query['hum']);
        self::assertSame('122', $query['dew']);
        self::assertSame('50', $query['wspd']);
        self::assertSame('180', $query['wdir']);
        // 10132.5 is a tie, and a tie goes to even, as Python's round has it.
        self::assertSame('10132', $query['bar']);
        self::assertSame('12', $query['rain']);
        self::assertSame('6', $query['rainrate']);
        self::assertSame('3005', $query['solarrad']);
        self::assertSame('40', $query['uvi']);
        self::assertArrayNotHasKey('tempin', $query);
        self::assertArrayNotHasKey('chill', $query);
        self::assertArrayNotHasKey('et', $query);
    }

    public function testOnlyTheNewestGoesAndWordsInTheBodyDecide(): void
    {
        $http = (new FakeHttpClient())->answer(200, '200');
        $upload = new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => 'w', 'key' => 'k', 'indoor' => true]), $http);
        $posted = $upload->post([Uploads::metricRecord(['dateTime' => 100]), Uploads::metricRecord(['dateTime' => 200])]);
        self::assertSame(1, $posted->sent);
        self::assertSame(1, $posted->skipped);
        self::assertSame(200, $posted->through);
        self::assertCount(1, $http->requests);
        self::assertStringContainsString('tempin=220', $http->last()->url);
        self::assertStringStartsWith(Weathercloud::URL . '?wid=w&key=k&type=251&ver=weewx-php&date=', $http->last()->url);

        $odd = new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => 'w', 'key' => 'k']), (new FakeHttpClient())->answer(200, 'something else'));
        self::assertCount(1, $odd->post([Uploads::metricRecord()])->failures);

        $wrong = new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => 'w', 'key' => 'k']), (new FakeHttpClient())->answer(200, '401'));
        try {
            $wrong->post([Uploads::metricRecord()]);
            self::fail('a wrong key is permanent');
        } catch (Rejected $error) {
            self::assertTrue($error->permanent);
        }
        self::assertSame('Weathercloud accepted the device w.', (new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => 'w', 'key' => 'k']), (new FakeHttpClient())->answer(200, '')))->check());
    }
}
