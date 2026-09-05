<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Stamp;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Upload\UploadError;
use WeewxPhp\Version;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * CWOP, the Citizen Weather Observer Program, over APRS-IS.
 *
 * Not HTTP: a TCP socket, a login line, and one line of ASCII in the TNC2
 * packet format amateur radio has used since the 1990s:
 *
 *     DW1234>APZPHP,TCPIP*:@271830z4823.15N/01142.30E_045/003g007t054r000p000P000b10132h72
 *
 * Every field is fixed-width, every absent field is dots of the same
 * width, and the whole thing is positional. A wrong width produces no
 * error but a reading in the wrong place, silently, forever. So the packet
 * is `weewx.restx.CWOPThread.get_tnc_packet` character for character,
 * `h00` for 100 % humidity and the two letters for solar radiation above
 * and below 1000 W/m² included. Those are not quirks; that is the protocol.
 *
 * The position is what the reading is attributed to and cannot be
 * corrected afterwards, so it comes from the archive's own coordinates
 * unless the upload names others. `APZPHP` is the tocall: `APWEE5` is
 * assigned to WeeWX and is not ours; `APZ` is the range the specification
 * leaves to software without a registered identifier.
 */
final class Cwop implements Upload
{
    public const DESTINATION = 'APZPHP';

    private readonly string $station;

    private readonly string $passcode;

    /** @var list<array{0: string, 1: int}> */
    private readonly array $servers;

    private readonly int $timeout;

    public function __construct(
        UploadConfig $config,
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly SocketFactory $sockets,
    ) {
        $this->station = strtoupper(trim($config->text('station')));
        // -1 is what an unlicensed station sends, and that is almost every
        // weather station: a DW or EW callsign needs no real passcode.
        $passcode = trim($config->text('passcode'));
        $this->passcode = $passcode === '' ? '-1' : $passcode;
        $this->timeout = $config->timeout;
        $this->servers = self::servers($config->list('servers'));
        if ($this->station === '') {
            throw new UploadError('CWOP needs a station id such as DW1234');
        }
    }

    /**
     * Where the archive keeps its coordinates, or the upload its own.
     *
     * @throws UploadError Without any: the packet is a position report.
     */
    public static function place(UploadConfig $config, ?float $latitude, ?float $longitude): self
    {
        throw new UploadError('use the factory');
    }

    public function kind(): Kind
    {
        return Kind::Cwop;
    }

    public function login(): string
    {
        return sprintf("user %s pass %s vers %s %s\r\n", $this->station, $this->passcode, Version::NAME, Version::STRING);
    }

    /**
     * The TNC2 packet for one record: fixed width throughout, see the
     * class comment. Everything is US customary except the barometer,
     * which CWOP wants in tenths of a millibar, and that inconsistency is
     * load-bearing: inches of mercury there read as 3000 mbar.
     *
     * @param array<string, mixed> $record
     */
    public function packet(array $record): string
    {
        $readings = new Readings($record);
        // '@' is a position report with a timestamp and APRS messaging. By
        // the letter of the specification '/' would be the honest one, an
        // unattended station cannot answer a message; but this goes to
        // somebody else's ingest, and '@' is the byte WeeWX 5.5 has sent
        // from thousands of stations for fifteen years, so it is the one
        // every CWOP parser has certainly been fed.
        $prefix = sprintf('%s>%s,TCPIP*:@%s', $this->station, self::DESTINATION, Stamp::aprs($readings->timestamp()));
        $position = self::latlon($this->latitude, ['N', 'S'], true) . '/' . self::latlon($this->longitude, ['E', 'W'], false);

        $wind = [];
        foreach (['windDir', 'windSpeed', 'windGust', 'outTemp'] as $obs) {
            $value = $readings->in($obs, UnitSystem::US);
            $wind[] = $value === null ? '...' : sprintf('%03d', (int) ($value + 0.5));
        }
        $weather = sprintf('_%s/%sg%st%s', ...$wind);

        $rain = [];
        foreach (['hourRain', 'rain24', 'dayRain'] as $obs) {
            $value = $readings->in($obs, UnitSystem::US);
            $rain[] = $value === null ? '...' : sprintf('%03d', (int) ($value * 100.0 + 0.5));
        }
        $weather .= sprintf('r%sp%sP%s', ...$rain);

        $altimeter = $readings->in('altimeter', UnitSystem::US);
        if ($altimeter === null) {
            $weather .= 'b.....';
        } else {
            $mbar = (float) Units::convert($altimeter, 'inHg', 'mbar');
            $weather .= sprintf('b%05d', (int) ($mbar * 10.0 + 0.5));
        }

        $humidity = $readings->get('outHumidity');
        if ($humidity === null) {
            $weather .= 'h..';
        } else {
            // Two digits, and no room for a third: h00 is how APRS writes 100 %.
            $weather .= $humidity < 99.5 ? sprintf('h%02d', (int) ($humidity + 0.5)) : 'h00';
        }

        $radiation = $readings->get('radiation');
        if ($radiation !== null && $radiation < 999.5) {
            $weather .= sprintf('L%03d', (int) ($radiation + 0.5));
        } elseif ($radiation !== null && $radiation < 1999.5) {
            // Over a thousand the letter changes and the thousand is dropped:
            // lowercase l means "add 1000".
            $weather .= sprintf('l%03d', (int) ($radiation - 1000 + 0.5));
        }

        return sprintf("%s%s%s.%s-%s\r\n", $prefix, $position, $weather, Version::NAME, Version::STRING);
    }

