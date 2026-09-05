<?php

declare(strict_types=1);

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Weather;

if (!isset($wx) || !$wx instanceof Weather) {
    throw new \LogicException('The data definition requires a Weather context');
}
$wx = $wx->output(new Output(
    'de',
    units: ['group_temperature' => 'degree_C', 'group_rain' => 'mm', 'group_speed' => 'km_per_hour', 'group_pressure' => 'mbar'],
    decimals: ['group_percent' => 0, 'group_pressure' => 0],
))->reference('archive');
return [
    'updated' => $wx->current('dateTime'),
    'temperature' => $wx->current('outTemp'),
    'humidity' => $wx->current('outHumidity'),
    'wind' => $wx->current('windSpeed'),
    'pressure' => $wx->current('barometer'),
    'low' => $wx->day()->min('outTemp'),
    'high' => $wx->day()->max('outTemp'),
    'rainDay' => $wx->day()->sum('rain'),
    'rainMonth' => $wx->month()->sum('rain'),
    'rainYear' => $wx->year()->sum('rain')->nightly(),
    'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
    'temperature7d' => $wx->last('7d')->series('outTemp', '1h'),
    'rain7d' => $wx->days(7)->series('rain', 'day'),
    'rainComparison' => $wx->month()->sum('rain')->compareYears()->priority(-5),
    'wettestMonth' => $wx->alltime()->series('rain', 'month')->completed(0.95)->rank(1)->nightly()->priority(-5),
    'drySpell' => $wx->alltime()->series('rain', 'day')->longestSpell()->nightly()->priority(-5),
    'sunrise' => $wx->reference('clock')->almanac()->sun()->rise()->nightly('00:05'),
    'sunset' => $wx->reference('clock')->almanac()->sun()->set()->nightly('00:05'),
];
