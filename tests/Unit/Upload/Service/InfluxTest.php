<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Influx;
use WeewxPhp\Upload\UploadError;

final class InfluxTest extends TestCase
{
    public function testARecordIsOneLineOfFloatsSortedByNameAndTagged(): void
    {
        $upload = $this->influx(['location' => 'Kirchdorf an der Amper']);
        $line = $upload->line(['dateTime' => 1_787_734_200, 'usUnits' => 17, 'interval' => 5, 'outTemp' => 20.0, 'rain' => 0, 'windDir' => 180, 'model' => 'HP2561', 'ET' => NAN, 'zero' => 0.5]);
        self::assertSame('weather,location=Kirchdorf\ an\ der\ Amper interval=5.0,outTemp=20.0,rain=0.0,windDir=180.0,zero=0.5 1787734200', $line);

        // Converted into the system that was set: a US record comes out metric.
        $metric = $this->influx()->line(['dateTime' => 1_787_734_200, 'usUnits' => 1, 'outTemp' => 68.0, 'rain' => 1.0]);
        self::assertSame('weather outTemp=20.0,rain=25.4 1787734200', $metric);
        $us = $this->influx(['unit_system' => 'US'])->line(['dateTime' => 1_787_734_200, 'usUnits' => 17, 'outTemp' => 20.0]);
        self::assertSame('weather outTemp=68.0 1787734200', $us);

        // Nothing to say: no line, rather than a line the far end refuses.
        self::assertNull($this->influx()->line(['dateTime' => 1_787_734_200, 'usUnits' => 17]));
        self::assertNull($this->influx()->line(['usUnits' => 17, 'outTemp' => 1.0]));
        self::assertSame('a\,b\=c\ d', Influx::tag('a,b=c d\\'));
        self::assertSame('a\,b=c\ d', Influx::measurement('a,b=c d'));
    }

    public function testWritesInBatchesWithTheApiTheServerSpeaks(): void
    {
        $http = (new FakeHttpClient())->answer(204)->answer(204);
        $upload = $this->influx(['org' => 'home'], $http, batch: 2);
        $posted = $upload->post([Uploads::metricRecord(['dateTime' => 100]), Uploads::metricRecord(['dateTime' => 200]), ['dateTime' => 300, 'usUnits' => 17]]);

        self::assertSame(2, $posted->sent);
        self::assertSame(1, $posted->skipped);
        self::assertSame(200, $posted->through);
        self::assertCount(1, $http->requests);
        $request = $http->last();
        self::assertSame('http://influxdb:8086/api/v2/write?bucket=weewx&precision=s&org=home', $request->url);
        self::assertSame('Token t0k', $request->headers['Authorization']);
        self::assertSame('text/plain; charset=utf-8', $request->headers['Content-Type']);
        self::assertSame(2, substr_count((string) $request->body, "\n") + 1);
        self::assertSame(30, $request->timeout);

        $one = $this->influx(['api' => 'v1', 'token' => '', 'username' => 'u', 'password' => 'p w', 'url' => 'https://db.example.org:8443/influx/']);
        self::assertSame('https://db.example.org:8443/influx/write?db=weewx&precision=s&u=u&p=p%20w', $one->writeUrl());
    }

    public function testTheAnswersThatMeanStopAndTheOnesThatMeanLater(): void
    {
        $later = $this->influx([], (new FakeHttpClient())->answer(500, 'busy'));
        $posted = $later->post([Uploads::metricRecord()]);
        self::assertSame([[1_787_734_200, 'InfluxDB answered 500: busy']], $posted->failures);

        foreach ([401, 404] as $status) {
            $stop = $this->influx([], (new FakeHttpClient())->answer($status, 'no'));
            try {
                $stop->post([Uploads::metricRecord()]);
                self::fail((string) $status . ' is permanent');
            } catch (Rejected $error) {
                self::assertTrue($error->permanent);
            }
        }
        $bad = $this->influx([], (new FakeHttpClient())->answer(400, 'field type conflict: outTemp'));
        self::assertStringContainsString('field type conflict', $bad->post([Uploads::metricRecord()])->failures[0][1]);

        $checked = $this->influx([], $http = (new FakeHttpClient())->answer(204));
        self::assertSame('InfluxDB accepted the credentials and the bucket weewx.', $checked->check());
        self::assertSame('', $http->last()->body);
        self::assertSame(['host' => 'influxdb', 'api' => 'v2', 'bucket' => 'weewx', 'measurement' => 'weather', 'location' => '', 'units' => 'METRICWX'], $checked->describe());
    }

    public function testRefusesWhatCannotWork(): void
    {
        try {
            $this->influx(['url' => 'influxdb:8086']);
            self::fail('a bare host is not an address');
        } catch (UploadError $error) {
            self::assertStringContainsString('http://', $error->getMessage());
        }
        $this->expectException(UploadError::class);
        $this->influx(['token' => '']);
    }

    /** @param array<string, string|int|float|bool|list<string>|null> $options */
    private function influx(array $options = [], ?FakeHttpClient $http = null, int $batch = Influx::BATCH): Influx
    {
        $config = Uploads::config(Kind::Influx, $options + ['url' => 'http://influxdb:8086', 'bucket' => 'weewx', 'token' => 't0k'], timeout: 30);
        return new Influx($config, $http ?? new FakeHttpClient(), $batch);
    }
}
