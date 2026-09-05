<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** Shared by every query in one page, including iterations and cache misses. */
final class ReadBudget
{
    private readonly float $started;
    private int $rows = 0;
    private int $statements = 0;
    private int $cacheHits = 0;

    public function __construct(
        public readonly int $maxRows = 12000,
        public readonly int $maxStatements = 128,
        public readonly float $milliseconds = 200,
    ) {
        $this->started = hrtime(true) / 1000000;
    }

    public function statement(): void
    {
        if (++$this->statements > $this->maxStatements || $this->expired()) {
            throw new Deferred('Page query budget exhausted');
        }
    }

    public function row(): void
    {
        if (++$this->rows > $this->maxRows || $this->expired()) {
            throw new Deferred('Page row budget exhausted');
        }
    }

    /** @var array<string, true> */
    private array $sources = [];

    public function resetSources(): void
    {
        $this->sources = [];
        $this->cacheHits = 0;
    }

    public function source(string $source): void
    {
        $this->sources[$source] = true;
        if (in_array($source, ['aggregate_state', 'series_chunk'], true)) {
            ++$this->cacheHits;
        }
    }

    /** @return array{rows: int, statements: int, milliseconds: float, sources: list<string>, cacheHits: int} */
    public function snapshot(): array
    {
        return ['rows' => $this->rows, 'statements' => $this->statements,
            'milliseconds' => round(hrtime(true) / 1000000 - $this->started, 3), 'sources' => array_keys($this->sources), 'cacheHits' => $this->cacheHits];
    }

    public function expired(): bool
    {
        return hrtime(true) / 1000000 - $this->started >= $this->milliseconds;
    }
}
