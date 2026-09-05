<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\HomeAssistant;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Mqtt\Client;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Upload\UploadError;
use WeewxPhp\Version;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Readings to an MQTT broker: what makes a page come alive. Belchertown,
 * jas, weewx-wdc and Weather34 subscribe to a broker over websockets and
 * redraw as messages arrive; without one they render and then sit frozen.
 *
 * The topic layout is `matthewwall/weewx-mqtt`'s, because that is what
 * those skins were written against: each reading to `<topic>/<name>`,
 * the name carrying a unit suffix, `outTemp_C`, from a table in which
 * `degree_compass`, `percent` and `uv_index` deliberately stay bare; and
 * the whole record as one JSON document to `<topic>/loop`, because one
 * subscription is cheaper than forty. Retained, by default, so a browser
 * gets the current conditions the moment it subscribes.
 *
 * Backfilling to a broker is meaningless: a retained message is "what it
 * is like now", and replaying an hour into it leaves the last of the hour
 * showing as current. Only the newest goes.
 */
final class Mqtt implements Upload
{
    /**
     * Unit names shortened for a topic, from weewx-mqtt's `UNIT_REDUCTIONS`.
     * Null means no suffix at all: `outHumidity_percent` is not a topic any
     * skin subscribes to.
     *
     * @var array<string, string|null>
     */
    public const UNIT_SUFFIX = [
        'degree_F' => 'F',
        'degree_C' => 'C',
        'inch' => 'in',
        'mile_per_hour' => 'mph',
        'mile_per_hour2' => 'mph',
        'km_per_hour' => 'kph',
        'km_per_hour2' => 'kph',
        'knot' => 'knot',
        'knot2' => 'knot2',
        'meter_per_second' => 'mps',
        'meter_per_second2' => 'mps',
        'degree_compass' => null,
        'watt_per_meter_squared' => 'Wpm2',
        'uv_index' => null,
        'percent' => null,
        'unix_epoch' => null,
    ];

    /** Never published: `usUnits` is the archive's business, and the unit is in the name instead. */
    private const NEVER = ['usUnits', 'interval'];

    private readonly Client $client;

    private readonly string $topic;

    private readonly ?UnitSystem $system;

    private readonly bool $appendUnits;

    private readonly bool $aggregate;

    private readonly bool $individual;

    private readonly bool $retain;

    private readonly int $qos;

    private readonly bool $homeAssistant;

    private readonly string $discoveryPrefix;

    private readonly string $station;

    private bool $announce = false;

    /**
     * @param string $stationName What Home Assistant calls the device when the upload names none.
     */
    public function __construct(UploadConfig $config, string $stationName, SocketFactory $sockets)
    {
        $host = trim($config->text('host'));
        if ($host === '') {
            throw new UploadError('MQTT needs a broker host');
        }
        $topic = trim($config->text('topic'), '/');
        $this->topic = $topic === '' ? 'weather' : $topic;
        $system = $config->text('unit_system');
        $this->system = $system === '' ? null : UnitSystem::fromName($system);
        $this->appendUnits = $config->flag('append_units');
        $this->aggregate = $config->flag('aggregate');
        $this->individual = $config->flag('individual');
        $this->retain = $config->flag('retain');
        $this->qos = $config->text('qos') === '1' ? 1 : 0;
        $this->homeAssistant = $config->flag('home_assistant');
        $prefix = trim($config->text('discovery_prefix'), '/');
        $this->discoveryPrefix = $prefix === '' ? 'homeassistant' : $prefix;
        $named = trim($config->text('station'));
        $this->station = $named === '' ? $stationName : $named;
        if (!$this->aggregate && !$this->individual) {
            throw new UploadError('MQTT with neither a JSON document nor individual topics would publish nothing');
        }
        $tls = $config->flag('tls');
        $clientId = trim($config->text('client_id'));
        $this->client = new Client(
            $sockets,
            $host,
            $config->int('port') ?? ($tls ? Client::DEFAULT_TLS_PORT : Client::DEFAULT_PORT),
            $clientId === '' ? sprintf('%s-%06x', Version::NAME, time() & 0xFFFFFF) : $clientId,
            $config->text('username'),
            $config->text('password'),
            $tls,
            $config->flag('tls_verify'),
            $config->int('keepalive') ?? 60,
            $config->timeout,
        );
    }

    public function kind(): Kind
    {
        return Kind::Mqtt;
    }

    /** Whether the readings' definitions for Home Assistant go out with the next post. */
    public function announces(): bool
    {
        return $this->homeAssistant;
    }

    /** Send the definitions for Home Assistant with the next post. */
    public function announce(): void
    {
        $this->announce = true;
    }