    /**
     * Decimal degrees as APRS writes them: `4823.15N`, `01142.30E`.
     * Degrees and decimal minutes, two digits of degrees for a latitude and
     * three for a longitude: `weeutil.weeutil.latlon_string`.
     *
     * @param array{0: string, 1: string} $hemispheres
     */
    public static function latlon(float $value, array $hemispheres, bool $latitude): string
    {
        $magnitude = abs($value);
        $degrees = floor($magnitude);
        $minutes = ($magnitude - $degrees) * 60.0;
        return sprintf($latitude ? '%02d' : '%03d', (int) $degrees)
            . sprintf('%05.2f', $minutes)
            . ($value >= 0 ? $hemispheres[0] : $hemispheres[1]);
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        if ($records === []) {
            return $posted;
        }
        // An APRS position report means "now": only the newest.
        $record = $records[count($records) - 1];
        $posted->skipped = count($records) - 1;
        $timestamp = (new Readings($record))->timestamp();
        try {
            $posted->note = $this->send($this->packet($record));
        } catch (Rejected $error) {
            $posted->failures[] = [$timestamp, $error->getMessage()];
            return $posted;
        }
        $posted->sent = 1;
        $posted->through = $timestamp;
        return $posted;
    }

    /**
     * Connect and log in without sending a packet. APRS-IS has no way to
     * validate a reading, so this tests the half that can be tested: a
     * server answers and takes the callsign.
     */
    public function check(): string
    {
        $last = '';
        foreach ($this->servers as [$host, $port]) {
            try {
                $connection = $this->sockets->open($host, $port, false, false, $this->timeout);
                try {
                    $connection->write($this->login());
                    $banner = trim($connection->readSome(1024));
                } finally {
                    $connection->close();
                }
                return sprintf("%s:%d answered: %s\n  packet: %s", $host, $port, substr($banner, 0, 120), trim($this->packet(['dateTime' => time(), 'usUnits' => 1])));
            } catch (Rejected $error) {
                $last = $error->getMessage();
            }
        }
        return 'no CWOP server answered: ' . $last;
    }

    public function describe(): array
    {
        return [
            'station' => $this->station,
            'servers' => array_map(static fn(array $server): string => sprintf('%s:%d', $server[0], $server[1]), $this->servers),
        ];
    }

    /**
     * Log in and send, on the first server that takes the connection.
     * Returns which one did.
     *
     * @throws Rejected When none did.
     */
    private function send(string $packet): string
    {
        $last = '';
        foreach ($this->servers as [$host, $port]) {
            try {
                $connection = $this->sockets->open($host, $port, false, false, $this->timeout);
                try {
                    $connection->write($this->login());
                    // APRS-IS answers the login with a banner. It is read before
                    // the packet goes: pushing a packet at a server that has
                    // not finished saying hello gets it dropped without a word.
                    $connection->readSome(1024);
                    $connection->write($packet);
                    $connection->readSome(1024);
                } finally {
                    $connection->close();
                }
                return sprintf('%s:%d', $host, $port);
            } catch (Rejected $error) {
                $last = $error->getMessage();
            }
        }
        throw new Rejected('no CWOP server answered: ' . $last);
    }

    /**
     * @param list<string> $entries
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function servers(array $entries): array
    {
        $servers = [];
        foreach ($entries as $entry) {
            [$host, $port] = array_pad(explode(':', trim($entry), 2), 2, '14580');
            $host = trim($host);
            if ($host === '' || !is_numeric($port)) {
                throw new UploadError(sprintf('%s is not a CWOP server address; host:port is', $entry));
            }
            $servers[] = [$host, (int) $port];
        }
        if ($servers === []) {
            throw new UploadError('no CWOP server address');
        }
        return $servers;
    }
}
