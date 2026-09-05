<?php

declare(strict_types=1);

namespace WeewxPhp\Log;

/**
 * Keeps every line in memory. For tests, and for a CLI that prints what a
 * run said once it is over.
 */
final class MemoryLogger implements Logger
{
    use LogLevelMethods;

    /** @var list<array{LogLevel, string}> */
    private array $lines = [];

    public function log(LogLevel $level, string $message): void
    {
        $this->lines[] = [$level, $message];
    }

    /** @return list<array{LogLevel, string}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Only the messages at or above a level, in order.
     *
     * @return list<string>
     */
    public function messages(LogLevel $atLeast = LogLevel::Debug): array
    {
        $found = [];
        foreach ($this->lines as [$level, $message]) {
            if ($level->value >= $atLeast->value) {
                $found[] = $message;
            }
        }
        return $found;
    }
}
