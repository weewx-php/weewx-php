<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use DateTimeImmutable;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Weewx\Formulas;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Readings that follow from other readings: WeeWX's `StdWXCalculate` and
 * the four helpers behind it -- the plain formulas, the pressure cooker,
 * the rain rater and the counter delta -- as one object.
 *
 * Runs on each packet before it is accumulated, then once more on the
 * finished record for the two readings that need an interval, windrun and
 * ET. Per packet because dewpoint(mean(T), mean(RH)) is not the mean of
 * the dew points: deriving after averaging gives the dew point of an
 * average hour, which is not a thing that happened. That is WeeWX's order
 * as well, and so is the order of the readings, {@see Derivable::DEFAULTS}.
 *
 * Per reading, WeeWX's own rule decides between the station's value and
 * ours, {@see How}. Where WeeWX cannot work a reading out it writes null
 * for it, and so does this: an archive record then carries the column, and
 * the daily summary a row for it, as WeeWX's would.
 *
 * Rain uses the longest available counter of the selected gauge. Falling
 * counters rebase; another continuous counter may bridge their reset.
 * Counter gaps are retained as evidence, not interpreted as rain rate.
 *
 * One of these per interval built. What it carries between packets -- the
 * last value of each counter, the rain of the last quarter hour, the
 * temperature of twelve hours ago -- is seeded from the packets before the
 * span, so a record is a function of the journal and a rebuild gives what
 * the first build gave.
 */
final class Derived
{
    /** How far back the rain rate looks, in seconds: WeeWX's `rain_period`. */
    public const RAIN_PERIOD = 900;

    /** The hour evapotranspiration is computed over: WeeWX's `et_period`. */
    public const ET_PERIOD = 3600;

    /** How far the temperature of twelve hours ago may be from that moment: WeeWX's `max_delta_12h`. */
    public const MAX_DELTA_12H = 1800;

    /** Metres above ground the wind is measured at, as WeeWX assumes when nobody says. */
    public const WIND_HEIGHT_M = 2.0;

    /** The atmospheric transmission coefficient of the clear-sky radiation: WeeWX's `atc`. */
    public const ATC = 0.8;

    /** Running totals `rain` is taken from, best first: weewx-evo's list. */
    public const COUNTERS = RainCounter::FIELDS;

    private RainCounter $rainCounter;
    /** @var list<array{start: int, stop: int, amount: float|null, status: string, counter: string, source: string}> */
    private array $rainEvidence = [];
    private bool $recoveredRain = false;

    /** @var list<array{0: int, 1: float}> When it rained and how much, for the last quarter hour. */
    private array $rainEvents = [];

    private ?int $ts12h = null;

    private ?float $temp12hF = null;

    /**
     * @param array<string, How> $how Per derived reading, what decides it, in the order they
     *     are worked out: {@see Derivable::policy()}.
     */
    public function __construct(
        private readonly Site $site,
        private readonly History $history,
        private readonly array $how,
    ) {
        $this->rainCounter = new RainCounter($site->zone);
    }

    public static function fromConfig(ArchiveConfig $config, History $history): self
    {
        return new self(Site::of($config), $history, Derivable::policy($config->calculate));
    }

    /**
     * A packet from before the interval: its counters and its rain are
     * remembered, nothing is derived.
     *
     * @param array<string, mixed> $record A placed packet in the archive's unit system.
     * @param array<string, mixed>|null $counters
     */
    public function seed(array $record, string $sender, ?array $counters = null): void
    {
        $this->noteRain($this->rainFromCounters($record, $sender, $counters));
    }

    /**
     * One packet, with what follows from it filled in.
     *
     * @param array<string, mixed> $record A placed packet in the archive's unit system.
     *
     * @return array<string, mixed>
     * @param array<string, mixed>|null $counters
     */
    public function applyPacket(array $record, string $sender, ?array $counters = null): array
    {
        $record = $this->derive($this->rainFromCounters($record, $sender, $counters, true));
        // WeeWX's rain rater sees a packet after the calculations: the rate
        // in a packet is worked out from the rain before it.
        $this->noteRain($record);
        return $record;
    }

