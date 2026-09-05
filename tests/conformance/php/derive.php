<?php

declare(strict_types=1);

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Derivable;
use WeewxPhp\Archive\Derived;
use WeewxPhp\Archive\How;
use WeewxPhp\Archive\Site;
use WeewxPhp\Config\Altitude;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Weewx\Policy;

require __DIR__ . '/bootstrap.php';

/*
 * Answers checks/derive.py from JSON on stdin:
 *
 *   archive    path of an archive to look twelve hours and an hour back in
 *   site       {latitude, longitude, altitude: [value, unit]}
 *   timezone   the zone a day is counted in
 *   calculate  {obsType: how}, overriding the defaults; optional
 *   seed       packets before the span, [{sender, data}]
 *   packets    [{sender, data}], in time order
 *   records    finished records, interval included
 *
 * with {packets: [...], records: [...]}: each with what was derived.
 */
$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_array($input['site'] ?? null)) {
    fwrite(STDERR, "expected a JSON object with a site on stdin\n");
    exit(1);
}

$zone = new DateTimeZone((string) ($input['timezone'] ?? 'UTC'));
$siteInput = $input['site'];
$altitude = is_array($siteInput['altitude'] ?? null)
    ? new Altitude((float) $siteInput['altitude'][0], (string) $siteInput['altitude'][1])
    : null;
$site = new Site(
    isset($siteInput['latitude']) ? (float) $siteInput['latitude'] : null,
    isset($siteInput['longitude']) ? (float) $siteInput['longitude'] : null,
    $altitude,
    $zone,
);

$overrides = [];
foreach ((array) ($input['calculate'] ?? []) as $obsType => $how) {
    $overrides[(string) $obsType] = How::from((string) $how);
}

$archive = ArchiveDb::open((string) $input['archive'], JournalMode::Wal, new Policy(), $zone);
$derived = new Derived($site, $archive, Derivable::policy($overrides));

/** @param mixed $items */
$each = static function (mixed $items): Generator {
    foreach ((array) $items as $item) {
        if (is_array($item) && is_array($item['data'] ?? null)) {
            yield (string) ($item['sender'] ?? 'primary') => $item['data'];
        }
    }
};

foreach ($each($input['seed'] ?? []) as $sender => $data) {
    $derived->seed($data, $sender);
}
$packets = [];
foreach ($each($input['packets'] ?? []) as $sender => $data) {
    $packets[] = $derived->applyPacket($data, $sender);
}
$records = [];
foreach ((array) ($input['records'] ?? []) as $record) {
    if (is_array($record)) {
        $records[] = $derived->applyRecord($record);
    }
}
$archive->close();

echo json_encode(['packets' => $packets, 'records' => $records], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
