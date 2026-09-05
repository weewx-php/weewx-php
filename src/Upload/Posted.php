<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/** What one run of an upload did. */
final class Posted
{
    public int $sent = 0;

    /** Records handed over and not sent: the older ones, where the service takes only the newest. */
    public int $skipped = 0;

    /**
     * Records that failed, with why. One bad record does not abandon the
     * rest: a service that rejects a reading from last Tuesday still takes
     * the one from a minute ago.
     *
     * @var list<array{0: int, 1: string}>
     */
    public array $failures = [];

    public string $note = '';

    /**
     * The newest record timestamp the service accepted. The runner writes
     * it down, and that is what makes the next tick continue rather than
     * start again.
     */
    public ?int $through = null;

    public function ok(): bool
    {
        return $this->failures === [];
    }

    public function summary(): string
    {
        $parts = [sprintf('%d sent', $this->sent)];
        if ($this->skipped > 0) {
            $parts[] = sprintf('%d skipped', $this->skipped);
        }
        if ($this->failures !== []) {
            $parts[] = sprintf('%d failed: %s', count($this->failures), $this->failures[0][1]);
        }
        if ($this->note !== '') {
            $parts[] = $this->note;
        }
        return implode(', ', $parts);
    }
}
