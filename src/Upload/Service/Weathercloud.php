<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Http\Http;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Stamp;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Version;
use WeewxPhp\Weewx\Formulas;

/**
 * Weathercloud. Metric, and every value an integer in tenths: 21.4 °C
 * goes as `214`. That is the whole protocol, a query string of scaled
 * integers with the date and the time in two parameters, both UTC.
 *
 * A scaled integer has no way to say "absent": a missing temperature and
 * 0.0 °C are `` and `0`, and only one of them is a fact. So what is not
 * there never reaches the query. And there is no timestamp in the
 * protocol beyond the moment of posting, so no backfill: an older record
 * would be published as the current conditions.
 */
final class Weathercloud implements Upload
{
    public const URL = 'https://api.weathercloud.net/v01/set';

    /** (our name, their name, unit, scale): the value goes as `round(reading * scale)`. */
    private const FIELDS = [
        ['outTemp', 'temp', 'degree_C', 10],
        ['outHumidity', 'hum', 'percent', 1],
        ['dewpoint', 'dew', 'degree_C', 10],
        ['windchill', 'chill', 'degree_C', 10],
        ['heatindex', 'heat', 'degree_C', 10],
        ['windSpeed', 'wspd', 'meter_per_second', 10],
        ['windGust', 'wspdhi', 'meter_per_second', 10],
        ['windDir', 'wdir', 'degree_compass', 1],
        ['barometer', 'bar', 'mbar', 10],
        ['dayRain', 'rain', 'mm', 10],
        ['rainRate', 'rainrate', 'mm_per_hour', 10],
        ['radiation', 'solarrad', 'watt_per_meter_squared', 10],
        ['UV', 'uvi', null, 10],
        ['ET', 'et', 'mm', 10],
    ];

    private const INDOOR_FIELDS = [
        ['inTemp', 'tempin', 'degree_C', 10],
        ['inHumidity', 'humin', 'percent', 1],
    ];

    private readonly string $wid;

    private readonly string $key;

    private readonly bool $indoor;

    private readonly int $timeout;

    public function __construct(UploadConfig $config, private readonly HttpClient $http)
    {
        $this->wid = trim($config->text('wid'));
        $this->key = trim($config->text('key'));
        $this->indoor = $config->flag('indoor');
        $this->timeout = $config->timeout;
    }

    public function kind(): Kind
    {
        return Kind::Weathercloud;
    }

    /**
     * The query string for one record.
     *
     * @param array<string, mixed> $record
     */
    public function query(array $record): string
    {
        $readings = new Readings($record);
        $fields = [
            'wid' => $this->wid,
            'key' => $this->key,
            // 251 is the number Weathercloud gave WeeWX. This is a different
            // program posting the same protocol, and being honest about the
            // family beats inventing a number they have not assigned.
            'type' => '251',
            'ver' => Version::NAME,
            'date' => Stamp::day($readings->timestamp()),
            'time' => Stamp::minute($readings->timestamp()),
        ];
        foreach ([...self::FIELDS, ...($this->indoor ? self::INDOOR_FIELDS : [])] as [$obs, $name, $unit, $scale]) {
            $value = $readings->get($obs, $unit);
            if ($value !== null) {
                $fields[$name] = (string) (int) Formulas::roundHalfEven($value * $scale);
            }
        }
        return Http::query($fields);
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        if ($records === []) {
            return $posted;
        }
        // Only the newest: the service has no timestamp, so an older
        // record would be published as the current conditions.
        $record = $records[count($records) - 1];
        $posted->skipped = count($records) - 1;
        $timestamp = (new Readings($record))->timestamp();
        try {
            $this->send($record);
        } catch (Rejected $error) {
            if ($error->permanent) {
                throw $error;
            }
            $posted->failures[] = [$timestamp, $error->getMessage()];
            return $posted;
        }
        $posted->sent = 1;
        $posted->through = $timestamp;
        return $posted;
    }

    public function check(): string
    {
        try {
            $this->send(['dateTime' => time(), 'usUnits' => 16]);
        } catch (Rejected $error) {
            return 'refused: ' . $error->getMessage();
        }
        return sprintf('Weathercloud accepted the device %s.', $this->wid);
    }

    public function describe(): array
    {
        return ['host' => 'api.weathercloud.net', 'wid' => $this->wid];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws Rejected
     */
    private function send(array $record): void
    {
        $response = $this->http->send(new HttpRequest('GET', self::URL . '?' . $this->query($record), timeout: $this->timeout));
        $text = strtolower(trim($response->body));
        // Weathercloud answers in words, on HTTP 200 whatever happened.
        if (in_array($text, ['400', '401', 'invalid'], true) || str_contains($text, 'wrong')) {
            throw new Rejected('Weathercloud rejected the device id or key: ' . $response->excerpt(), permanent: true);
        }
        if ($response->status !== 200) {
            throw new Rejected(sprintf('Weathercloud answered %d: %s', $response->status, $response->excerpt()));
        }
        if ($text !== '' && $text !== '200' && !str_contains($text, 'ok')) {
            throw new Rejected('Weathercloud answered ' . $response->excerpt());
        }
    }
}
