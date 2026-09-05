<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Ingest\Http;
use WeewxPhp\Ingest\Parser;
use WeewxPhp\Ingest\Protocol;
use WeewxPhp\Ingest\Rejected;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

final class ParserTest extends TestCase
{
    public function testCapturedHp2561UploadIncludesProbeTemperaturesAndRainCounters(): void
    {
        $body = file_get_contents(dirname(__DIR__, 2) . '/uploads/hp2561ae_pro.txt');
        self::assertNotFalse($body);
        $observation = Parser::observation(Protocol::Ecowitt, Parser::form(trim($body)), 1787656002);
        self::assertSame(59.7, $observation->data['outTemp']);
        self::assertSame(0.02, $observation->data['dayRain']);
        self::assertArrayNotHasKey('rain', $observation->data);
        self::assertSame(66.2, $observation->data['extraTemp9']);
        self::assertSame(65.7, $observation->data['soilTemp1']);
        self::assertSame(1.62, $observation->data['wn34_ch1_batt']);
        $metric = Units::toSystem($observation->data + ['usUnits' => $observation->units->value], UnitSystem::METRICWX);
        self::assertEqualsWithDelta(19.0, $metric['extraTemp9'], 0.00001);
        self::assertEqualsWithDelta(0.508, $metric['dayRain'], 0.00001);
        self::assertArrayNotHasKey('PASSKEY', $observation->data);
        self::assertStringContainsString('soil_ec1=60', $observation->sample);
    }

    public function testMetricObserverDoesNotTurnUvIrradianceIntoAnIndex(): void
    {
        $body = file_get_contents(dirname(__DIR__, 2) . '/uploads/wunderground/observer_metric.txt');
        self::assertNotFalse($body);
        $fields = Parser::form(trim($body));
        $fields['dailyrain'] = '10';
        $fields['windspeed'] = '3.6';
        $observation = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertSame(UnitSystem::METRIC, $observation->units);
        self::assertSame(1.4, $observation->data['outTemp']);
        self::assertSame(1.0, $observation->data['dayRain']);
        self::assertSame(0.38, $observation->data['uvradiation']);
        self::assertArrayNotHasKey('UV', $observation->data);
        $mps = Parser::observation(Protocol::Wunderground, $fields, 1788609600, 'mps');
        self::assertSame(UnitSystem::METRICWX, $mps->units);
        self::assertSame(10.0, $mps->data['dayRain']);
        self::assertFalse($observation->deviceTime);
        self::assertSame(1788609600, $observation->timestamp);
    }

    public function testMissingAndNonFiniteValuesDoNotEnterMeasurements(): void
    {
        $fields = Parser::form('ID=X&PASSWORD=secret&tempf=-9999&humidity=50&UV=1e999&rainin=0');
        $obs = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertNull($obs->data['outTemp']);
        self::assertArrayNotHasKey('UV', $obs->data);
        self::assertSame(0.0, $obs->data['hourRain']);
        self::assertStringNotContainsString('secret', $obs->sample);
    }

    public function testStationPressureFirmwareIsRecognized(): void
    {
        $fields = Parser::form('ID=X&PASSWORD=secret&baromin=29.92&softwaretype=WH2600GEN_V2.2.5');
        $obs = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertSame(29.92, $obs->data['pressure']);
        self::assertArrayNotHasKey('barometer', $obs->data);
    }

    public function testValidDeviceTimeSurvivesAndFutureTimeUsesReception(): void
    {
        $fields = Parser::form('ID=X&PASSWORD=secret&tempf=68&dateutc=' . urlencode(gmdate('Y-m-d H:i:s', 1788609500)));
        $obs = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertTrue($obs->deviceTime);
        self::assertSame(1788609500, $obs->timestamp);
        self::assertSame(1788609500, $obs->reportedTimestamp);
        self::assertSame('device', $obs->timeReason);
        $fields['dateutc'] = '2099-01-01 00:00:00';
        $future = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertFalse($future->deviceTime);
        self::assertSame(4070908800, $future->reportedTimestamp);
        self::assertSame('out_of_range', $future->timeReason);
        $fields['dateutc'] = 'invalid';
        $invalid = Parser::observation(Protocol::Wunderground, $fields, 1788609600);
        self::assertNull($invalid->reportedTimestamp);
        self::assertSame('invalid', $invalid->timeReason);
        $fields['dateutc'] = 'now';
        self::assertSame('server', Parser::observation(Protocol::Wunderground, $fields, 1788609600)->timeReason);
    }

    /** @dataProvider malformedForms */
    public function testMalformedFormsAreRejected(string $body): void
    {
        $this->expectException(Rejected::class);
        Parser::form($body);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedForms(): iterable
    {
        yield 'duplicate' => ['ID=a&ID=b'];
        yield 'encoded duplicate' => ['PASSWORD=a&%50ASSWORD=b'];
        yield 'array' => ['ID[]=a'];
        yield 'control' => ['ID=a%0Ab'];
        yield 'escape' => ['ID=%xy'];
        yield 'utf8' => ['ID=%FF'];
        yield 'length' => ['x=' . str_repeat('a', 65536)];
    }

    public function testUntrustedForwardingHeadersCannotFakeTlsOrSource(): void
    {
        $server = ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertSame(['192.0.2.1', false], Http::connection($server, new IngestConfig()));
        self::assertSame(['127.0.0.1', true], Http::connection($server, new IngestConfig(trustedProxies: ['192.0.2.1'])));
        $server['HTTP_X_FORWARDED_FOR'] = '127.0.0.1, 192.0.2.2';
        self::assertSame(['192.0.2.1', false], Http::connection($server, new IngestConfig(trustedProxies: ['192.0.2.1'])));
    }

    public function testTlsToTheProxyDoesNotMakeTheConsoleConnectionSecure(): void
    {
        $server = ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.2', 'HTTP_X_FORWARDED_PROTO' => 'http'];
        $config = new IngestConfig(trustedProxies: ['192.0.2.1']);
        self::assertSame(['192.0.2.2', false], Http::connection($server, $config));
        $server['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertSame(['192.0.2.2', true], Http::connection($server, $config));
        $server['HTTP_X_FORWARDED_FOR'] = '192.0.2.2, 192.0.2.3';
        self::assertSame(['192.0.2.1', false], Http::connection($server, $config));
        unset($server['HTTP_X_FORWARDED_FOR']);
        self::assertSame(['192.0.2.1', false], Http::connection($server, $config));
    }
}
