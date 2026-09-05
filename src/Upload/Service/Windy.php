<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Stamp;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Upload\UploadError;

/**
 * Windy.com: the one service here that is not the Ambient protocol. JSON
 * in the body of a POST, metric units, and the API key in the path.
 *
 * Pressure goes in pascals, not hectopascals as every barometer and every
 * other service has it: `101325`, not `1013.25`. The hectopascal figure is
 * accepted and drawn as a vacuum. And the key is in the URL, so it lands
 * in any proxy log between here and Windy; nothing here logs the path.
 *
 * Windy takes several observations in one request, so a catch-up is one
 * request rather than twelve.
 */
final class Windy implements Upload
{
    public const URL = 'https://stations.windy.com/pws/update/';

    /** (our name, Windy's name, unit, decimals). Metric throughout; the pressure is handled apart. */
    private const FIELDS = [
        ['outTemp', 'temp', 'degree_C', 1],
        ['dewpoint', 'dewpoint', 'degree_C', 1],
        ['outHumidity', 'rh', 'percent', 0],
        ['windSpeed', 'wind', 'meter_per_second', 1],
        ['windDir', 'winddir', 'degree_compass', 0],
        ['windGust', 'gust', 'meter_per_second', 1],
        ['barometer', 'pressure', 'mbar', 2],
        ['hourRain', 'precip', 'mm', 2],
        ['UV', 'uv', null, 1],
    ];

    private readonly string $apiKey;

    private readonly int $station;

    private readonly int $timeout;

    public function __construct(UploadConfig $config, private readonly HttpClient $http)
    {
        $this->apiKey = trim($config->text('api_key'));
        // Windy allows several stations under one key, numbered from zero.
        $this->station = $config->int('station') ?? 0;
        $this->timeout = $config->timeout;
        if ($this->apiKey === '') {
            throw new UploadError('Windy needs an API key');
        }
    }

    public function kind(): Kind
    {
        return Kind::Windy;
    }

    /**
     * One record as one observation.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, int|float|string>
     */
    public function observation(array $record): array
    {
        $readings = new Readings($record);
        $observation = ['station' => $this->station, 'dateutc' => Stamp::ambient($readings->timestamp())];
        foreach (self::FIELDS as [$obs, $name, $unit, $places]) {
            $value = $readings->get($obs, $unit);
            if ($value === null) {
                continue;
            }
            if ($name === 'pressure') {
                // Millibars are hectopascals; Windy wants pascals.
                $value *= 100.0;
                $places = 0;
            }
            $observation[$name] = self::rounded($value, $places);
        }
        return $observation;
    }

    /**
     * The body of one request: every record as an observation.
     *
     * @param list<array<string, mixed>> $records
     */
    public function body(array $records): string
    {
        return json_encode(
            ['observations' => array_map($this->observation(...), $records)],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        if ($records === []) {
            return $posted;
        }
        $last = (new Readings($records[count($records) - 1]))->timestamp();
        try {
            $this->send($records);
        } catch (Rejected $error) {
            if ($error->permanent) {
                throw $error;
            }
            $posted->failures[] = [$last, $error->getMessage()];
            return $posted;
        }
        $posted->sent = count($records);
        $posted->through = $last;
        return $posted;
    }

    public function check(): string
    {
        try {
            $this->send([['dateTime' => time(), 'usUnits' => 16]]);
        } catch (Rejected $error) {
            return 'refused: ' . $error->getMessage();
        }
        return 'Windy accepted the API key.';
    }

    public function describe(): array
    {
        // No key here: it is in the URL, which is bad enough already.
        return ['host' => 'stations.windy.com', 'station' => $this->station];
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @throws Rejected
     */
    private function send(array $records): void
    {
        $response = $this->http->send(new HttpRequest(
            'POST',
            self::URL . $this->apiKey,
            $this->body($records),
            ['Content-Type' => 'application/json'],
            $this->timeout,
        ));
        if ($response->status === 401 || $response->status === 403) {
            throw new Rejected('Windy rejected the API key: ' . $response->excerpt(), permanent: true);
        }
        if ($response->status !== 200) {
            throw new Rejected(sprintf('Windy answered %d: %s', $response->status, $response->excerpt()));
        }
    }

    /** Rounded to a number of places the way Python's `round` rounds: a tie to even, on the value itself. */
    private static function rounded(float $value, int $places): float
    {
        return (float) sprintf('%.' . $places . 'f', $value);
    }
}
