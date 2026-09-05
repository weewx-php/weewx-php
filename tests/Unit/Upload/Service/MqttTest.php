<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload\Service;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\FakeConnection;
use WeewxPhp\Tests\Support\FakeSocketFactory;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\HomeAssistant;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Mqtt\Client;
use WeewxPhp\Upload\Mqtt\Wire;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Service\Mqtt;
use WeewxPhp\Upload\UploadError;

final class MqttTest extends TestCase
{
    private const CONNACK_OK = "\x20\x02\x00\x00";

    public function testTheWireFormatIsTheSpecifications(): void
    {
        self::assertSame("\x00", Wire::encodeLength(0));
        self::assertSame("\x7f", Wire::encodeLength(127));
        self::assertSame("\x80\x01", Wire::encodeLength(128));
        self::assertSame("\xff\xff\xff\x7f", Wire::encodeLength(268_435_455));
        self::assertSame("\x00\x04MQTT", Wire::encodeString('MQTT'));

        // CONNECT: protocol name and level, clean session with a password, keepalive 60, the client id and the credentials.
        $connect = Wire::connect('weewx', 'user', 'pw', 60);
        self::assertSame("\x10" . chr(6 + 1 + 1 + 2 + 7 + 6 + 4) . "\x00\x04MQTT\x04\xc2\x00\x3c\x00\x05weewx\x00\x04user\x00\x02pw", $connect);
        self::assertSame("\x10\x11\x00\x04MQTT\x04\x02\x00\x0a\x00\x05weewx", Wire::connect('weewx', '', '', 10));

        // PUBLISH at QoS 0 retained, and at QoS 1 with a packet id.
        self::assertSame("\x31\x0d\x00\x07weather20.0", Wire::publish('weather', '20.0', 0, true, 0));
        self::assertSame("\x32\x0f\x00\x07weather\x00\x07" . '20.0', Wire::publish('weather', '20.0', 1, false, 7));
        self::assertSame("\xe0\x00", Wire::disconnect());

        $connection = new FakeConnection("\x40\x02\x00\x07");
        self::assertSame([Wire::PUBACK, 0, "\x00\x07"], Wire::read($connection));
    }

    public function testAQosOnePublishWaitsForItsOwnAcknowledgement(): void
    {
        // The broker answers CONNACK, then a PINGRESP and a PUBACK for somebody else's id before ours.
        $connection = new FakeConnection(self::CONNACK_OK . "\xd0\x00" . "\x40\x02\x00\x09" . "\x40\x02\x00\x01");
        $client = $this->client((new FakeSocketFactory())->queue($connection));

        $client->publish('weather/loop', '{}', 1, true);
        self::assertStringStartsWith("\x10", $connection->written);
        self::assertStringContainsString("\x33\x12\x00\x0cweather/loop\x00\x01{}", $connection->written);
        $client->close();
        self::assertStringEndsWith("\xe0\x00", $connection->written);
        self::assertTrue($connection->closed);

        $silent = new FakeConnection(self::CONNACK_OK);
        $client = $this->client((new FakeSocketFactory())->queue($silent));
        $this->expectException(Rejected::class);
        $client->publish('weather/loop', '{}', 1, false);
    }

    public function testARefusedPasswordIsPermanentAndABusyBrokerIsNot(): void
    {
        $client = $this->client((new FakeSocketFactory())->queue(new FakeConnection("\x20\x02\x00\x04")));
        try {
            $client->connect();
            self::fail('code 4 is a bad password');
        } catch (Rejected $error) {
            self::assertTrue($error->permanent);
            self::assertStringContainsString('bad user name or password', $error->getMessage());
        }
        $client = $this->client((new FakeSocketFactory())->queue(new FakeConnection("\x20\x02\x00\x03")));
        try {
            $client->connect();
            self::fail('code 3 is a busy broker');
        } catch (Rejected $error) {
            self::assertFalse($error->permanent);
        }
    }

    public function testTheMessageCarriesUnitsInItsNames(): void
    {
        $upload = $this->mqtt(['unit_system' => 'US']);
        $message = $upload->message(['dateTime' => 1_787_734_200, 'usUnits' => 17, 'interval' => 5, 'outTemp' => 20.0, 'outHumidity' => 61.0, 'windDir' => 180.0, 'windSpeed' => 5.0, 'barometer' => 1013.25, 'UV' => 4.0, 'model' => 'HP2561', 'rain' => null]);

        self::assertSame(1_787_734_200, $message['dateTime']);
        self::assertSame(68.0, $message['outTemp_F']);
        self::assertSame(61.0, $message['outHumidity']);
        self::assertSame(180.0, $message['windDir']);
        self::assertArrayHasKey('windSpeed_mph', $message);
        self::assertArrayHasKey('barometer_inHg', $message);
        self::assertSame(4.0, $message['UV']);
        self::assertSame('HP2561', $message['model']);
        self::assertArrayNotHasKey('interval', $message);
        self::assertArrayNotHasKey('usUnits', $message);
        self::assertArrayNotHasKey('rain', $message);

        // As the archive holds it, and without suffixes.
        $plain = $this->mqtt(['append_units' => false])->message(['dateTime' => 1, 'usUnits' => 17, 'outTemp' => 20.0]);
        self::assertSame(['dateTime' => 1, 'outTemp' => 20.0], $plain);
        self::assertSame('outTemp_C', Mqtt::topicName('outTemp', 'degree_C', true));
        self::assertSame('windSpeed_mps', Mqtt::topicName('windSpeed', 'meter_per_second', true));
        self::assertSame('radiation_Wpm2', Mqtt::topicName('radiation', 'watt_per_meter_squared', true));
        self::assertSame('soilMoist1_centibar', Mqtt::topicName('soilMoist1', 'centibar', true));
    }