    /**
     * The finished record: the same calculations, this time with an
     * interval to work windrun and ET out over.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function applyRecord(array $record): array
    {
        return $this->derive($record);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function derive(array $record): array
    {
        $units = self::unitSystem($record);
        if ($units === null) {
            return $record;
        }
        foreach ($this->how as $obsType => $how) {
            if ($obsType === 'rain' || !$this->wants($obsType, $record)) {
                continue;
            }
            $record = $this->calculate($obsType, $record, $units);
        }
        return $record;
    }

    /** @param array<string, mixed> $record */
    private function wants(string $obsType, array $record): bool
    {
        return match ($this->how[$obsType] ?? How::PreferHardware) {
            How::Hardware => false,
            How::Software => true,
            How::PreferHardware => ($record[$obsType] ?? null) === null,
        };
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function calculate(string $obsType, array $record, UnitSystem $units): array
    {
        return match ($obsType) {
            'pressure' => $this->pressure($record, $units),
            'altimeter' => $this->altimeter($record, $units),
            'appTemp' => $this->appTemp($record, $units),
            'barometer' => $this->barometer($record, $units),
            'cloudbase' => $this->cloudbase($record, $units),
            'dewpoint' => self::dewpoint($record, $units, 'outTemp', 'outHumidity', 'dewpoint'),
            'ET' => $this->evapotranspiration($record, $units),
            'heatindex' => self::heatindex($record, $units),
            'humidex' => self::humidex($record, $units),
            'inDewpoint' => self::dewpoint($record, $units, 'inTemp', 'inHumidity', 'inDewpoint'),
            'maxSolarRad' => $this->maxSolarRad($record),
            'rainRate' => $this->rainRate($record),
            'windchill' => self::windchill($record, $units),
            'windrun' => self::windrun($record, $units),
            'windDir' => self::directionOfCalm($record, 'windSpeed', 'windDir'),
            'windGustDir' => self::directionOfCalm($record, 'windGust', 'windGustDir'),
            default => $record,
        };
    }

    // -- rain, from counters and into the rate ----------------------------

    /**
     * `rain` as the difference between two readings of a running total.
     * The totals are remembered whether or not `rain` is wanted, so a later
     * packet without a hardware value still has something to differ from.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     * @param array<string, mixed>|null $counters
     */
    private function rainFromCounters(array $record, string $sender, ?array $counters, bool $collect = false): array
    {
        $hardware = !$this->wants('rain', $record);
        $counters ??= array_intersect_key($record, array_flip(self::COUNTERS));
        $ordered = array_replace(array_intersect_key(array_fill_keys(self::COUNTERS, null), $counters), $counters);
        $reading = $this->rainCounter->read($sender, self::timestamp($record), $ordered, $hardware);
        $this->recoveredRain = !$hardware && $reading['stop'] - $reading['start'] > self::RAIN_PERIOD;
        if (!$hardware && $reading['amount'] !== null) {
            $record['rain'] = $reading['amount'];
        }
        if ($collect && !$hardware && $reading['status'] !== 'absent' && $reading['start'] < $reading['stop']) {
            $this->rainEvidence[] = $reading + ['source' => $sender];
        }
        return $record;
    }

    /** @return list<array{start: int, stop: int, amount: float|null, status: string, counter: string, source: string}> */
    public function rainEvidence(int $interval): array
    {
        return array_values(array_filter($this->rainEvidence, static fn(array $reading): bool =>
            $reading['stop'] - $reading['start'] > $interval || $reading['status'] !== 'complete'));
    }

    /**
     * WeeWX's `RainRater.add_loop_packet`: a packet with rain in it becomes
     * an event, and events older than the period go.
     *
     * @param array<string, mixed> $record
     */
    private function noteRain(array $record): void
    {
        $rain = self::number($record['rain'] ?? null);
        if ($rain === null || $rain <= 0.0 || $this->recoveredRain) {
            return;
        }
        $when = self::timestamp($record);
        $this->rainEvents[] = [$when, $rain];
        $this->rainEvents = array_values(array_filter(
            $this->rainEvents,
            static fn(array $event): bool => $event[0] >= $when - self::RAIN_PERIOD,
        ));
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function rainRate(array $record): array
    {
        if ($this->recoveredRain) {
            return self::cannot($record, 'rainRate');
        }
        $when = self::timestamp($record);
        $sum = 0.0;
        foreach ($this->rainEvents as [$at, $rain]) {
            if ($at > $when - self::RAIN_PERIOD) {
                $sum += $rain;
            }
        }
        $record['rainRate'] = 3600 * $sum / self::RAIN_PERIOD;
        return $record;
    }

    // -- the pressures ------------------------------------------------------

    /**
     * Station pressure from the barometer: WeeWX's `PressureCooker.pressure`,
     * which reverses the Davis reduction using the temperature of twelve
     * hours ago.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function pressure(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'outTemp', 'barometer', 'outHumidity')) {
            return self::cannot($record, 'pressure');
        }
        $temp12hF = $this->temperature12hF(self::timestamp($record));
        $outTemp = self::number($record['outTemp']);
        $barometer = self::number($record['barometer']);
        $outHumidity = self::number($record['outHumidity']);
        $altitudeFt = $this->site->altitude?->in('foot');
        if ($temp12hF === null || $outTemp === null || $barometer === null || $outHumidity === null || $altitudeFt === null) {
            return self::cannot($record, 'pressure');
        }
        // The Davis reduction works in US units; only what it needs is converted.
        $us = Units::toSystem(
            ['usUnits' => $units->value, 'outTemp' => $outTemp, 'barometer' => $barometer, 'outHumidity' => $outHumidity],
            UnitSystem::US,
        );
        $pressureInHg = Formulas::sealevelToSensorPressureUS(
            self::number($us['barometer']) ?? $barometer,
            $altitudeFt,
            self::number($us['outTemp']) ?? $outTemp,
            $temp12hF,
            self::number($us['outHumidity']) ?? $outHumidity,
        );
        $record['pressure'] = $pressureInHg === null
            ? null
            : self::number(Units::convert($pressureInHg, 'inHg', Units::standardUnit($units, 'group_pressure')));
        return $record;
    }

    /**
     * The outside temperature of roughly twelve hours ago, in Fahrenheit,
     * looked up again only once the moment has moved on by more than the
     * tolerance: WeeWX's `PressureCooker._get_temperature_12h`.
     */
    private function temperature12hF(int $timestamp): ?float
    {
        $ts12h = $timestamp - 12 * 3600;
        if ($this->ts12h === null || $this->temp12hF === null || abs($this->ts12h - $ts12h) > self::MAX_DELTA_12H) {
            $this->temp12hF = null;
            $record = $this->history->recordNear($ts12h, self::MAX_DELTA_12H);
            $outTemp = $record === null ? null : self::number($record['outTemp'] ?? null);
            $system = $record === null ? null : self::unitSystem($record);
            if ($outTemp !== null && $system !== null) {
                [$unit] = Units::unitOf($system, 'outTemp');
                $this->temp12hF = $unit === null ? null : self::number(Units::convert($outTemp, $unit, 'degree_F'));
            }
            $this->ts12h = $ts12h;
        }
        return $this->temp12hF;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function altimeter(array $record, UnitSystem $units): array
    {
        $altitude = $this->altitudeIn($units);
        if (!self::has($record, 'pressure') || $altitude === null) {
            return self::cannot($record, 'altimeter');
        }
        $pressure = self::number($record['pressure']);
        $record['altimeter'] = $units === UnitSystem::US
            ? Formulas::altimeterPressureUS($pressure, $altitude)
            : Formulas::altimeterPressureMetric($pressure, $altitude);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function barometer(array $record, UnitSystem $units): array
    {
        $altitude = $this->altitudeIn($units);
        if (!self::has($record, 'pressure', 'outTemp') || $altitude === null) {
            return self::cannot($record, 'barometer');
        }
        $pressure = self::number($record['pressure']);
        $outTemp = self::number($record['outTemp']);
        $record['barometer'] = $units === UnitSystem::US
            ? Formulas::sealevelPressureUS($pressure, $altitude, $outTemp)
            : Formulas::sealevelPressureMetric($pressure, $altitude, $outTemp);
        return $record;
    }

    // -- temperatures and the like --------------------------------------------

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function dewpoint(array $record, UnitSystem $units, string $temperature, string $humidity, string $target): array
    {
        if (!self::has($record, $temperature, $humidity)) {
            return self::cannot($record, $target);
        }
        $t = self::number($record[$temperature]);
        $rh = self::number($record[$humidity]);
        $record[$target] = $units === UnitSystem::US ? Formulas::dewpointF($t, $rh) : Formulas::dewpointC($t, $rh);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function heatindex(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'outTemp', 'outHumidity')) {
            return self::cannot($record, 'heatindex');
        }
        $t = self::number($record['outTemp']);
        $rh = self::number($record['outHumidity']);
        $record['heatindex'] = $units === UnitSystem::US ? Formulas::heatindexF($t, $rh) : Formulas::heatindexC($t, $rh);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function humidex(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'outTemp', 'outHumidity')) {
            return self::cannot($record, 'humidex');
        }
        $t = self::number($record['outTemp']);
        $rh = self::number($record['outHumidity']);
        $record['humidex'] = $units === UnitSystem::US ? Formulas::humidexF($t, $rh) : Formulas::humidexC($t, $rh);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function windchill(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'outTemp', 'windSpeed')) {
            return self::cannot($record, 'windchill');
        }
        $t = self::number($record['outTemp']);
        $v = self::number($record['windSpeed']);
        $record['windchill'] = match ($units) {
            UnitSystem::US => Formulas::windchillF($t, $v),
            UnitSystem::METRIC => Formulas::windchillMetric($t, $v),
            UnitSystem::METRICWX => Formulas::windchillMetricWX($t, $v),
        };
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function appTemp(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'outTemp', 'outHumidity', 'windSpeed')) {
            return self::cannot($record, 'appTemp');
        }
        $t = self::number($record['outTemp']);
        $rh = self::number($record['outHumidity']);
        $v = self::number($record['windSpeed']);
        if ($units === UnitSystem::US) {
            $record['appTemp'] = Formulas::apptempF($t, $rh, $v);
            return $record;
        }
        // The metric formula wants the wind in m/s, whichever metric system the record is in.
        [$unit] = Units::unitOf($units, 'windSpeed');
        $mps = $v === null || $unit === null ? null : self::number(Units::convert($v, $unit, 'meter_per_second'));
        $record['appTemp'] = Formulas::apptempC($t, $rh, $mps);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function cloudbase(array $record, UnitSystem $units): array
    {
        $altitude = $this->altitudeIn($units);
        if (!self::has($record, 'outTemp', 'outHumidity') || $altitude === null) {
            return self::cannot($record, 'cloudbase');
        }
        $t = self::number($record['outTemp']);
        $rh = self::number($record['outHumidity']);
        $record['cloudbase'] = $units === UnitSystem::US
            ? Formulas::cloudbaseUS($t, $rh, $altitude)
            : Formulas::cloudbaseMetric($t, $rh, $altitude);
        return $record;
    }

    // -- sun, wind, evapotranspiration -----------------------------------------

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function maxSolarRad(array $record): array
    {
        $altitudeM = $this->site->altitude?->in('meter');
        if ($this->site->latitude === null || $this->site->longitude === null || $altitudeM === null) {
            return self::cannot($record, 'maxSolarRad');
        }
        $record['maxSolarRad'] = Formulas::solarRadRS(
            $this->site->latitude,
            $this->site->longitude,
            $altitudeM,
            self::timestamp($record),
            self::ATC,
        );
        return $record;
    }

    /**
     * Distance the wind covered, in the record's distance unit: miles or
     * kilometres from mph or km/h and minutes; from m/s WeeWX multiplies
     * out the seconds and divides by the thousand metres.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function windrun(array $record, UnitSystem $units): array
    {
        if (!self::has($record, 'windSpeed', 'interval')) {
            return self::cannot($record, 'windrun');
        }
        $speed = self::number($record['windSpeed']);
        $interval = self::number($record['interval']);
        if ($speed === null || $interval === null) {
            return self::cannot($record, 'windrun');
        }
        $record['windrun'] = $units === UnitSystem::METRICWX
            ? $speed * $interval * 60.0 / 1000.0
            : $speed * $interval / 60.0;
        return $record;
    }

    /**
     * A direction with no wind behind it is cleared: WeeWX's `force_null`.
     * Anything else about the direction is left as the station sent it.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function directionOfCalm(array $record, string $speed, string $direction): array
    {
        if (!array_key_exists($speed, $record)) {
            return $record;
        }
        $value = self::number($record[$speed]);
        if ($value !== null && $value !== 0.0) {
            return $record;
        }
        $record[$direction] = null;
        return $record;
    }

    /**
     * Evapotranspiration over the record's interval, from the archive's
     * last hour: WeeWX's `ETXType`. A packet has no interval and gets none.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function evapotranspiration(array $record, UnitSystem $units): array
    {
        if (!array_key_exists('interval', $record)) {
            return self::cannot($record, 'ET');
        }
        $interval = self::number($record['interval']);
        $end = self::timestamp($record);
        $altitudeFt = $this->site->altitude?->in('foot');
        $window = $this->history->etWindow($end - self::ET_PERIOD, $end);
        if ($window === null || $interval === null || $altitudeFt === null
            || $this->site->latitude === null || $this->site->longitude === null
            || in_array(null, $window, true)) {
            return self::cannot($record, 'ET');
        }
        $tMax = self::number($window['t_max']);
        $tMin = self::number($window['t_min']);
        $radAvg = self::number($window['rad_avg']);
        $windAvg = self::number($window['wind_avg']);
        $rhMax = self::number($window['rh_max']);
        $rhMin = self::number($window['rh_min']);
        $system = is_int($window['units_min']) ? UnitSystem::tryFrom($window['units_min']) : null;
        if ($tMax === null || $tMin === null || $radAvg === null || $windAvg === null || $rhMax === null || $rhMin === null
            || $system === null || $window['units_min'] !== $window['units_max']) {
            // Mixed unit systems in one hour is a case WeeWX skips as well.
            return self::cannot($record, 'ET');
        }
        // The formula runs in US units, whatever the archive is kept in.
        if ($system !== UnitSystem::US) {
            $tMax = Units::cToF($tMax);
            $tMin = Units::cToF($tMin);
            $windAvg = $system === UnitSystem::METRICWX ? Units::mpsToMph($windAvg) : Units::kphToMph($windAvg);
        }
        $local = (new DateTimeImmutable('@' . $end))->setTimezone($this->site->zone);
        $todUtc = (int) gmdate('G', $end) + (int) gmdate('i', $end) / 60.0 + (int) gmdate('s', $end) / 3600.0;
        $rate = Formulas::evapotranspirationUS(
            $tMin,
            $tMax,
            $rhMin,
            $rhMax,
            $radAvg,
            $windAvg,
            self::WIND_HEIGHT_M / Units::METER_PER_FOOT,
            $this->site->latitude,
            $this->site->longitude,
            $altitudeFt,
            (int) $local->format('z'),
            $todUtc,
        );
        // Inches per hour over the interval, then into the record's rain unit.
        $inches = $rate === null ? null : $rate * $interval / 60.0;
        $record['ET'] = $inches === null
            ? null
            : self::number(Units::convert($inches, 'inch', Units::standardUnit($units, 'group_rain')));
        return $record;
    }

    // -- helpers --------------------------------------------------------------

    private function altitudeIn(UnitSystem $units): ?float
    {
        return $this->site->altitude?->in($units === UnitSystem::US ? 'foot' : 'meter');
    }

    /**
     * WeeWX's `CannotCalculate`: the reading is wanted and cannot be worked
     * out, and the record says so with a null.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function cannot(array $record, string $obsType): array
    {
        $record[$obsType] = null;
        return $record;
    }

    /** @param array<string, mixed> $record Whether every key is present, null or not: WeeWX's `in record`. */
    private static function has(array $record, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $record */
    private static function unitSystem(array $record): ?UnitSystem
    {
        $value = $record['usUnits'] ?? null;
        return is_int($value) ? UnitSystem::tryFrom($value) : null;
    }

    /** @param array<string, mixed> $record */
    private static function timestamp(array $record): int
    {
        $value = $record['dateTime'] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_float($value) ? (int) $value : 0;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        return null;
    }
}
