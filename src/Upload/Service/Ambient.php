<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Http\HttpResponse;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Stamp;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Upload\UploadError;
use WeewxPhp\Version;
use WeewxPhp\Weewx\UnitSystem;

/**
 * The Ambient protocol: Weather Underground, PWSweather and Met Office WOW.
 *
 * Weather Underground defined it and the others copied it, down to the
 * parameter names, so it is one class with three hosts rather than three
 * near-identical files. WOW renamed both credentials, shortened the field
 * list and answers a wrong key with HTTP 403 rather than a word in the
 * body; otherwise it is the same GET.
 *
 * The fields are `weewx.restx.AmbientThread._FORMATS`, name for name and
 * width for width: `humidity=061` and `windspeedmph=003.1` are what the
 * protocol defines, and the zero-padding is not decoration. Three things
 * are decisions rather than transcription: the units are US whatever the
 * archive holds, because the protocol has no way to say otherwise; an
 * absent reading is absent, never zero, because a `rainin=0.00` from a
 * station with no gauge is kept by the service forever; and a bad
 * password is said once, because these services answer it with 200 and a
 * word in the body.
 */
final class Ambient implements Upload
{
    /**
     * WeeWX's table for Weather Underground and PWSweather, in its order,
     * as (our name, their name, printf format); `dateTime` is where WeeWX
     * has it. `windgustdir` is not in WeeWX's table but is in the
     * protocol: a gust with no direction is half a reading.
     */
    private const FIELDS = [
        ['barometer', 'baromin', '%.3f'],
        ['co', 'AqCO', '%f'],
        ['dateTime', 'dateutc', ''],
        ['dayRain', 'dailyrainin', '%.2f'],
        ['dewpoint', 'dewptf', '%.1f'],
        ['hourRain', 'rainin', '%.2f'],
        ['leafWet1', 'leafwetness', '%03.0f'],
        ['leafWet2', 'leafwetness2', '%03.0f'],
        ['no2', 'AqNO2', '%f'],
        ['o3', 'AqOZONE', '%f'],
        ['outHumidity', 'humidity', '%03.0f'],
        ['outTemp', 'tempf', '%.1f'],
        ['pm10_0', 'AqPM10', '%.1f'],
        ['pm2_5', 'AqPM2.5', '%.1f'],
        ['radiation', 'solarradiation', '%.2f'],
        ['so2', 'AqSO2', '%f'],
        ['soilMoist1', 'soilmoisture', '%03.0f'],
        ['soilMoist2', 'soilmoisture2', '%03.0f'],
        ['soilMoist3', 'soilmoisture3', '%03.0f'],
        ['soilMoist4', 'soilmoisture4', '%03.0f'],
        ['soilTemp1', 'soiltempf', '%.1f'],
        ['soilTemp2', 'soiltemp2f', '%.1f'],
        ['soilTemp3', 'soiltemp3f', '%.1f'],
        ['soilTemp4', 'soiltemp4f', '%.1f'],
        ['UV', 'UV', '%.2f'],
        ['windDir', 'winddir', '%03.0f'],
        ['windGust', 'windgustmph', '%03.1f'],
        ['windGust10', 'windgustmph_10m', '%03.1f'],
        ['windGustDir10', 'windgustdir_10m', '%03.0f'],
        ['windSpeed', 'windspeedmph', '%03.1f'],
        ['windSpeed2', 'windspdmph_avg2m', '%03.1f'],
        ['windGustDir', 'windgustdir', '%03.0f'],
    ];

    /** Inside the house. Off by default: the temperature of somebody's living room on a public map is their decision. */
    private const INDOOR_FIELDS = [
        ['inTemp', 'indoortempf', '%.1f'],
        ['inHumidity', 'indoorhumidity', '%.0f'],
    ];

    /** WOW's own, shorter table: `weewx.restx.WOWThread._FORMATS`. */
    private const WOW_FIELDS = [
        ['dateTime', 'dateutc', ''],
        ['barometer', 'baromin', '%.3f'],
        ['outTemp', 'tempf', '%.1f'],
        ['outHumidity', 'humidity', '%.0f'],
        ['windSpeed', 'windspeedmph', '%.0f'],
        ['windDir', 'winddir', '%.0f'],
        ['windGust', 'windgustmph', '%.0f'],
        ['windGustDir', 'windgustdir', '%.0f'],
        ['dewpoint', 'dewptf', '%.1f'],
        ['hourRain', 'rainin', '%.2f'],
        ['dayRain', 'dailyrainin', '%.3f'],
    ];

    /** What these services say, on HTTP 200, when the credentials are wrong. */
    private const BAD_LOGIN = ['invalidpasswordid', 'badauth', 'error: not authorized', 'unable to validate', 'invalid'];

