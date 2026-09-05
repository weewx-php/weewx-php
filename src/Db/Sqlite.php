<?php

declare(strict_types=1);

namespace WeewxPhp\Db;

use Generator;
use SQLite3;
use SQLite3Stmt;
use Throwable;
use WeewxPhp\Config\JournalMode;

/**
 * One SQLite file, opened through the sqlite3 extension rather than PDO.
 *
 * PDO has no floating-point parameter: a float is bound as text, rendered
 * with the `precision` setting, which is 14 digits by default and turns
 * 0.30000000000000004 into 0.3. A record written that way is not the record
 * WeeWX would have written. sqlite3 binds a double as a double, and reads
 * one back as one.
 *
 * Every statement takes positional parameters, bound by their PHP type:
 * int, float, string, bool and null each go in as themselves.
 */
final class Sqlite
{
    private int $depth = 0;

    private function __construct(private readonly SQLite3 $connection, private readonly string $path) {}

    /**
     * @param bool $create Whether a missing file may be created; without it a missing file is an error.
     * @param string $synchronous SQLite's `synchronous` pragma: `FULL` for a record that must survive
     *     a power cut, `NORMAL` for a journal that can afford to lose its last second.
     *
     * @throws DbError If the file cannot be opened.
     */
    public static function open(string $path, bool $create, JournalMode $journalMode, string $synchronous = 'FULL', int $busyTimeout = 5000): self
    {
        if (!$create && !is_file($path)) {
            throw new DbError(sprintf('Database %s does not exist', $path));
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DbError(sprintf('Cannot create directory %s', $directory));
        }
        try {
            $flags = SQLITE3_OPEN_READWRITE | ($create ? SQLITE3_OPEN_CREATE : 0);
            $connection = new SQLite3($path, $flags);
        } catch (Throwable $error) {
            throw new DbError(sprintf('Cannot open %s: %s', $path, $error->getMessage()), 0, $error);
        }
        $connection->enableExceptions(true);
        // Five seconds, as WeeWX's own driver waits: a tick and an ingest may
        // want the same file at the same moment, and neither should give up
        // on a lock that lasts milliseconds.
        $connection->busyTimeout($busyTimeout);
        $db = new self($connection, $path);
        $db->exec('PRAGMA journal_mode=' . strtoupper($journalMode->value));
        $db->exec('PRAGMA synchronous=' . $synchronous);
        return $db;
    }

    public function path(): string
    {
        return $this->path;
    }

    public static function readOnly(string $path): self
    {
        if (!is_file($path)) {
            throw new DbError('Database does not exist');
        }
        $connection = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $connection->enableExceptions(true);
        $connection->busyTimeout(100);
        return new self($connection, $path);
    }

    /**
     * Run a statement and return how many rows it changed.
     *
     * @param list<int|float|string|bool|null> $params
     */
    public function exec(string $sql, array $params = []): int
    {
        if ($params === []) {
            $this->guard(fn(): bool => $this->connection->exec($sql), $sql);
            return $this->connection->changes();
        }
        $statement = $this->prepare($sql, $params);
        $result = $this->guard(fn() => $statement->execute(), $sql);
        if ($result !== false) {
            $result->finalize();
        }
        $statement->close();
        return $this->connection->changes();
    }

    /**
     * Run a query and yield each row as an associative array. Integers,
     * floats and text come back as themselves, NULL as null.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): Generator
    {
        $statement = $this->prepare($sql, $params);
        $result = $this->guard(fn() => $statement->execute(), $sql);
        if ($result === false) {
            $statement->close();
            throw new DbError(sprintf('Query failed: %s', $sql));
        }
        try {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $result->finalize();
            $statement->close();
        }
    }

    /**
     * The first row of a query, or null.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        foreach ($this->query($sql, $params) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * The first column of the first row, or null.
     *
     * @param list<int|float|string|bool|null> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->one($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /**
     * Run a callable inside one transaction. Nested calls join the open
     * transaction rather than starting a second one, because SQLite has no
     * nested BEGIN and a batch wraps a loop of single writes.
     *
     * The transaction is IMMEDIATE, so the write lock is taken up front and
     * a second writer waits at the door instead of deadlocking halfway.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }
        $this->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $work();
            $this->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            try {
                $this->exec('ROLLBACK');
            } catch (Throwable) {
                // The rollback failing does not change what is reported.
            }
            throw $error;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** @return list<string> Every table, in the order SQLite lists them. */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY rowid") as $row) {
            $tables[] = self::text($row['name']);
        }
        return $tables;
    }

    /**
     * A value that has to be text: what SQLite returns for a name, a type
     * or a metadata value.
     *
     * @throws DbError If it is not scalar.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => throw new DbError(sprintf('Expected text, got %s', get_debug_type($value))),
        };
    }

    /**
     * The columns of a table, as declared.
     *
     * @return list<array{name: string, type: string}>
     */
    public function columns(string $table): array
    {
        $columns = [];
        foreach ($this->query(sprintf('PRAGMA table_info("%s")', str_replace('"', '""', $table))) as $row) {
            $columns[] = ['name' => self::text($row['name']), 'type' => self::text($row['type'])];
        }
        return $columns;
    }

    /**
     * Copy the whole database into another file with SQLite's online
     * backup, which is the only copy that is consistent while somebody
     * writes: `cp` of a file in WAL mode leaves everything since the last
     * checkpoint behind.
     *
     * @throws DbError If the copy fails.
     */
    public function backup(string $target): void
    {
        try {
            $destination = new SQLite3($target, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $destination->enableExceptions(true);
            $done = $this->connection->backup($destination);
            $destination->close();
        } catch (Throwable $error) {
            throw new DbError(sprintf('Backup to %s failed: %s', $target, $error->getMessage()), 0, $error);
        }
        if (!$done) {
            throw new DbError(sprintf('Backup to %s failed', $target));
        }
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /** @param list<int|float|string|bool|null> $params */
    private function prepare(string $sql, array $params): SQLite3Stmt
    {
        $statement = $this->guard(fn() => $this->connection->prepare($sql), $sql);
        if ($statement === false) {
            throw new DbError(sprintf('Cannot prepare: %s', $sql));
        }
        foreach ($params as $index => $value) {
            $type = match (true) {
                $value === null => SQLITE3_NULL,
                is_int($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                is_bool($value) => SQLITE3_INTEGER,
                default => SQLITE3_TEXT,
            };
            $statement->bindValue($index + 1, is_bool($value) ? (int) $value : $value, $type);
        }
        return $statement;
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function guard(callable $call, string $sql): mixed
    {
        try {
            return $call();
        } catch (DbError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DbError(sprintf('%s [%s]', $error->getMessage(), $sql), 0, $error);
        }
    }
}
