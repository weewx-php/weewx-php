<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use RuntimeException;

/**
 * One tick at a time. Two of them over the same journal would build the
 * same intervals and race for the same day rows, so the second one is
 * told the first is busy and does nothing; the work keeps.
 *
 * An advisory lock on a file, which every host has, released when the
 * process ends however it ends.
 */
final class Lock
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    /**
     * The lock, or null when another process holds it.
     *
     * @throws RuntimeException If the lock file cannot be opened at all.
     */
    public static function tryAcquire(string $path): ?self
    {
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open lock file %s', $path));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return new self($handle);
    }

    public function release(): void
    {
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }
}
