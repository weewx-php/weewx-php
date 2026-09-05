<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../Unit/Support/Archives.php';

use WeewxPhp\Frontend\Astronomy;
use WeewxPhp\Frontend\Spec;
use WeewxPhp\Tests\Support\Archives;

$cases = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$archive = Archives::config(database: '/tmp/unused-astronomy.sdb');
$out = [];
foreach ($cases as $case) {
    $spec = new Spec(period: 'almanac', aggregate: 'value', observation: $case['field'], body: $case['body'] ?? 'sun',
        latitude: $case['lat'] ?? 48.4596, longitude: $case['lon'] ?? 11.6539, elevation: $case['elevation'] ?? 0.0,
        horizon: $case['horizon'] ?? 0.0, pressure: $case['pressure'] ?? 1010.0, useCenter: $case['center'] ?? false);
    $spec->validate();
    $out[] = (new Astronomy($spec, $archive))->value($case['at']);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