    public function testPublishesEachReadingAndTheDocumentRetained(): void
    {
        $connection = new FakeConnection(self::CONNACK_OK);
        $sockets = (new FakeSocketFactory())->queue($connection);
        $upload = $this->mqtt([], $sockets);
        $posted = $upload->post([Uploads::metricRecord(['dateTime' => 100]), ['dateTime' => 200, 'usUnits' => 17, 'outTemp' => 20.5, 'windDir' => 180.0]]);

        self::assertSame(1, $posted->skipped);
        self::assertSame(200, $posted->through);
        // dateTime, outTemp_C, windDir, and the document.
        self::assertSame(4, $posted->sent);
        self::assertStringContainsString("\x31\x17\x00\x11weather/outTemp_C20.5", $connection->written);
        self::assertStringContainsString("\x00\x0fweather/windDir180.0", $connection->written);
        self::assertStringContainsString("\x00\x0cweather/loop" . '{"dateTime":200,"outTemp_C":20.5,"windDir":180.0}', $connection->written);
        self::assertStringEndsWith("\xe0\x00", $connection->written);
        self::assertSame([['host' => 'mqtt.example.org', 'port' => 1883, 'tls' => false, 'verify' => true, 'timeout' => 10]], $sockets->opened);
        self::assertSame(['host' => 'mqtt.example.org', 'port' => 1883, 'topic' => 'weather'], $upload->describe());

        $lost = $this->mqtt([], (new FakeSocketFactory())->queue(new Rejected('unreachable')));
        $failed = $lost->post([['dateTime' => 300, 'usUnits' => 17, 'outTemp' => 1.0]]);
        self::assertSame([[300, 'unreachable']], $failed->failures);
        self::assertNull($failed->through);
    }

    public function testAnnouncesToHomeAssistantWhenAskedAndRetained(): void
    {
        $upload = $this->mqtt(['home_assistant' => true, 'individual' => false]);
        $record = ['dateTime' => 1, 'usUnits' => 17, 'outTemp' => 20.0, 'dayRain' => 1.5];
        self::assertCount(1, $upload->messages($record));

        $upload->announce();
        $messages = $upload->messages($record);
        self::assertCount(3, $messages);
        [$topic, $payload, $retained] = $messages[0];
        self::assertSame('homeassistant/sensor/weewx_php_kirchdorf_an_der_amper/outTemp/config', $topic);
        self::assertTrue($retained);
        $definition = json_decode($payload, true);
        self::assertIsArray($definition);
        self::assertSame('Out temp', $definition['name']);
        self::assertSame('weather/loop', $definition['state_topic']);
        self::assertSame("{{ value_json.outTemp_C | default('', true) }}", $definition['value_template']);
        self::assertSame('temperature', $definition['device_class']);
        self::assertSame('°C', $definition['unit_of_measurement']);
        self::assertSame('measurement', $definition['state_class']);
        self::assertSame(3600, $definition['expire_after']);
        $total = json_decode($messages[1][1], true);
        self::assertIsArray($total);
        self::assertSame('total_increasing', $total['state_class']);
        self::assertArrayNotHasKey('expire_after', $total);
        // A daily total is not a reading with a class of its own; weewx-evo leaves it without one too.
        self::assertArrayNotHasKey('device_class', $total);
        self::assertSame('mm', $total['unit_of_measurement']);

        self::assertSame('Wind gust dir', HomeAssistant::readable('windGustDir'));
        self::assertSame('Soil temp1', HomeAssistant::readable('soilTemp1'));
        self::assertSame('wind_speed', HomeAssistant::deviceClass('windGust'));
        self::assertNull(HomeAssistant::deviceClass('lightning_num'));
        self::assertSame('hp2561_kirchdorf', HomeAssistant::slug('HP2561 Kirchdorf!'));
    }

    public function testCheckPublishesAStatusAndNeedsAHostAndSomethingToPublish(): void
    {
        $connection = new FakeConnection(self::CONNACK_OK);
        $upload = $this->mqtt(['tls' => true, 'port' => 8884], (new FakeSocketFactory())->queue($connection));
        self::assertSame('connected to mqtt.example.org:8884 and published to weather/ as individual topics and a JSON document.', $upload->check());
        self::assertStringContainsString("\x00\x0eweather/statusweewx-php", $connection->written);

        try {
            $this->mqtt(['aggregate' => false, 'individual' => false]);
            self::fail('nothing to publish is a mistake');
        } catch (UploadError $error) {
            self::assertStringContainsString('publish nothing', $error->getMessage());
        }
        $this->expectException(UploadError::class);
        $this->mqtt(['host' => ' ']);
    }

    private function client(FakeSocketFactory $sockets): Client
    {
        return new Client($sockets, 'mqtt.example.org', 1883, 'weewx', '', '', false, true, 60, 10);
    }

    /** @param array<string, string|int|float|bool|list<string>|null> $options */
    private function mqtt(array $options = [], ?FakeSocketFactory $sockets = null): Mqtt
    {
        $config = Uploads::config(Kind::Mqtt, $options + ['host' => 'mqtt.example.org', 'client_id' => 'weewx']);
        return new Mqtt($config, 'Kirchdorf an der Amper', $sockets ?? new FakeSocketFactory());
    }
}
