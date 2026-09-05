<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * One section of a configuration file: its values and its subsections, in
 * the order they were read, with the comments that stood beside them.
 *
 * A key names either a value or a subsection, never both. Values are
 * strings or lists of strings, exactly as the file holds them; {@see Value}
 * turns them into what a setting needs.
 */
final class Section
{
    /** @var array<string, string|list<string>|Section> */
    private array $entries = [];

    /**
     * The lines that stood before each entry, as read: comment lines with
     * their '#', blank lines as ''.
     *
     * @var array<string, list<string>>
     */
    private array $comments = [];

    /**
     * The comment on the entry's own line, with its '#', or '' for none.
     *
     * @var array<string, string>
     */
    private array $inlineComments = [];

    /**
     * @param string $name The name between the brackets; '' for the root.
     * @param int $depth How many brackets the name stands in; 0 for the root.
     */
    public function __construct(
        private readonly string $name,
        private readonly int $depth,
        private readonly ?self $parent = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    /** '[Archives][[kirchdorf]]' for a subsection, '' for the root. */
    public function path(): string
    {
        if ($this->parent === null) {
            return '';
        }
        return $this->parent->path() . str_repeat('[', $this->depth) . $this->name . str_repeat(']', $this->depth);
    }

    /** The path of a value in this section, for error messages. */
    public function pathOf(string $key): string
    {
        $own = $this->path();
        return $own === '' ? $key : $own . ' ' . $key;
    }

    /** @return list<string> Every key, values and subsections alike, in file order. */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function isSection(string $key): bool
    {
        return ($this->entries[$key] ?? null) instanceof self;
    }

    /** @return array<string, Section> The subsections, in file order. */
    public function sections(): array
    {
        $found = [];
        foreach ($this->entries as $key => $entry) {
            if ($entry instanceof self) {
                $found[$key] = $entry;
            }
        }
        return $found;
    }

    /** @return array<string, Value> The values, in file order. */
    public function values(): array
    {
        $found = [];
        foreach ($this->entries as $key => $entry) {
            if (!$entry instanceof self) {
                $found[$key] = new Value($this->pathOf($key), $entry);
            }
        }
        return $found;
    }

    /**
     * @throws ConfigError If there is no such subsection.
     */
    public function section(string $key): self
    {
        $entry = $this->entries[$key] ?? null;
        if (!$entry instanceof self) {
            throw ConfigError::at($this->pathOf($key), $entry === null ? 'section is missing' : 'expected a section, found a value');
        }
        return $entry;
    }

    public function optionalSection(string $key): ?self
    {
        return $this->has($key) ? $this->section($key) : null;
    }

    /**
     * @throws ConfigError If the key is missing or names a subsection.
     */
    public function value(string $key): Value
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            throw ConfigError::at($this->pathOf($key), 'setting is missing');
        }
        if ($entry instanceof self) {
            throw ConfigError::at($entry->path(), 'expected a value, found a section');
        }
        return new Value($this->pathOf($key), $entry);
    }

    public function optional(string $key): ?Value
    {
        return $this->has($key) ? $this->value($key) : null;
    }

    /**
     * Set a value, keeping any comments the key already has.
     *
     * @param string|list<string> $value
     *
     * @throws ConfigError If the key names a subsection.
     */
    public function set(string $key, string|array $value): void
    {
        if ($this->isSection($key)) {
            throw ConfigError::at($this->pathOf($key), 'cannot replace a section with a value');
        }
        $this->entries[$key] = $value;
        $this->comments[$key] ??= [];
        $this->inlineComments[$key] ??= '';
    }

    /**
     * @throws ConfigError If the key already exists.
     */
    public function addSection(string $name): self
    {
        if ($this->has($name)) {
            throw ConfigError::at($this->pathOf($name), 'already exists');
        }
        $section = new self($name, $this->depth + 1, $this);
        $this->entries[$name] = $section;
        $this->comments[$name] = [];
        $this->inlineComments[$name] = '';
        return $section;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key], $this->comments[$key], $this->inlineComments[$key]);
    }

    /** @return list<string> */
    public function comments(string $key): array
    {
        return $this->comments[$key] ?? [];
    }

    /** @param list<string> $lines */
    public function setComments(string $key, array $lines): void
    {
        $this->requireKey($key);
        $this->comments[$key] = $lines;
    }

    public function inlineComment(string $key): string
    {
        return $this->inlineComments[$key] ?? '';
    }

    public function setInlineComment(string $key, string $comment): void
    {
        $this->requireKey($key);
        $this->inlineComments[$key] = $comment;
    }

    private function requireKey(string $key): void
    {
        if (!$this->has($key)) {
            throw ConfigError::at($this->pathOf($key), 'no such key');
        }
    }
}
