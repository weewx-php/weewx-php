<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/**
 * Writes a configuration file the way ConfigObj writes weewx.conf, down to
 * the indentation of blank lines: a file WeeWX wrote comes back byte for
 * byte, and a file written here reads the same to WeeWX.
 *
 * @internal Use {@see ConfFile::toString()}.
 */
final class ConfWriter
{
    private const INDENT = '    ';

    /** @var list<string> */
    private array $out = [];

    private function __construct() {}

    /**
     * @param list<string> $initialComment
     * @param list<string> $finalComment
     */
    public static function write(Section $root, array $initialComment, array $finalComment, string $newline): string
    {
        $writer = new self();
        foreach ($initialComment as $line) {
            $writer->out[] = self::outerComment($line);
        }
        $writer->section($root);
        foreach ($finalComment as $line) {
            $writer->out[] = self::outerComment($line);
        }
        $text = implode($newline, $writer->out);
        return str_ends_with($text, $newline) ? $text : $text . $newline;
    }

    private function section(Section $section): void
    {
        $indent = str_repeat(self::INDENT, $section->depth());
        foreach ($section->keys() as $key) {
            foreach ($section->comments($key) as $line) {
                $this->out[] = $indent . self::innerComment($line);
            }
            $inline = self::inlineComment($section->inlineComment($key));
            if ($section->isSection($key)) {
                $child = $section->section($key);
                $this->out[] = $indent . str_repeat('[', $child->depth()) . self::quote($key) . str_repeat(']', $child->depth()) . $inline;
                $this->section($child);
            } else {
                $this->out[] = $indent . self::quote($key) . ' = ' . self::value($section->value($key)->raw()) . $inline;
            }
        }
    }

    /**
     * A comment line before an entry: re-indented, and given its '#' if the
     * line was set without one.
     */
    private static function innerComment(string $line): string
    {
        $line = ltrim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            return '# ' . $line;
        }
        return $line;
    }

    /** A line of the opening or closing block: kept as it is, apart from a missing '#'. */
    private static function outerComment(string $line): string
    {
        $stripped = trim($line);
        if ($stripped !== '' && !str_starts_with($stripped, '#')) {
            return '# ' . $line;
        }
        return $line;
    }

    private static function inlineComment(string $comment): string
    {
        if ($comment === '') {
            return '';
        }
        return self::INDENT . (str_starts_with($comment, '#') ? $comment : ' # ' . $comment);
    }

    /** @param string|list<string> $value */
    private static function value(string|array $value): string
    {
        if (is_string($value)) {
            return self::quote($value);
        }
        if ($value === []) {
            return ',';
        }
        if (count($value) === 1) {
            return self::quote($value[0]) . ',';
        }
        return implode(', ', array_map(self::quote(...), $value));
    }

    /**
     * Quote a value or key when reading it back would otherwise go wrong:
     * a comma or '#' inside it, whitespace or a quote at either end, or
     * nothing at all.
     */
    private static function quote(string $text): string
    {
        if ($text === '') {
            return '""';
        }
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            throw new ConfigError(sprintf('value %s spans lines, which this format cannot hold', var_export($text, true)));
        }
        $edge = ' ' . "\t" . "\v" . '\'"';
        $needsQuotes = str_contains($edge, $text[0])
            || str_contains($edge, $text[strlen($text) - 1])
            || str_contains($text, ',')
            || str_contains($text, '#');
        if (!$needsQuotes) {
            return $text;
        }
        if (str_contains($text, '"') && str_contains($text, "'")) {
            throw new ConfigError(sprintf('value %s holds both kinds of quote and cannot be written', var_export($text, true)));
        }
        $quote = str_contains($text, '"') ? "'" : '"';
        return $quote . $text . $quote;
    }
}
