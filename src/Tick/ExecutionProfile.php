<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use WeewxPhp\Log\Logger;
use WeewxPhp\State\StateDb;
use WeewxPhp\Time\Clock;

/** A conservative wall-time window. Its caller owns the corresponding worker lock. */
final class ExecutionProfile
{
    private readonly float $started;
    private readonly float $maximum;
    private readonly float $seconds;
    private float $ceiling = 0;
    private int $checkpointAt = -1;

    public function __construct(
        private readonly StateDb $state,
        private readonly Clock $clock,
        private readonly Logger $log,
        private readonly string $name,
        private readonly int $phpLimit,
        int $configured,
        float $available,
    ) {
        $this->started = $clock->monotonic();
        $this->maximum = min($available, self::limit($configured, $phpLimit));
        $saved = $state->executionProfile($name);
        $sameHostLimit = ($saved['php_limit'] ?? null) === $phpLimit;
        $next = $sameHostLimit ? self::number($saved['next'] ?? null) : 0;
        $this->ceiling = $sameHostLimit ? self::number($saved['ceiling'] ?? null) : 0;
        if ($sameHostLimit && ($saved['active'] ?? false) === true) {
            $last = self::number($saved['elapsed'] ?? null);
            $attempted = self::number($saved['seconds'] ?? null);
            $this->ceiling = max(1.0, min($attempted * 0.7, $last > 1 ? $last * 0.8 : $attempted * 0.5));
            $log->warning(sprintf('runtime %s: previous process interrupted; safe runtime reduced to %.1f s (host limit not proven)', $name, $this->ceiling));
        }
        $initial = $configured > 0 || $phpLimit > 0 ? $this->maximum : 20.0;
        $this->seconds = max(0.0, min($this->maximum, $next > 0 ? $next : $initial, $this->ceiling > 0 ? $this->ceiling : INF));
        $this->save(true, $this->seconds);
        $log->info(sprintf('runtime %s: %.1f s budget, PHP limit %d s', $name, $this->seconds, $phpLimit));
    }

    public static function limit(int $configured, int $phpLimit, float $requestElapsed = 0): float
    {
        $maximum = $configured > 0 ? (float) $configured : 300.0;
        if ($phpLimit > 0) {
            $maximum = min($maximum, $phpLimit - max(2.0, $phpLimit * 0.1) - $requestElapsed);
        }
        return max(0.0, $maximum);
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function checkpoint(): void
    {
        $elapsed = (int) floor($this->clock->monotonic() - $this->started);
        if ($elapsed !== $this->checkpointAt) {
            $this->checkpointAt = $elapsed;
            $this->save(true, $this->seconds);
        }
    }

    public function finish(bool $exhausted): void
    {
        $elapsed = $this->clock->monotonic() - $this->started;
        $next = $this->seconds;
        if ($exhausted && $elapsed >= $this->seconds * 0.9 && $this->ceiling === 0.0) {
            $next = min($this->maximum, max($next + 1, $next * 1.25));
        }
        $this->save(false, $next);
    }

    private function save(bool $active, float $next): void
    {
        $this->state->saveExecutionProfile($this->name, ['php_limit' => $this->phpLimit, 'seconds' => $this->seconds,
            'next' => $next, 'ceiling' => $this->ceiling, 'active' => $active,
            'elapsed' => round($this->clock->monotonic() - $this->started, 3)]);
    }

    private static function number(mixed $value): float
    {
        return (is_float($value) || is_int($value)) && is_finite((float) $value) ? max(0.0, (float) $value) : 0.0;
    }
}
