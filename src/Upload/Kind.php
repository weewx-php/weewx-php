<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/**
 * The services readings can be sent to, and what each one is like: whether
 * it takes a timestamp with a reading (and so can be sent what it missed),
 * how often it wants to hear, and which settings it needs.
 */
enum Kind: string
{
    case Wunderground = 'wunderground';
    case PwsWeather = 'pwsweather';
    case Wow = 'wow';
    case Windy = 'windy';
    case Weathercloud = 'weathercloud';
    case Cwop = 'cwop';
    case Mqtt = 'mqtt';
    case Influx = 'influx';

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Wunderground => 'Weather Underground',
            self::PwsWeather => 'PWSweather',
            self::Wow => 'Met Office WOW',
            self::Windy => 'Windy',
            self::Weathercloud => 'Weathercloud',
            self::Cwop => 'CWOP',
            self::Mqtt => 'MQTT',
            self::Influx => 'InfluxDB',
        };
    }

    /** One line for a list of what there is. */
    public function summary(): string
    {
        return match ($this) {
            self::Wunderground => 'The largest network of personal weather stations. Free, and what most people mean by publishing their readings.',
            self::PwsWeather => 'A second network, same protocol. Costs nothing and takes the readings a station is already sending elsewhere.',
            self::Wow => "The UK Met Office's observations site. Takes stations from anywhere, not only the UK.",
            self::Windy => 'What people sailing and flying look at. Metric, JSON, and the one service here that is not the Ambient protocol.',
            self::Weathercloud => 'A map and a dashboard, popular in Europe. Metric, and every reading goes as an integer in tenths.',
            self::Cwop => "The Citizen Weather Observer Program. Feeds NOAA's MADIS, so the readings reach the same place the airports do.",
            self::Mqtt => 'A broker, so a page updates while somebody is looking at it. What Belchertown, jas and weewx-wdc take their live data from.',
            self::Influx => 'The archive in a time series database, so Grafana can ask it questions. One upload per archive.',
        };
    }

    /**
     * Whether records the service missed are worth sending afterwards. True
     * for anything that takes a timestamp with the reading. False for a
     * service that only ever means "now": posting it a stale reading as
     * current is worse than posting nothing.
     */
    public function backfill(): bool
    {
        return !in_array($this, [self::Cwop, self::Weathercloud, self::Mqtt], true);
    }

    /** Whether the `live` trigger means anything: only a broker wants every packet. */
    public function allowsLive(): bool
    {
        return $this === self::Mqtt;
    }

    public function defaultTrigger(): Trigger
    {
        return match ($this) {
            self::Cwop => Trigger::Interval,
            self::Mqtt => Trigger::Live,
            default => Trigger::Record,
        };
    }

    /** Seconds between runs on the `interval` trigger. CWOP asks for one report every ten minutes and means it. */
    public function defaultEvery(): int
    {
        return $this === self::Cwop ? 600 : 900;
    }

    /**
     * How many missed records one run may send. A station offline for a
     * week must not come back and fire two thousand requests at a free
     * service; the operator's own database is another matter.
     */
    public function defaultCatchUp(): int
    {
        if ($this === self::Influx) {
            return 5000;
        }
        return $this->backfill() ? 12 : 0;
    }

    public function maxCatchUp(): int
    {
        return $this === self::Influx ? 1_000_000 : 288;
    }

    /** Seconds one request may take before it is given up on. */
    public function defaultTimeout(): int
    {
        return $this === self::Influx ? 30 : 10;
    }

    public function maxTimeout(): int
    {
        return $this === self::Influx ? 300 : 60;
    }

    /** How old the newest record may be and still be posted as current, by a service without backfill. */
    public function defaultStale(): int
    {
        return $this === self::Cwop ? 600 : 900;
    }

    /**
     * The settings this kind takes, beyond the ones every upload has.
     *
     * @return array<string, OptionSpec>
     */
    public function spec(): array
    {
        return match ($this) {
            self::Wunderground, self::PwsWeather => [
                'station' => OptionSpec::text(required: true),
                'password' => OptionSpec::secret(required: true),
                'indoor' => OptionSpec::flag(false),
            ],
            self::Wow => [
                'station' => OptionSpec::text(required: true),
                'password' => OptionSpec::secret(required: true),
            ],
            self::Windy => [
                'api_key' => OptionSpec::secret(required: true),
                'station' => OptionSpec::int(0),
            ],
            self::Weathercloud => [
                'wid' => OptionSpec::text(required: true),
                'key' => OptionSpec::secret(required: true),
                'indoor' => OptionSpec::flag(false),
            ],
            self::Cwop => [
                'station' => OptionSpec::text(required: true),
                'passcode' => OptionSpec::secret(default: '-1'),
                'latitude' => OptionSpec::float(),
                'longitude' => OptionSpec::float(),
                'servers' => OptionSpec::list(['cwop.aprs.net:14580', 'cwop.aprs.net:23']),
            ],
            self::Mqtt => [
                'host' => OptionSpec::text(required: true),
                'port' => OptionSpec::int(null),
                'username' => OptionSpec::text(),
                'password' => OptionSpec::secret(),
                'client_id' => OptionSpec::text(),
                'tls' => OptionSpec::flag(false),
                'tls_verify' => OptionSpec::flag(true),
                'topic' => OptionSpec::text(default: 'weather'),
                'unit_system' => OptionSpec::choice(['', 'US', 'METRIC', 'METRICWX'], ''),
                'append_units' => OptionSpec::flag(true),
                'aggregate' => OptionSpec::flag(true),
                'individual' => OptionSpec::flag(true),
                'retain' => OptionSpec::flag(true),
                'qos' => OptionSpec::choice(['0', '1'], '0'),
                'home_assistant' => OptionSpec::flag(false),
                'discovery_prefix' => OptionSpec::text(default: 'homeassistant'),
                'station' => OptionSpec::text(),
                'keepalive' => OptionSpec::int(60),
            ],
            self::Influx => [
                'url' => OptionSpec::text(required: true),
                'api' => OptionSpec::choice(['v2', 'v1'], 'v2'),
                'bucket' => OptionSpec::text(required: true),
                'org' => OptionSpec::text(),
                'token' => OptionSpec::secret(),
                'username' => OptionSpec::text(),
                'password' => OptionSpec::secret(),
                'measurement' => OptionSpec::text(default: 'weather'),
                'location' => OptionSpec::text(),
                'unit_system' => OptionSpec::choice(['METRICWX', 'METRIC', 'US'], 'METRICWX'),
            ],
        };
    }
}
