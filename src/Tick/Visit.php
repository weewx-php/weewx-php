<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

/** A public page may request configured work, at most once per minute across all visitors. */
final class Visit
{
    public const EVERY = 60;

    public function __construct(private readonly Runtime $runtime, private readonly ?Dispatcher $dispatcher = null) {}

    /** @phpstan-impure
     * @return array{status: string, queued: list<string>, launcher: string}|null
     */
    public function run(): ?array
    {
        if (!$this->runtime->config->settings->visitTickEnabled) {
            return null;
        }
        $this->runtime->ensureDataDir();
        $path = $this->runtime->config->settings->dataDir . '/visit-tick';
        $file = fopen($path, 'c+');
        if ($file === false) {
            return null;
        }
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $now = $this->runtime->clock->now();
            $last = (int) stream_get_contents($file);
            if ($last > $now - self::EVERY) {
                return null;
            }
            rewind($file);
            $stamp = (string) $now;
            if (fwrite($file, $stamp) !== strlen($stamp) || !ftruncate($file, strlen($stamp)) || !fflush($file)) {
                return null;
            }
            return ($this->dispatcher ?? new Dispatcher($this->runtime))->request();
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
