<?php

declare(strict_types=1);

namespace WeewxPhp\Extension;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Upload\Http\HttpClient;

/** A package can only register within its own namespace. */
final class Registration
{
    /** @var array<string, callable(Context, array<string, mixed>): (Value|Series|Report)> */
    private array $tags = [];
    /** @var callable(Context, Budget, HttpClient): array<string, int|string>|null */
    private $worker = null;

    /** @param callable(Context, array<string, mixed>): (Value|Series|Report) $reader */
    public function tag(string $name, callable $reader): void
    {
        if (preg_match('/^[a-z][a-zA-Z0-9_]{0,47}$/D', $name) !== 1 || isset($this->tags[$name])) {
            throw new \InvalidArgumentException('Invalid or duplicate extension tag');
        }
        $this->tags[$name] = $reader;
    }

    /** @param callable(Context, Budget, HttpClient): array<string, int|string> $worker */
    public function worker(callable $worker): void
    {
        if ($this->worker !== null) {
            throw new \InvalidArgumentException('Extension worker already registered');
        }
        $this->worker = $worker;
    }

    /** @return array<string, callable(Context, array<string, mixed>): (Value|Series|Report)> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return callable(Context, Budget, HttpClient): array<string, int|string>|null */
    public function task(): ?callable
    {
        return $this->worker;
    }
}
