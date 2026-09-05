<?php

declare(strict_types=1);

use WeewxPhp\Config\JournalMode;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Weewx\UnitSystem;

require __DIR__ . '/bootstrap.php';

/*
 * The live journal, fed from checks/tick.py:
 *
 *   live.php add <path> <interval> <archive,...>
 *
 * with packets as a JSON list on stdin, each {dateTime, usUnits, sender,
 * data, kind?, interval?}. Prints how many were new.
 */
$arguments = array_slice($argv, 1);
$command = array_shift($arguments) ?? '';
$path = array_shift($arguments) ?? '';
if ($command !== 'add' || $path === '') {
    fwrite(STDERR, "usage: live.php add <path> <interval> <archive,...>\n");
    exit(2);
}
$interval = (int) ($arguments[0] ?? 300);
$archives = explode(',', $arguments[1] ?? 'kirchdorf');

$input = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($input)) {
    fwrite(STDERR, "expected a JSON list of packets on stdin\n");
    exit(1);
}

$live = LiveDb::open($path, JournalMode::Wal);
$packets = [];
foreach ($input as $one) {
    if (!is_array($one) || !is_array($one['data'] ?? null)) {
        continue;
    }
    $sender = (string) ($one['sender'] ?? 'ecowitt');
    $packets[] = new Packet(
        (int) $one['dateTime'],
        UnitSystem::from((int) ($one['usUnits'] ?? 17)),
        $one['data'],
        $sender,
        $sender,
        strtoupper($sender),
        null,
        null,
        PacketKind::from((string) ($one['kind'] ?? 'loop')),
        isset($one['interval']) ? (float) $one['interval'] : null,
        (int) $one['dateTime'] + 1,
    );
}
$added = $live->addAll($packets, $archives, $interval);
$live->close();

echo json_encode(['added' => $added], JSON_THROW_ON_ERROR);
