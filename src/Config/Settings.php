<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use DateTimeZone;
use WeewxPhp\Log\LogLevel;

/** The installation-wide settings: the top level of weewx-php.conf. */
final class Settings
{
    /**
     * @param string $dataDir Absolute path of the directory holding the databases, the log and the lock.
     * @param int $archiveInterval Seconds; the same for every archive.
     * @param int $archiveDelay Seconds an interval is held back after it ends.
     * @param int $liveRetention Seconds a packet stays in the live journal.
     * @param int $rawRetention Seconds the raw upload stays beside a packet.
     * @param int $timeBudget Runtime ceiling in seconds; zero learns the host runtime automatically.
     * @param int $maxIntervalsPerRun How many intervals one archive may build per tick.
     * @param string|null $tickToken What an HTTP call of tick.php has to carry; null refuses them all.
     * @param bool $backupEnabled Create a full backup on the first tick of each local day.
     * @param int $backupRetentionDays Retain completed packages for this many elapsed days.
     */
    public function __construct(
        public readonly string $dataDir,
        public readonly DateTimeZone $timezone,
        public readonly int $archiveInterval,
        public readonly int $archiveDelay,
        public readonly bool $loopHilo,
        public readonly LatePackets $latePackets,
        public readonly int $liveRetention,
        public readonly int $rawRetention,
        public readonly int $timeBudget,
        public readonly int $maxIntervalsPerRun,
        public readonly JournalMode $journalMode,
        public readonly ?string $tickToken,
        public readonly LogLevel $logLevel,
        public readonly bool $backupEnabled = true,
        public readonly int $backupRetentionDays = 3,
        public readonly bool $visitTickEnabled = true,
    ) {}

    public function liveDbPath(): string
    {
        return $this->dataDir . '/live.sdb';
    }

    public function ingestDbPath(): string
    {
        return $this->dataDir . '/ingest.sdb';
    }

    public function stateDbPath(): string
    {
        return $this->dataDir . '/state.sdb';
    }

    public function logPath(): string
    {
        return $this->dataDir . '/log/weewx-php.log';
    }

    public function lockPath(): string
    {
        return $this->dataDir . '/tick.lock';
    }
}
