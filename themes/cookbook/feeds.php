<?php

declare(strict_types=1);

use WeewxPhp\Frontend\Api\Feed;
use WeewxPhp\Frontend\Query;
use WeewxPhp\Frontend\Weather;

if (!isset($wx) || !$wx instanceof Weather) {
    throw new LogicException('A Weather context is required');
}
/** @var array<string, Query> $queries */
$queries = require __DIR__ . '/data.php';
// Explicitly public examples. Use exact origins in your own publication if desired.
return [
    'sidebar' => new Feed(
        $wx,
        array_intersect_key($queries, array_flip(['temperature', 'humidity', 'wind'])),
        labels: ['temperature' => 'Temperatur', 'humidity' => 'Luftfeuchte', 'wind' => 'Wind'],
        origins: ['*'],
        pollSeconds: 60,
        title: 'Wetterstation',
    ),
    'live' => new Feed(
        $wx,
        live: ['temperature' => 'outTemp', 'humidity' => 'outHumidity', 'wind' => 'windSpeed'],
        labels: ['temperature' => 'Temperatur', 'humidity' => 'Luftfeuchte', 'wind' => 'Wind'],
        origins: ['*'],
        title: 'Live',
    ),
    'charts' => new Feed(
        $wx,
        array_diff_key($queries, array_flip(['temperature', 'humidity', 'wind'])),
        origins: ['*'],
        pollSeconds: 60,
        title: 'Diagramme',
    ),
];