    /** What a reading is called on the broker. */
    public static function topicName(string $obs, ?string $unit, bool $appendUnits): string
    {
        if (!$appendUnits || $unit === null) {
            return $obs;
        }
        $suffix = array_key_exists($unit, self::UNIT_SUFFIX) ? self::UNIT_SUFFIX[$unit] : $unit;
        return $suffix === null ? $obs : $obs . '_' . $suffix;
    }

    /**
     * A record as the names and values that go on the broker. Converted
     * here rather than per field, so that one record is one unit system
     * throughout: a document with `outTemp_C` beside `dewpoint_F` is not a
     * bug anybody spots by reading it.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function message(array $record): array
    {
        $readings = new Readings($record);
        $stored = $readings->system();
        $wanted = $this->system ?? $stored;
        $shaped = [];
        foreach ($record as $obs => $value) {
            if (in_array($obs, self::NEVER, true) || $value === null) {
                continue;
            }
            if ($obs === 'dateTime') {
                $shaped['dateTime'] = $readings->timestamp();
                continue;
            }
            [$unit] = Units::unitOf($stored, $obs);
            [$target] = Units::unitOf($wanted, $obs);
            if ((is_int($value) || is_float($value)) && $unit !== null && $target !== null && $unit !== $target) {
                $converted = Units::convert($value, $unit, $target);
                if ($converted === null) {
                    continue;
                }
                $value = (float) $converted;
            }
            $shaped[self::topicName($obs, $target ?? $unit, $this->appendUnits)] = $value;
        }
        return $shaped;
    }

    /**
     * The messages one record becomes, as (topic, payload, retained), in the
     * order they go out: the definitions first when they are due, then the
     * readings, then the document.
     *
     * @param array<string, mixed> $record
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public function messages(array $record): array
    {
        $shaped = $this->message($record);
        $messages = [];
        if ($this->homeAssistant && $this->announce) {
            $readings = new Readings($record);
            $wanted = $this->system ?? $readings->system();
            foreach (array_keys($record) as $obs) {
                $obs = (string) $obs;
                if (in_array($obs, self::NEVER, true) || $obs === 'dateTime' || !$readings->has($obs)) {
                    continue;
                }
                [$unit] = Units::unitOf($wanted, $obs);
                $field = self::topicName($obs, $unit, $this->appendUnits);
                if (!array_key_exists($field, $shaped)) {
                    continue;
                }
                [$where, $payload] = HomeAssistant::discovery($obs, $unit, $this->topic, $field, $this->station, $this->discoveryPrefix);
                // Always retained, whatever `retain` says for the readings: a
                // definition nobody kept is one only a running Home Assistant saw.
                $messages[] = [$where, $payload, true];
            }
        }
        if ($this->individual) {
            foreach ($shaped as $name => $value) {
                $messages[] = [$this->topic . '/' . $name, self::text($value), $this->retain];
            }
        }
        if ($this->aggregate) {
            // `loop` is what the skins subscribe to, whatever produced it.
            $messages[] = [
                $this->topic . '/loop',
                json_encode($shaped, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
                $this->retain,
            ];
        }
        return $messages;
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        if ($records === []) {
            return $posted;
        }
        $record = $records[count($records) - 1];
        $posted->skipped = count($records) - 1;
        $timestamp = (new Readings($record))->timestamp();
        try {
            $this->client->connect();
            foreach ($this->messages($record) as [$topic, $payload, $retain]) {
                $this->client->publish($topic, $payload, $this->qos, $retain);
                ++$posted->sent;
            }
        } catch (Rejected $error) {
            if ($error->permanent) {
                throw $error;
            }
            $posted->failures[] = [$timestamp, $error->getMessage()];
            return $posted;
        } finally {
            $this->client->close();
            $this->announce = false;
        }
        $posted->through = $timestamp;
        return $posted;
    }

    public function check(): string
    {
        try {
            $this->client->connect();
        } catch (Rejected $error) {
            return 'could not connect: ' . $error->getMessage();
        }
        try {
            $this->client->publish($this->topic . '/status', Version::NAME, $this->qos, false);
        } catch (Rejected $error) {
            return 'connected, but publishing failed: ' . $error->getMessage();
        } finally {
            $this->client->close();
        }
        $where = $this->individual && $this->aggregate
            ? 'individual topics and a JSON document'
            : ($this->individual ? 'individual topics' : 'a JSON document');
        return sprintf('connected to %s:%d and published to %s/ as %s.', $this->client->host(), $this->client->port(), $this->topic, $where);
    }

    public function describe(): array
    {
        return ['host' => $this->client->host(), 'port' => $this->client->port(), 'topic' => $this->topic];
    }

    /** A value as the text on its own topic: what Python's `str()` would make of it. */
    private static function text(mixed $value): string
    {
        if (is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
