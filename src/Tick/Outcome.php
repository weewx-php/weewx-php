<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

/** What one tick did, for the log, the state and whoever called it. */
final class Outcome
{
    public const OK = 'ok';
    public const BUSY = 'busy';
    public const ERROR = 'error';

    /**
     * @param string $status `ok`, `busy` when another tick held the lock, `error` when an
     *     archive, backup or maintenance job failed; independent work is still done.
     * @param array<string, array<string, mixed>> $archives Per archive: `records` written, or `error`.
     * @param array<string, array<string, mixed>> $stations Per station: `status`, `last_seen`, `since`.
     * @param array<string, array<string, mixed>> $uploads Per upload: `sent`, or `skipped`, `blocked` or `error`.
     * @param array<string, int|string> $backup Latest full backup result.
     * @param array<string, int> $analytics Completed, pending, failed and busy analysis work.
     * @param array<string, array<string, int|string>> $extensions Independent extension work.
     */
    public function __construct(
        public readonly string $status,
        public readonly array $archives,
        public readonly array $stations,
        public readonly int $durationMs,
        public readonly array $uploads = [],
        public readonly array $backup = [],
        public readonly bool $maintenanceOk = true,
        public readonly array $analytics = [],
        public readonly array $extensions = [],
    ) {}

    public static function busy(int $durationMs): self
    {
        return new self(self::BUSY, [], [], $durationMs);
    }

    /** @return array<string, mixed> What tick.php answers with; nothing of the configuration in it. */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'archives' => $this->archives,
            'uploads' => $this->uploads,
            'stations' => $this->stations,
            'duration_ms' => $this->durationMs,
            'backup' => $this->backup,
            'analytics' => $this->analytics,
            'extensions' => $this->extensions,
            'maintenance' => ['status' => $this->maintenanceOk ? 'ok' : 'error'],
        ];
    }
}
