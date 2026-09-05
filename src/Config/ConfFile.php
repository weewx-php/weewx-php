<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use RuntimeException;

/**
 * A configuration file in the format of weewx.conf, read with its comments
 * and written back with them.
 */
final class ConfFile
{
    /**
     * @param list<string> $initialComment The lines before the first entry.
     * @param list<string> $finalComment The lines after the last entry.
     * @param string $newline The line ending the file uses.
     */
    public function __construct(
        private readonly Section $root,
        private array $initialComment,
        private array $finalComment,
        private readonly string $newline = "\n",
    ) {}

    /**
     * @throws ConfigError If a line cannot be read.
     */
    public static function parse(string $text): self
    {
        return ConfParser::parse($text);
    }

    /**
     * @throws ConfigError If the file cannot be read or a line cannot be parsed.
     */
    public static function read(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigError(sprintf('configuration file %s does not exist', $path));
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new ConfigError(sprintf('configuration file %s cannot be read', $path));
        }
        try {
            return self::parse($text);
        } catch (ConfigError $error) {
            throw new ConfigError(sprintf('%s: %s', $path, $error->getMessage()), 0, $error);
        }
    }

    public function root(): Section
    {
        return $this->root;
    }

    /** @return list<string> */
    public function initialComment(): array
    {
        return $this->initialComment;
    }

    /** @param list<string> $lines */
    public function setInitialComment(array $lines): void
    {
        $this->initialComment = $lines;
    }

    /** @return list<string> */
    public function finalComment(): array
    {
        return $this->finalComment;
    }

    /** @param list<string> $lines */
    public function setFinalComment(array $lines): void
    {
        $this->finalComment = $lines;
    }

    public function toString(): string
    {
        return ConfWriter::write($this->root, $this->initialComment, $this->finalComment, $this->newline);
    }

    /**
     * Write the file in one move: to a sibling first, then renamed into
     * place, so an interrupted write cannot leave half a configuration. The
     * mode of an existing file is kept.
     *
     * @throws RuntimeException If the file cannot be written.
     */
    public function write(string $path): void
    {
        $text = $this->toString();
        $temporary = sprintf('%s.%d.tmp', $path, getmypid());
        if (file_put_contents($temporary, $text) === false) {
            throw new RuntimeException(sprintf('cannot write %s', $temporary));
        }
        if (is_file($path)) {
            $mode = fileperms($path);
            if ($mode !== false) {
                chmod($temporary, $mode & 0o7777);
            }
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException(sprintf('cannot move %s into place as %s', $temporary, $path));
        }
    }
}
