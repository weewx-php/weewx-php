<?php

declare(strict_types=1);

use WeewxPhp\Weewx\UnitError;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

require __DIR__ . '/bootstrap.php';

/*
 * Answers the questions checks/units.py asks, from JSON on stdin:
 *
 *   pairs    [[from, to], ...]   every conversion, applied to `samples`
 *   samples  [x, ...]
 *   obs      [name, ...]         the group of each, and its unit per system
 *   records  [{...}, ...]        each converted into each of the three systems
 *
 * A pair the table does not hold answers null, so the check can say which
 * conversion is missing rather than the helper dying on the first one.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON object on stdin\n");
    exit(1);
}

$samples = $input['samples'] ?? [];
$conversions = [];
foreach ($input['pairs'] ?? [] as [$from, $to]) {
    $values = [];
    foreach ($samples as $sample) {
        try {
            $values[] = Units::convert((float) $sample, (string) $from, (string) $to);
        } catch (UnitError) {
            $values = null;
            break;
        }
    }
    $conversions[$from . '>' . $to] = $values;
}

$groups = [];
$units = [];
foreach ($input['obs'] ?? [] as $obs) {
    $groups[$obs] = Units::groupOf((string) $obs);
    foreach (UnitSystem::cases() as $system) {
        $units[$obs][$system->value] = Units::unitOf($system, (string) $obs)[0];
    }
}

$records = [];
foreach ($input['records'] ?? [] as $index => $record) {
    foreach (UnitSystem::cases() as $system) {
        $records[$index][$system->value] = Units::toSystem((array) $record, $system);
    }
}

echo json_encode([
    'conversions' => $conversions,
    'groups' => $groups,
    'units' => $units,
    'records' => $records,
], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