    public const URLS = [
        'wunderground' => 'https://weatherstation.wunderground.com/weatherstation/updateweatherstation.php',
        'pwsweather' => 'https://www.pwsweather.com/pwsupdate/pwsupdate.php',
        'wow' => 'https://wow.metoffice.gov.uk/automaticreading',
    ];

    private readonly Kind $kind;

    private readonly string $station;

    private readonly string $password;

    private readonly bool $indoor;

    private readonly int $timeout;

    public function __construct(UploadConfig $config, private readonly HttpClient $http)
    {
        if (!isset(self::URLS[$config->kind->value])) {
            throw new UploadError(sprintf('%s is not an Ambient service', $config->kind->value));
        }
        $this->kind = $config->kind;
        $this->station = trim($config->text('station'));
        $this->password = $config->text('password');
        $this->indoor = $config->flag('indoor');
        $this->timeout = $config->timeout;
    }

    private function endpoint(): string
    {
        return self::URLS[$this->kind->value] ?? throw new UploadError('not an Ambient service');
    }

    public function kind(): Kind
    {
        return $this->kind;
    }

    /**
     * The whole URL for one record: WeeWX's `format_url`, joined the way
     * WeeWX joins it.
     *
     * @param array<string, mixed> $record
     */
    public function url(array $record): string
    {
        $readings = new Readings($record);
        $wow = $this->kind === Kind::Wow;
        $parts = [
            'action=updateraw',
            sprintf('%s=%s', $wow ? 'siteid' : 'ID', $this->station),
            sprintf('%s=%s', $wow ? 'siteAuthenticationKey' : 'PASSWORD', rawurlencode($this->password)),
            'softwaretype=' . Version::NAME,
        ];
        $fields = $wow ? self::WOW_FIELDS : [...self::FIELDS, ...($this->indoor ? self::INDOOR_FIELDS : [])];
        foreach ($fields as [$obs, $name, $format]) {
            if ($obs === 'dateTime') {
                $parts[] = 'dateutc=' . rawurlencode(Stamp::ambient($readings->timestamp()));
                continue;
            }
            $value = $readings->in($obs, UnitSystem::US);
            if ($value !== null) {
                $parts[] = sprintf('%s=%s', $name, sprintf($format, $value));
            }
        }
        return $this->endpoint() . '?' . implode('&', $parts);
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        foreach ($records as $record) {
            try {
                $this->send($record);
            } catch (Rejected $error) {
                if ($error->permanent) {
                    throw $error;
                }
                // Stop at the first refusal: the rest are almost certainly
                // the same problem, and eleven more requests at a service
                // having a bad afternoon is how a station gets rate-limited.
                $posted->failures[] = [self::timestamp($record), $error->getMessage()];
                break;
            }
            ++$posted->sent;
            $posted->through = self::timestamp($record);
        }
        return $posted;
    }

    /**
     * Post a record with a timestamp and no readings. That is accepted as
     * an empty update and records nothing, which is what makes it safe to
     * run from a command.
     */
    public function check(): string
    {
        try {
            $this->send(['dateTime' => time(), 'usUnits' => UnitSystem::US->value]);
        } catch (Rejected $error) {
            return 'refused: ' . $error->getMessage();
        }
        return sprintf('%s accepted the credentials for %s.', $this->kind->label(), $this->station);
    }

    public function describe(): array
    {
        return ['host' => (string) parse_url($this->endpoint(), PHP_URL_HOST), 'station' => $this->station];
    }

    /**
     * One record. Raises `Rejected` with whether it is worth retrying.
     *
     * @param array<string, mixed> $record
     */
    private function send(array $record): void
    {
        $response = $this->http->send(new HttpRequest('GET', $this->url($record), timeout: $this->timeout));
        $this->judge($response);
    }

    private function judge(HttpResponse $response): void
    {
        $label = $this->kind->label();
        if ($this->kind === Kind::Wow) {
            // WOW signals a bad login with a 403 and says nothing in the body.
            if ($response->status === 403) {
                throw new Rejected(sprintf('%s rejected the site id or key for %s', $label, $this->station), permanent: true);
            }
            if ($response->status < 200 || $response->status > 299) {
                throw new Rejected(sprintf('%s answered %d: %s', $label, $response->status, $response->excerpt()));
            }
            return;
        }
        $body = strtolower(trim($response->body));
        if (str_starts_with($body, 'error') || self::saysBadLogin($body)) {
            throw new Rejected(
                sprintf('%s rejected the credentials for station %s: %s', $label, $this->station, $response->excerpt()),
                permanent: true,
            );
        }
        if ($response->status < 200 || $response->status > 299) {
            throw new Rejected(sprintf('%s answered %d: %s', $label, $response->status, $response->excerpt()));
        }
    }

    private static function saysBadLogin(string $body): bool
    {
        foreach (self::BAD_LOGIN as $word) {
            if (str_contains($body, $word)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $record */
    private static function timestamp(array $record): int
    {
        return (new Readings($record))->timestamp();
    }
}
