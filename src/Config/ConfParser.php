<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * Reads the format of weewx.conf: the subset of ConfigObj that WeeWX uses.
 *
 * Sections are named by bracket depth, values are strings or comma lists,
 * quotes protect commas and hashes, and every comment is kept so the file
 * can be written back as it was. Triple-quoted values and interpolation are
 * not part of the subset and are refused rather than guessed at.
 *
 * @internal Use {@see ConfFile::parse()}.
 */
final class ConfParser
{
    private Section $current;

    /** @var list<string> The comment lines seen since the last entry. */
    private array $pending = [];

    /** @var list<string>|null The block before the first entry, once one was seen. */
    private ?array $initial = null;

    private function __construct(private readonly Section $root)
    {
        $this->current = $root;
    }

    public static function parse(string $text): ConfFile
    {
        $newline = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if ($lines === false) {
            throw new ConfigError('cannot split the file into lines');
        }
        // A file ending in a newline has nothing after it; that is not an
        // empty final line to keep.
        if ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        $parser = new self(new Section('', 0));
        foreach ($lines as $index => $line) {
            $parser->takeLine($index + 1, $line);
        }
        return new ConfFile($parser->root, $parser->initial ?? [], $parser->pending, $newline);
    }

    private function takeLine(int $number, string $line): void
    {
        $stripped = trim($line);
        if ($stripped === '' || $stripped[0] === '#') {
            $this->pending[] = $line;
            return;
        }
        if ($stripped[0] === '[') {
            $this->takeSectionMarker($number, $stripped);
            return;
        }
        $this->takeKeyValue($number, $stripped);
    }

    private function takeSectionMarker(int $number, string $line): void
    {
        if (preg_match('/^((?:\[\s*)+)(.*?)((?:\s*\])+)\s*(#.*)?$/', $line, $found) !== 1) {
            throw ConfigError::atLine($number, 'malformed section marker');
        }
        $depth = substr_count($found[1], '[');
        if (substr_count($found[3], ']') !== $depth) {
            throw ConfigError::atLine($number, 'opening and closing brackets do not match');
        }
        $name = self::unquote(trim($found[2]));
        if ($name === '') {
            throw ConfigError::atLine($number, 'section has no name');
        }
        if ($depth > $this->current->depth() + 1) {
            throw ConfigError::atLine($number, sprintf('section %s is nested %d deep under a section %d deep', $name, $depth, $this->current->depth()));
        }
        $parent = $this->current;
        while ($parent->depth() >= $depth) {
            $parent = $parent->parent() ?? throw ConfigError::atLine($number, 'section nesting is broken');
        }
        if ($parent->has($name)) {
            $path = $parent->path() . str_repeat('[', $depth) . $name . str_repeat(']', $depth);
            throw ConfigError::atLine($number, sprintf('duplicate section %s', $path));
        }
        $section = $parent->addSection($name);
        $this->attachComments($parent, $name, $found[4] ?? '');
        $this->current = $section;
    }

    private function takeKeyValue(int $number, string $line): void
    {
        [$key, $rest] = self::splitKey($number, $line);
        if ($this->current->has($key)) {
            throw ConfigError::atLine($number, sprintf('duplicate key %s', $this->current->pathOf($key)));
        }
        [$value, $comment] = self::parseValue($number, $rest);
        $this->current->set($key, $value);
        $this->attachComments($this->current, $key, $comment);
    }

    /**
     * @return array{0: string, 1: string} The key, and everything after the '='.
     */
    private static function splitKey(int $number, string $line): array
    {
        $first = $line[0];
        if ($first === '"' || $first === "'") {
            $end = strpos($line, $first, 1);
            if ($end === false) {
                throw ConfigError::atLine($number, 'unterminated quote in key');
            }
            $key = substr($line, 1, $end - 1);
            $after = ltrim(substr($line, $end + 1));
        } else {
            $equals = strpos($line, '=');
            if ($equals === false) {
                throw ConfigError::atLine($number, 'expected key = value or a [section]');
            }
            $key = rtrim(substr($line, 0, $equals));
            $after = substr($line, $equals);
        }
        if (!str_starts_with($after, '=')) {
            throw ConfigError::atLine($number, 'expected = after the key');
        }
        if ($key === '') {
            throw ConfigError::atLine($number, 'key is empty');
        }
        return [$key, substr($after, 1)];
    }

    /**
     * The value part of a line, the way ConfigObj reads it: items separated
     * by commas, a trailing comma or more than one item making a list, a
     * lone comma an empty list, and a '#' outside quotes starting the
     * comment.
     *
     * @return array{0: string|list<string>, 1: string} The value and the comment with its '#'.
     */
    private static function parseValue(int $number, string $text): array
    {
        $text = ltrim($text);
        if (str_starts_with($text, '"""') || str_starts_with($text, "'''")) {
            throw ConfigError::atLine($number, 'triple-quoted values are not supported');
        }
        $length = strlen($text);
        $position = 0;
        $items = [];
        $trailingComma = false;
        $emptyList = false;
        $comment = '';

        while (true) {
            while ($position < $length && ($text[$position] === ' ' || $text[$position] === "\t")) {
                $position++;
            }
            if ($position >= $length) {
                break;
            }
            $char = $text[$position];
            if ($char === '#') {
                $comment = substr($text, $position);
                break;
            }
            if ($char === ',') {
                if ($items !== [] || $emptyList) {
                    throw ConfigError::atLine($number, 'unexpected comma');
                }
                $emptyList = true;
                $position++;
                continue;
            }
            if ($emptyList) {
                throw ConfigError::atLine($number, 'unexpected text after an empty list');
            }
            if ($char === '"' || $char === "'") {
                $end = strpos($text, $char, $position + 1);
                if ($end === false) {
                    throw ConfigError::atLine($number, 'unterminated quote in value');
                }
                $items[] = substr($text, $position + 1, $end - $position - 1);
                $position = $end + 1;
            } else {
                $start = $position;
                while ($position < $length && $text[$position] !== ',' && $text[$position] !== '#') {
                    $position++;
                }
                $items[] = rtrim(substr($text, $start, $position - $start));
            }
            $trailingComma = false;
            while ($position < $length && ($text[$position] === ' ' || $text[$position] === "\t")) {
                $position++;
            }
            if ($position < $length && $text[$position] === ',') {
                $trailingComma = true;
                $position++;
                continue;
            }
            if ($position < $length && $text[$position] !== '#') {
                throw ConfigError::atLine($number, 'unexpected text after a quoted value');
            }
        }

        if ($emptyList) {
            return [[], $comment];
        }
        if ($items === []) {
            return ['', $comment];
        }
        if (count($items) === 1 && !$trailingComma) {
            return [$items[0], $comment];
        }
        return [$items, $comment];
    }

    private static function unquote(string $text): string
    {
        $length = strlen($text);
        if ($length >= 2 && ($text[0] === '"' || $text[0] === "'") && $text[$length - 1] === $text[0]) {
            return substr($text, 1, $length - 2);
        }
        return $text;
    }

    /**
     * The comment lines gathered since the previous entry belong to this
     * one -- except before the very first entry, where ConfigObj keeps them
     * as the file's opening comment instead.
     */
    private function attachComments(Section $section, string $key, string $inline): void
    {
        if ($this->initial === null) {
            $this->initial = $this->pending;
        } else {
            $section->setComments($key, $this->pending);
        }
        $section->setInlineComment($key, $inline);
        $this->pending = [];
    }
}
