<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Windy;
use WeewxPhp\Upload\UploadError;

final class WindyTest extends TestCase
{
    public function testAnObservationIsMetricWithThePressureInPascals(): void
    {
        $upload = new Windy(Uploads::config(Kind::Windy, ['api_key' => 'k3y', 'station' => 2]), new FakeHttpClient());
        $observation = $upload->observation(Uploads::metricRecord());

        self::assertSame(2, $observation['station']);
        self::assertSame('2026-08-26 08:50:00', $observation['dateutc']);
        self::assertSame(20.0, $observation['temp']);
        self::assertSame(12.2, $observation['dewpoint']);
        self::assertSame(61.0, $observation['rh']);
        self::assertSame(5.0, $observation['wind']);
        self::assertSame(180.0, $observation['winddir']);
        self::assertSame(8.0, $observation['gust']);
        self::assertSame(101325.0, $observation['pressure']);
        self::assertSame(0.4, $observation['precip']);
        self::assertSame(4.0, $observation['uv']);
        self::assertArrayNotHasKey('temp', $upload->observation(['dateTime' => 1, 'usUnits' => 17]));

        // From a US record the same numbers come out, converted.
        $us = $upload->observation(['dateTime' => 1_787_734_200, 'usUnits' => 1, 'outTemp' => 68.0, 'windSpeed' => 10.0]);
        self::assertSame(20.0, $us['temp']);
        self::assertSame(4.5, $us['wind']);
    }

    public function testABatchIsOneRequestWithTheKeyInThePath(): void
    {
        $http = (new FakeHttpClient())->answer(200, 'SUCCESS');
        $upload = new Windy(Uploads::config(Kind::Windy, ['api_key' => 'k3y']), $http);
        $posted = $upload->post([Uploads::metricRecord(['dateTime' => 100]), Uploads::metricRecord(['dateTime' => 200])]);

        self::assertSame(2, $posted->sent);
        self::assertSame(200, $posted->through);
        $request = $http->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://stations.windy.com/pws/update/k3y', $request->url);
        self::assertSame('application/json', $request->headers['Content-Type']);
        $body = json_decode((string) $request->body, true);
        self::assertIsArray($body);
        $observations = $body['observations'] ?? null;
        self::assertTrue(is_array($observations) && count($observations) === 2, 'two observations in one request');
        self::assertStringContainsString('"pressure":101325.0', (string) $request->body);
    }

    public function testRefusalsAreTransientUnlessTheKeyIsWrong(): void
    {
        $upload = new Windy(Uploads::config(Kind::Windy, ['api_key' => 'k3y']), (new FakeHttpClient())->answer(503, 'later'));
        $posted = $upload->post([Uploads::metricRecord()]);
        self::assertSame(0, $posted->sent);
        self::assertSame([[1_787_734_200, 'Windy answered 503: later']], $posted->failures);

        $wrong = new Windy(Uploads::config(Kind::Windy, ['api_key' => 'k3y']), (new FakeHttpClient())->answer(401, 'nope')->answer(401, 'nope'));
        try {
            $wrong->post([Uploads::metricRecord()]);
            self::fail('a wrong key is permanent');
        } catch (Rejected $error) {
            self::assertTrue($error->permanent);
        }
        self::assertStringStartsWith('refused: ', $wrong->check());
        self::assertSame(['host' => 'stations.windy.com', 'station' => 0], $wrong->describe());

        $this->expectException(UploadError::class);
        new Windy(Uploads::config(Kind::Windy, ['api_key' => ' ']), new FakeHttpClient());
    }
}
