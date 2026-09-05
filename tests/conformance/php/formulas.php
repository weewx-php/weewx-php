<?php

declare(strict_types=1);

use WeewxPhp\Weewx\Formulas;

require __DIR__ . '/bootstrap.php';

/*
 * Answers checks/formulas.py from JSON on stdin: a list of calls
 * {name, args}, each answered with the value the named formula returns.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON object on stdin\n");
    exit(1);
}

$number = static fn(mixed $value): ?float => $value === null ? null : (float) $value;

$answers = [];
foreach ($input['calls'] ?? [] as $call) {
    $args = (array) ($call['args'] ?? []);
    $answers[] = match ((string) $call['name']) {
        'dewpointF' => Formulas::dewpointF($number($args[0]), $number($args[1])),
        'dewpointC' => Formulas::dewpointC($number($args[0]), $number($args[1])),
        'windchillF' => Formulas::windchillF($number($args[0]), $number($args[1])),
        'windchillMetric' => Formulas::windchillMetric($number($args[0]), $number($args[1])),
        'windchillMetricWX' => Formulas::windchillMetricWX($number($args[0]), $number($args[1])),
        'heatindexF' => Formulas::heatindexF($number($args[0]), $number($args[1])),
        'heatindexC' => Formulas::heatindexC($number($args[0]), $number($args[1])),
        'humidexC' => Formulas::humidexC($number($args[0]), $number($args[1])),
        'humidexF' => Formulas::humidexF($number($args[0]), $number($args[1])),
        'apptempC' => Formulas::apptempC($number($args[0]), $number($args[1]), $number($args[2])),
        'apptempF' => Formulas::apptempF($number($args[0]), $number($args[1]), $number($args[2])),
        'cloudbase_Metric' => Formulas::cloudbaseMetric($number($args[0]), $number($args[1]), $number($args[2])),
        'cloudbase_US' => Formulas::cloudbaseUS($number($args[0]), $number($args[1]), $number($args[2])),
        'altimeter_pressure_US' => Formulas::altimeterPressureUS($number($args[0]), $number($args[1])),
        'altimeter_pressure_Metric' => Formulas::altimeterPressureMetric($number($args[0]), $number($args[1])),
        'sealevel_pressure_Metric' => Formulas::sealevelPressureMetric($number($args[0]), $number($args[1]), $number($args[2])),
        'sealevel_pressure_US' => Formulas::sealevelPressureUS($number($args[0]), $number($args[1]), $number($args[2])),
        'SeaLevelToSensorPressure_12' => Formulas::sealevelToSensorPressureUS((float) $args[0], (float) $args[1], (float) $args[2], (float) $args[3], (float) $args[4]),
        'solar_rad_RS' => Formulas::solarRadRS((float) $args[0], (float) $args[1], (float) $args[2], (int) $args[3], (float) $args[4]),
        'evapotranspiration_Metric' => Formulas::evapotranspirationMetric(
            $number($args[0]), $number($args[1]), $number($args[2]), $number($args[3]), $number($args[4]),
            $number($args[5]), $number($args[6]), $number($args[7]), $number($args[8]), $number($args[9]),
            (int) $args[10], (float) $args[11],
        ),
        'evapotranspiration_US' => Formulas::evapotranspirationUS(
            $number($args[0]), $number($args[1]), $number($args[2]), $number($args[3]), $number($args[4]),
            $number($args[5]), $number($args[6]), $number($args[7]), $number($args[8]), $number($args[9]),
            (int) $args[10], (float) $args[11],
        ),
        default => throw new RuntimeException('unknown formula ' . (string) $call['name']),
    };
}

echo json_encode(['answers' => $answers], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
