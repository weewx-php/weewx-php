<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Config\ConfigReader;
use WeewxPhp\Ingest\Parser;
use WeewxPhp\Ingest\Protocol;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Runtime;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreDisabledAndTransportCanBeRestricted(): void
    {
        $config = ConfigReader::read(ConfFile::parse(''), '/no-ingest');
        self::assertFalse($config->ingest->enabled);
        self::assertSame('auto', $config->ingest->tickMode);
        self::assertSame(120, $config->ingest->senderRequestsPerMinute);
        $configured = ConfigReader::read(ConfFile::parse("[Ingest]\nenabled = true\npublic_url = https://weather.example\nhttp_wunderground = false\ntrusted_proxies = 127.0.0.1\n"), '/no-ingest');
        self::assertTrue($configured->ingest->enabled);
        self::assertTrue($configured->ingest->httpEcowitt);
        self::assertFalse($configured->ingest->httpWunderground);
        self::assertSame(['127.0.0.1'], $configured->ingest->trustedProxies);
        self::assertSame([], $configured->warnings);
    }

    public function testAdoptedSendersCanBeSelectedWithoutAStationsSection(): void
    {
        $dir = TempDir::create('ingest-config');
        $path = $dir . '/weather.conf';
        file_put_contents($path, "data_dir = data\n[Ingest]\nenabled = true\n");
        $runtime = Runtime::boot($path);
        try {
            $keys = $runtime->ingest()->credentials();
            $obs = Parser::observation(Protocol::Wunderground, ['ID' => 'device', 'tempf' => '68'], time());
            $sender = $runtime->ingest()->receive($obs, $keys['wunderground'], '192.0.2.1', true, time(), 100, static fn(): bool => false);
            self::assertSame([], Config::load($path)->stations);
            $runtime->ingest()->adopt($sender->id, 'Garden', time());
            file_put_contents($path, "\n[Archives]\n[[garden]]\nprimary = " . $sender->id . "\nsenders = " . $sender->id . "\n", FILE_APPEND);
            $config = Config::load($path);
            self::assertSame('Garden', $config->stations[$sender->id]->name);
            self::assertSame($sender->id, $config->archives['garden']->primary);
        } finally {
            $runtime->close();
            TempDir::remove($dir);
        }
    }

    public function testInvalidProxyFailsConfiguration(): void
    {
        $this->expectException(ConfigError::class);
        ConfigReader::read(ConfFile::parse("[Ingest]\ntrusted_proxies = *\n"), '/no-ingest');
    }

    public function testExternalTickAndSenderLimitsAreValidated(): void
    {
        $config = ConfigReader::read(ConfFile::parse("[Ingest]\ntick_mode = external\nsender_requests_per_minute = 80\n"), '/no-ingest');
        self::assertSame('external', $config->ingest->tickMode);
        self::assertSame(80, $config->ingest->senderRequestsPerMinute);
        self::assertSame([], $config->warnings);
        $this->expectException(ConfigError::class);
        ConfigReader::read(ConfFile::parse("[Ingest]\ntick_mode = inline\n"), '/no-ingest');
    }

    public function testSenderLimitMustBePositive(): void
    {
        $this->expectException(ConfigError::class);
        ConfigReader::read(ConfFile::parse("[Ingest]\nsender_requests_per_minute = 0\n"), '/no-ingest');
    }
}
