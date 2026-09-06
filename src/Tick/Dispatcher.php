<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use RuntimeException;

/** Durable, coalesced wakeups. HTTP callers never execute queued work. */
final class Dispatcher
{
    public const LANES = ['archive', 'analytics', 'services', 'maintenance'];

    /** @param callable(string): bool|null $launch Test/external process launcher. */
    public function __construct(private readonly Runtime $runtime, private readonly mixed $launch = null) {}

    public function directory(): string
    {
        return $this->runtime->config->settings->dataDir . '/jobs';
    }

    /** @param list<string> $lanes
     * @return array{status: string, queued: list<string>, launcher: string}
     */
    public function request(array $lanes = self::LANES): array
    {
        $this->runtime->ensureDataDir();
        $dir = $this->directory();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create worker queue');
        }
        $launcher = 'background';
        foreach ($lanes as $lane) {
            self::validate($lane);
            // A fixed marker coalesces repeated requests without a database write lock.
            if (!touch($dir . '/' . $lane . '.requested')) {
                throw new RuntimeException('Cannot persist worker request');
            }
            $probe = Lock::tryAcquire($dir . '/' . $lane . '.lock');
            if ($probe === null) {
                continue;
            }
            $probe->release();
            $started = $this->launch !== null ? ($this->launch)($lane) : $this->spawn($lane);
            if (!$started) {
                $launcher = 'external-required';
            }
        }
        return ['status' => 'queued', 'queued' => $lanes, 'launcher' => $launcher];
    }

    public static function validate(string $lane): void
    {
        if (!in_array($lane, self::LANES, true)) {
            throw new RuntimeException('Unknown worker lane');
        }
    }

    private function spawn(string $lane): bool
    {
        $config = $this->runtime->configPath();
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('exec') || $config === null) {
            return false;
        }
        $php = null;
        foreach (['/usr/bin/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION, '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION, '/usr/bin/php', PHP_BINDIR . '/php', '/usr/local/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $php = $candidate;
                break;
            }
        }
        if ($php === null) {
            return false;
        }
        // Only fixed executable/script paths and server-side configuration are passed.
        // All standard streams are detached; the shell exits without waiting for PHP.
        $command = implode(' ', array_map('escapeshellarg', [$php, dirname(__DIR__, 2) . '/bin/worker.php', $config, $lane]));
        $output = [];
        $code = 1;
        exec($command . ' </dev/null >>' . escapeshellarg($this->directory() . '/worker-errors.log') . ' 2>&1 &', $output, $code);
        return $code === 0;
    }
}
