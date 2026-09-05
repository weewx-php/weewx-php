<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Trigger;

final class UploadConfigTest extends TestCase
{
    private const HEAD = "[Stations]\n[[ecowitt]]\n[Archives]\n[[kirchdorf]]\nprimary = ecowitt\n";

    public function testReadsEveryKindWithItsOwnDefaults(): void
    {
        $config = ConfigReader::read(ConfFile::parse(self::HEAD . implode("\n", [
            '[Uploads]',
            '    [[wu]]',
            '        kind = wunderground',
            '        station = IBAYERN123',
            '        password = "sec ret"',
            '        indoor = true',
            '    [[cwop]]',
            '        kind = cwop',
            '        station = dw1234',
            '        servers = cwop.aprs.net:14580',
            '    [[broker]]',
            '        kind = mqtt',
            '        host = mqtt.example.org',
            '        qos = 1',
            '        tls = yes',
            '        catch_up = 0',
            '    [[influx]]',
            '        kind = influx',
            '        url = http://influxdb:8086',
            '        bucket = weewx',
            '        token = "t0k"',
            '        catch_up = 20000',
            '        trigger = interval',
            '        every = 1h',
            '    [[windy]]',
            '        kind = windy',
            '        api_key = "k"',
            '        station = 2',
            '        colour = blue',
            '',
        ])), '/srv');

        self::assertSame(['wu', 'cwop', 'broker', 'influx', 'windy'], array_keys($config->uploads));

        $wu = $config->uploads['wu'];
        self::assertSame(Kind::Wunderground, $wu->kind);
        self::assertSame('kirchdorf', $wu->archive);
        self::assertSame(Trigger::Record, $wu->trigger);
        self::assertSame(12, $wu->catchUp);
        self::assertSame(10, $wu->timeout);
        self::assertSame(900, $wu->stale);
        self::assertSame('IBAYERN123', $wu->text('station'));
        self::assertSame('sec ret', $wu->text('password'));
        self::assertTrue($wu->flag('indoor'));

        $cwop = $config->uploads['cwop'];
        self::assertSame(Trigger::Interval, $cwop->trigger);
        self::assertSame(600, $cwop->every);
        self::assertSame(0, $cwop->catchUp);
        self::assertSame(600, $cwop->stale);
        self::assertSame('-1', $cwop->text('passcode'));
        self::assertNull($cwop->float('latitude'));
        self::assertSame(['cwop.aprs.net:14580'], $cwop->list('servers'));

        $broker = $config->uploads['broker'];
        self::assertSame(Trigger::Live, $broker->trigger);
        self::assertSame('1', $broker->text('qos'));
        self::assertTrue($broker->flag('tls'));
        self::assertTrue($broker->flag('tls_verify'));
        self::assertSame('weather', $broker->text('topic'));
        self::assertNull($broker->int('port'));
        self::assertSame(60, $broker->int('keepalive'));

        $influx = $config->uploads['influx'];
        self::assertSame(20000, $influx->catchUp);
        self::assertSame(30, $influx->timeout);
        self::assertSame(3600, $influx->every);
        self::assertSame('v2', $influx->text('api'));
        self::assertSame('METRICWX', $influx->text('unit_system'));

        self::assertSame(2, $config->uploads['windy']->int('station'));
        self::assertSame(['[Uploads][[windy]] colour: unknown setting, ignored'], $config->warnings);
    }

    public function testAnUploadWithoutArchivesOrUploadsIsNothing(): void
    {
        $config = ConfigReader::read(ConfFile::parse(self::HEAD), '/srv');
        self::assertSame([], $config->uploads);
        self::assertNull($config->upload('wu'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenUploads(): iterable
    {
        yield 'no kind' => ["[Uploads]\n[[x]]\nstation = a", '[Uploads][[x]] kind: is needed: one of wunderground'];
        yield 'unknown kind' => ["[Uploads]\n[[x]]\nkind = pigeon", 'expected one of wunderground'];
        yield 'missing station' => ["[Uploads]\n[[x]]\nkind = wunderground\npassword = p", '[Uploads][[x]] station: is needed for a wunderground upload'];
        yield 'empty password' => ["[Uploads]\n[[x]]\nkind = pwsweather\nstation = a\npassword = \"\"", 'must not be empty'];
        yield 'live for a weather service' => ["[Uploads]\n[[x]]\nkind = windy\napi_key = k\ntrigger = live", 'live is for mqtt only'];
        yield 'unknown archive' => ["[Uploads]\n[[x]]\nkind = windy\napi_key = k\narchive = shed", 'shed is not an archive'];
        yield 'catch-up too high' => ["[Uploads]\n[[x]]\nkind = windy\napi_key = k\ncatch_up = 289", 'must be between 0 and 288'];
        yield 'timeout too long' => ["[Uploads]\n[[x]]\nkind = windy\napi_key = k\ntimeout = 5m", 'must be between 1 and 60 seconds'];
        yield 'bad choice' => ["[Uploads]\n[[x]]\nkind = influx\nurl = http://a\nbucket = b\napi = v3", 'expected one of v2, v1'];
        yield 'bad flag' => ["[Uploads]\n[[x]]\nkind = mqtt\nhost = h\nretain = maybe", '[Uploads][[x]] retain'];
    }

    /** @dataProvider brokenUploads */
    public function testRefusesWhatDoesNotHoldTogether(string $text, string $message): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage($message);
        ConfigReader::read(ConfFile::parse(self::HEAD . $text . "\n"), '/srv');
    }

    public function testTwoArchivesNeedTheUploadToNameOne(): void
    {
        $two = self::HEAD . "[[shed]]\nsenders = *\n";
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('[Uploads][[x]] archive: is needed: there are 2 archives');
        ConfigReader::read(ConfFile::parse($two . "[Uploads]\n[[x]]\nkind = windy\napi_key = k\n"), '/srv');
    }
}
