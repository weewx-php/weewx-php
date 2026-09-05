<?php

declare(strict_types=1);

use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

require __DIR__ . '/bootstrap.php';

/*
 * Answers checks/accum.py from JSON on stdin:
 *
 *   cases  [{start, stop, unit_system?, extractors?, loop_hilo?, weight?,
 *            records: [{...}, ...], stored?: {obs: tuple}}, ...]
 *
 * For each case: the records go into one accumulator in order, and the
 * answer holds the record it extracts and the statistics tuple of every
 * type it saw. `stored` primes types the way a day is loaded from its
 * tables; `weight` is what each record counts for, 1 for a LOOP packet and
 * 60 * interval for an archive record.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON object on stdin\n");
    exit(1);
}

$answers = [];
foreach ($input['cases'] ?? [] as $case) {
    $extractors = [];
    foreach ($case['extractors'] ?? [] as $obsType => $name) {
        $extractors[$obsType] = Extractor::from((string) $name);
    }
    $unitSystem = isset($case['unit_system']) ? UnitSystem::from((int) $case['unit_system']) : null;
    $accum = new Accum((int) $case['start'], (int) $case['stop'], $unitSystem, new Policy($extractors));
    foreach ($case['stored'] ?? [] as $obsType => $tuple) {
        $accum->setStats((string) $obsType, $tuple === null ? null : (array) $tuple);
    }
    $addHilo = (bool) ($case['loop_hilo'] ?? true);
    // An integer weight stays an integer, as it does in WeeWX: 1 for a LOOP
    // packet keeps sumtime a count, 60.0 * interval makes it a float.
    $weight = $case['weight'] ?? 1;
    $weight = is_int($weight) ? $weight : (float) $weight;
    foreach ($case['records'] ?? [] as $record) {
        $accum->addRecord((array) $record, $addHilo, $weight);
    }
    $stats = [];
    foreach ($accum->types() as $obsType) {
        $stats[$obsType] = $accum->get($obsType)->statsTuple();
    }
    $answers[] = ['record' => $accum->record(), 'stats' => $stats];
}

echo json_encode(['cases' => $answers], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
