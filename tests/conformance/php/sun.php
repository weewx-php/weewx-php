<?php

declare(strict_types=1);

use WeewxPhp\Weewx\Sun;

require __DIR__ . '/bootstrap.php';

/*
 * Answers checks/sun.py from JSON on stdin:
 *
 *   places  [[latitude, longitude], ...]
 *   times   [unix timestamp, ...]
 *
 * For every place and time: the sun's geometric elevation in degrees, the
 * Earth's distance in AU, and the elevation as seen through the air at
 * the pressure and temperature WeeWX's almanac assumes.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON object on stdin\n");
    exit(1);
}

$answers = [];
foreach ($input['places'] ?? [] as [$latitude, $longitude]) {
    $row = [];
    foreach ($input['times'] ?? [] as $when) {
        [$elevation, $distance] = Sun::position((int) $when, (float) $latitude, (float) $longitude);
        $row[] = [$elevation, $distance, Sun::apparent($elevation)];
    }
    $answers[] = $row;
}

echo json_encode(['positions' => $answers], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
