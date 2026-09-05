<?php

declare(strict_types=1);

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\UnitSystem;
use WeewxPhp\Weewx\VecStats;

require __DIR__ . '/bootstrap.php';

/*
 * The archive database, driven from checks/archive.py:
 *
 *   archive.php create <path>              make an empty archive
 *   archive.php add <path>                 records as a JSON list on stdin; prints how many went in
 *   archive.php add-column <path> <name> <type>
 *   archive.php record <path> <ts>         one record as JSON, or null
 *   archive.php count <path>
 *   archive.php day <path> <obs> <sod>     one daily summary row as a JSON list, or null
 *   archive.php meta <path> <name>
 *
 * The zone is the container's, read from TZ: the checks key their days on
 * it, and PHP itself never looks at that variable.
 */
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? '';
$path = array_shift($arguments) ?? '';
if ($command === '' || $path === '') {
    fwrite(STDERR, "usage: archive.php <command> <path> [...]\n");
    exit(2);
}

$zoneName = getenv('TZ');
$zone = new DateTimeZone($zoneName === false || $zoneName === '' ? date_default_timezone_get() : $zoneName);
$archive = ArchiveDb::open($path, JournalMode::Wal, new Policy(), $zone, create: $command === 'create');

switch ($command) {
    case 'create':
        echo json_encode(['created' => $archive->created(), 'columns' => count($archive->schema()->columns)], JSON_THROW_ON_ERROR);
        break;

    case 'add':
        $records = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            fwrite(STDERR, "expected a JSON list of records on stdin\n");
            exit(1);
        }
        $written = 0;
        foreach ($records as $record) {
            if (is_array($record) && $archive->addRecord($record)) {
                $written++;
            }
        }
        echo json_encode(['written' => $written], JSON_THROW_ON_ERROR);
        break;

    case 'add-column':
        [$name, $type] = [$arguments[0] ?? '', $arguments[1] ?? 'REAL'];
        echo json_encode(['added' => $archive->addColumn($name, WeewxPhp\Weewx\ColumnType::fromName($type))], JSON_THROW_ON_ERROR);
        break;

    case 'record':
        echo json_encode($archive->record((int) ($arguments[0] ?? 0)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        break;

    case 'count':
        echo json_encode(['count' => $archive->count()], JSON_THROW_ON_ERROR);
        break;

    case 'day':
        [$obsType, $sod] = [$arguments[0] ?? '', (int) ($arguments[1] ?? 0)];
        $unitSystem = $archive->unitSystem() ?? UnitSystem::US;
        $day = $archive->loadDay($sod, $unitSystem);
        $stats = $day->has($obsType) ? $day->get($obsType) : null;
        $tuple = $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->statsTuple() : null;
        echo json_encode($tuple, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        break;

    case 'meta':
        echo json_encode($archive->getMeta($arguments[0] ?? ''), JSON_THROW_ON_ERROR);
        break;

    case 'add-batch':
        $records = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            fwrite(STDERR, "expected a JSON list of records on stdin\n");
            exit(1);
        }
        $rows = array_values(array_filter($records, 'is_array'));
        echo json_encode(['written' => $archive->addRecords($rows)], JSON_THROW_ON_ERROR);
        break;

    case 'days':
        echo json_encode(iterator_to_array($archive->days(), false), JSON_THROW_ON_ERROR);
        break;

    case 'rebuild-day':
        echo json_encode(['records' => $archive->rebuildDay((int) ($arguments[0] ?? 0))], JSON_THROW_ON_ERROR);
        break;

    case 'rebuild-all':
        $rebuilt = 0;
        foreach (iterator_to_array($archive->days(), false) as $sod) {
            $archive->rebuildDay($sod);
            $rebuilt++;
        }
        echo json_encode(['days' => $rebuilt], JSON_THROW_ON_ERROR);
        break;

    default:
        fwrite(STDERR, "unknown command $command\n");
        exit(2);
}
$archive->close();
