<?php

declare(strict_types=1);

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\FakeSocketFactory;
use WeewxPhp\Tests\Support\Uploads;
use WeewxPhp\Upload\HomeAssistant;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Records;
use WeewxPhp\Upload\Service\Ambient;
use WeewxPhp\Upload\Service\Cwop;
use WeewxPhp\Upload\Service\Influx;
use WeewxPhp\Upload\Service\Mqtt;
use WeewxPhp\Upload\Service\Weathercloud;
use WeewxPhp\Upload\Service\Windy;
use WeewxPhp\Weewx\Policy;

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/Unit/Support/Uploads.php';
require dirname(__DIR__, 2) . '/Unit/Support/FakeHttpClient.php';
require dirname(__DIR__, 2) . '/Unit/Support/FakeConnection.php';
require dirname(__DIR__, 2) . '/Unit/Support/FakeSocketFactory.php';

/*
 * Answers checks/uploads.py from JSON on stdin: for every record, what
 * each service would be sent, without sending it.
 *
 *   records   [record, ...] in any unit system
 *   station   {id, password, latitude, longitude}
 *   archive   a database to sum the rain in, and `times` to sum it for
 *
 * The answer holds, per record: the Ambient query for Weather Underground
 * and WOW as name-value pairs, the CWOP packet, Windy's observation,
 * Weathercloud's query, the InfluxDB line, the MQTT document and one
 * Home Assistant definition; and per requested time, the three rain sums.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_array($input['records'] ?? null) || !is_array($input['station'] ?? null)) {
    fwrite(STDERR, "expected records and a station on stdin\n");
    exit(1);
}
$station = $input['station'];
$id = (string) $station['id'];
$password = (string) $station['password'];
$latitude = (float) $station['latitude'];
$longitude = (float) $station['longitude'];

$http = new FakeHttpClient();
$sockets = new FakeSocketFactory();
$wu = new Ambient(Uploads::config(Kind::Wunderground, ['station' => $id, 'password' => $password, 'indoor' => true]), $http);
$wow = new Ambient(Uploads::config(Kind::Wow, ['station' => $id, 'password' => $password]), $http);
$cwop = new Cwop(Uploads::config(Kind::Cwop, ['station' => $id, 'passcode' => '-1']), $latitude, $longitude, $sockets);
$windy = new Windy(Uploads::config(Kind::Windy, ['api_key' => $password]), $http);
$weathercloud = new Weathercloud(Uploads::config(Kind::Weathercloud, ['wid' => $id, 'key' => $password, 'indoor' => true]), $http);
$influx = new Influx(Uploads::config(Kind::Influx, ['url' => 'http://influxdb:8086', 'bucket' => 'weewx', 'token' => $password, 'location' => 'Kirchdorf an der Amper']), $http);
$mqtt = new Mqtt(Uploads::config(Kind::Mqtt, ['host' => 'broker', 'client_id' => 'c']), 'Kirchdorf an der Amper', $sockets);

$pairs = static function (string $url): array {
    $query = (string) parse_url($url, PHP_URL_QUERY);
    $found = [];
    foreach (explode('&', $query) as $part) {
        [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
        $found[rawurldecode($name)] = rawurldecode($value);
    }
    return $found;
};

$answers = [];
foreach ($input['records'] as $record) {
    if (!is_array($record)) {
        continue;
    }
    [$topic, $definition] = HomeAssistant::discovery('outTemp', 'degree_C', 'weather', 'outTemp_C', 'Kirchdorf an der Amper');
    $answers[] = [
        'wu' => $pairs($wu->url($record)),
        'wow' => $pairs($wow->url($record)),
        'cwop' => trim($cwop->packet($record)),
        'windy' => $windy->observation($record),
        'weathercloud' => $pairs('?' . $weathercloud->query($record)),
        'influx' => $influx->line($record),
        'mqtt' => $mqtt->message($record),
        'discovery' => [$topic, $definition],
    ];
}

$rain = [];
if (is_array($input['archive'] ?? null) && is_string($input['archive']['path'] ?? null)) {
    $zoneName = getenv('TZ');
    $zone = new DateTimeZone($zoneName === false || $zoneName === '' ? date_default_timezone_get() : $zoneName);
    $archive = ArchiveDb::open($input['archive']['path'], JournalMode::Wal, new Policy(), $zone);
    $records = new Records($archive, $zone);
    foreach ((array) ($input['archive']['times'] ?? []) as $time) {
        $augmented = $records->augment(['dateTime' => (int) $time, 'usUnits' => (int) ($input['archive']['usUnits'] ?? 1)]);
        $rain[(string) $time] = [
            'hourRain' => $augmented['hourRain'] ?? null,
            'rain24' => $augmented['rain24'] ?? null,
            'dayRain' => $augmented['dayRain'] ?? null,
        ];
    }
    $archive->close();
}

echo json_encode(['records' => $answers, 'rain' => $rain], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
