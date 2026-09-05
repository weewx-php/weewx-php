<?php

declare(strict_types=1);

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Weather;

if (!isset($wx) || !$wx instanceof Weather) {
    throw new LogicException('A Weather context is required');
}
$wx = $wx->output(new Output(
    'de',
    units: ['group_temperature' => 'degree_C',
        'group_rain' => 'mm', 'group_speed' => 'km_per_hour', 'group_pressure' => 'mbar'],
    decimals: ['group_percent' => 0],
))->reference('archive');

return [
    'temperature' => $wx->current('outTemp'),
    'humidity' => $wx->current('outHumidity'),
    'wind' => $wx->current('windSpeed'),
    'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
    'humidity24h' => $wx->last('24h')->series('outHumidity', '15m'),
    'rain24h' => $wx->last('24h')->series('rain', '15m'),
    'rain7d' => $wx->days(7)->series('rain', 'day'),
    'rainComparison' => $wx->month()->sum('rain')->compareYears(0.95)->nightly('02:00')->priority(-5),
];
