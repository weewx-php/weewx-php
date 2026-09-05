<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Ingest\NativeParser;
use WeewxPhp\Ingest\Rejected;
use WeewxPhp\Ingest\SensorSource;

final class SensorSourceTest extends TestCase
{
    private const RECEIVER = '11111111-1111-4111-8111-111111111111';

    /** @return array<string, string> */
    private function source(string $type): array
    {
        return ['type' => $type, 'receiver_id' => self::RECEIVER, 'model' => 'Test',
            'sensor_id' => 'aabbccddeeff/0000002a', 'channel' => '1'];
    }

    /** @param array<string, mixed> $changes */
    private function body(string $type, array $changes = []): string
    {
        $source = $this->source($type);
        $packet = array_replace(['station_id' => SensorSource::stationId($source),
            'event_id' => '22222222-2222-4222-8222-222222222222', 'kind' => 'loop',
            'driver_module' => SensorSource::MODULES[$type], 'dateTime' => 1788609600,
            'usUnits' => 17, 'data' => ['outTemp' => 20], 'source' => $source], $changes);
        return json_encode(['version' => 3, 'collector_id' => self::RECEIVER,
            'packets' => [$packet]], JSON_THROW_ON_ERROR);
    }

    public function testIdentityVectorsMatchPythonForEverySource(): void
    {
        $vectors = ['gw1000' => 'b3000eb6-1ea9-502e-b48d-409e27bde747',
            'weatherflow_udp' => '9fb5357d-1567-534e-a82b-5e7f117ebe5f',
            'rtl_433' => 'ca9fe46c-5bc4-53aa-9bba-b75b9dd20a98'];
        foreach ($vectors as $type => $station) {
            self::assertSame($station, SensorSource::stationId($this->source($type)));
            self::assertCount(1, NativeParser::parse($this->body($type))['events']);
        }
    }

    public function testInvalidSourcesAndModuleBindingsAreRejected(): void
    {
        foreach (array_keys(SensorSource::MODULES) as $type) {
            foreach ([
                ['source' => array_replace($this->source($type), ['channel' => '2'])],
                ['source' => array_replace($this->source($type), ['type' => 'unsupported'])],
                ['source' => array_replace($this->source($type), ['type' => []])],
                ['source' => array_replace($this->source($type), ['token' => 'injected'])],
                ['driver_module' => 'weewx.drivers.simulator'],
                ['kind' => 'archive', 'interval' => 5],
                ['source' => null],
            ] as $changes) {
                try {
                    NativeParser::parse($this->body($type, $changes));
                    self::fail('Invalid source accepted');
                } catch (Rejected $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
            $body = json_decode($this->body($type), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertIsArray($body['packets']);
            self::assertIsArray($body['packets'][0]);
            unset($body['packets'][0]['source']);
            try {
                NativeParser::parse(json_encode($body, JSON_THROW_ON_ERROR));
                self::fail('Missing source accepted');
            } catch (Rejected $exception) {
                self::assertSame('sensor_source_required', $exception->getMessage());
            }
        }
    }
}
