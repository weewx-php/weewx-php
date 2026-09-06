<?php

declare(strict_types=1);

// A real ingest-like writer. The backup must never capture different revisions.
$directory = $argv[1];
$ingest = new SQLite3($directory . '/ingest.sdb');
$live = new SQLite3($directory . '/live.sdb');
foreach ([$ingest, $live] as $db) {
    $db->enableExceptions(true);
    $db->busyTimeout(15000);
}
$revision = 0;
$deadline = microtime(true) + 30;
while (!is_file($directory . '/writer-stop') && microtime(true) < $deadline) {
    $ingest->exec('BEGIN IMMEDIATE');
    $live->exec('BEGIN IMMEDIATE');
    ++$revision;
    $ingest->exec('UPDATE backup_probe SET revision = ' . $revision);
    usleep(1000);
    $live->exec('UPDATE backup_probe SET revision = ' . $revision);
    $live->exec('COMMIT');
    $ingest->exec('COMMIT');
    if ($revision === 1) {
        file_put_contents($directory . '/writer-ready', '1');
    }
    usleep(1000);
    clearstatcache();
}
$live->close();
$ingest->close();
