<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Db;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\DbError;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tests\Support\TempDir;

final class SqliteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('sqlite');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testFloatsComeBackAsTheSameDoubles(): void
    {
        $db = Sqlite::open($this->dir . '/a.sdb', true, JournalMode::Wal);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, x REAL, n INTEGER, s TEXT, b INTEGER)');
        $awkward = 0.1 + 0.2;
        $db->exec('INSERT INTO t VALUES (?, ?, ?, ?, ?)', [1, $awkward, 7, 'seven', true]);
        $db->exec('INSERT INTO t VALUES (?, ?, ?, ?, ?)', [2, null, null, null, false]);

        $rows = iterator_to_array($db->query('SELECT * FROM t ORDER BY id'), false);

        self::assertSame(1, $rows[0]['id']);
        self::assertSame($awkward, $rows[0]['x']);
        self::assertSame(7, $rows[0]['n']);
        self::assertSame('seven', $rows[0]['s']);
        self::assertSame(1, $rows[0]['b']);
        self::assertNull($rows[1]['x']);
        self::assertNull($rows[1]['n']);
        self::assertSame(0, $rows[1]['b']);
        self::assertSame(2, $db->scalar('SELECT COUNT(*) FROM t'));
        self::assertNull($db->scalar('SELECT x FROM t WHERE id = ?', [99]));
        self::assertSame('wal', $db->scalar('PRAGMA journal_mode'));
        $db->close();
    }

    public function testRefusesToInventAMissingFileUnlessAsked(): void
    {
        $this->expectException(DbError::class);
        Sqlite::open($this->dir . '/missing.sdb', false, JournalMode::Delete);
    }

    public function testATransactionRollsBackWhatFailedInsideIt(): void
    {
        $db = Sqlite::open($this->dir . '/b.sdb', true, JournalMode::Delete);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $caught = null;
        try {
            $db->transaction(function () use ($db): void {
                $db->exec('INSERT INTO t VALUES (1)');
                // A nested call joins the transaction rather than starting one.
                $db->transaction(fn(): int => $db->exec('INSERT INTO t VALUES (2)'));
                self::assertTrue($db->inTransaction());
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $error) {
            $caught = $error->getMessage();
        }

        self::assertSame('boom', $caught);
        self::assertFalse($db->inTransaction());
        self::assertSame(0, $db->scalar('SELECT COUNT(*) FROM t'));
        $written = $db->transaction(fn(): int => $db->exec('INSERT INTO t VALUES (3)'));
        self::assertSame(1, $written);
        self::assertSame(1, $db->scalar('SELECT COUNT(*) FROM t'));
    }

    public function testDescribesTablesAndColumnsAndBacksUp(): void
    {
        $db = Sqlite::open($this->dir . '/c.sdb', true, JournalMode::Wal);
        $db->exec('CREATE TABLE archive (dateTime INTEGER NOT NULL PRIMARY KEY, outTemp REAL)');
        $db->exec('CREATE TABLE archive_day_outTemp (dateTime INTEGER NOT NULL PRIMARY KEY, min REAL)');
        $db->exec('INSERT INTO archive VALUES (1, 2.5)');

        self::assertSame(['archive', 'archive_day_outTemp'], $db->tables());
        self::assertSame(
            [['name' => 'dateTime', 'type' => 'INTEGER'], ['name' => 'outTemp', 'type' => 'REAL']],
            $db->columns('archive'),
        );

        $db->backup($this->dir . '/copy.sdb');
        $copy = Sqlite::open($this->dir . '/copy.sdb', false, JournalMode::Delete);
        self::assertSame(2.5, $copy->scalar('SELECT outTemp FROM archive'));
    }

    public function testASqlMistakeNamesTheStatement(): void
    {
        $db = Sqlite::open($this->dir . '/d.sdb', true, JournalMode::Delete);

        $this->expectException(DbError::class);
        $this->expectExceptionMessage('SELECT nothing FROM nowhere');
        $db->one('SELECT nothing FROM nowhere');
    }
}
