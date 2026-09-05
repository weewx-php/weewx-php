<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Tests\Support\TempDir;

final class ConfFileTest extends TestCase
{
    /**
     * A file in the shape ConfigObj writes. Built from lines rather than a
     * heredoc, because a blank line inside a section carries the section's
     * indentation and a heredoc would strip exactly that.
     */
    private static function sample(): string
    {
        return implode("\n", [
            '# A file in the shape ConfigObj writes.',
            '#',
            'debug = 0',
            'location = Kirchdorf an der Amper',
            'altitude = 440, meter    # a list with a comment',
            'empty_list = ,',
            'one_item = weewx.engine.StdArchive,',
            'quoted = "a, b", \'say "hi"\', "#hash"',
            'blank = ""',
            'expression = foo + 0.2',
            '',
            '# A section, with a blank line and a comment before it.',
            '[Station]',
            '    ',
            '    # An indented comment.',
            '    station_type = Ecowitt',
            '    [[Inner]]',
            '        key = value    # inline',
            '        [[[Deeper]]]',
            '            unused = unused',
            '    [[Second]]',
            '        x = 1',
            '[Other]',
            '    y = 2',
            '',
            '# The closing block.',
        ]) . "\n";
    }

    public function testReadsValuesTheWayConfigObjDoes(): void
    {
        $root = ConfFile::parse(self::sample())->root();

        self::assertSame('0', $root->value('debug')->string());
        self::assertSame('Kirchdorf an der Amper', $root->value('location')->string());
        self::assertSame(['440', 'meter'], $root->value('altitude')->list());
        self::assertSame([], $root->value('empty_list')->raw());
        self::assertSame(['weewx.engine.StdArchive'], $root->value('one_item')->raw());
        self::assertSame(['a, b', 'say "hi"', '#hash'], $root->value('quoted')->raw());
        self::assertSame('', $root->value('blank')->string());
        self::assertSame('foo + 0.2', $root->value('expression')->string());

        $station = $root->section('Station');
        self::assertSame(1, $station->depth());
        self::assertSame('Ecowitt', $station->value('station_type')->string());
        self::assertSame('value', $station->section('Inner')->value('key')->string());
        self::assertSame('unused', $station->section('Inner')->section('Deeper')->value('unused')->string());
        self::assertSame('1', $station->section('Second')->value('x')->string());
        self::assertSame('2', $root->section('Other')->value('y')->string());
        self::assertSame(['Station', 'Other'], array_keys($root->sections()));
    }

    public function testKeepsCommentsWhereTheyStood(): void
    {
        $file = ConfFile::parse(self::sample());
        $root = $file->root();

        self::assertSame(['# A file in the shape ConfigObj writes.', '#'], $file->initialComment());
        self::assertSame([], $root->comments('debug'));
        self::assertSame('# a list with a comment', $root->inlineComment('altitude'));
        self::assertSame(['', '# A section, with a blank line and a comment before it.'], $root->comments('Station'));
        self::assertSame(['    ', '    # An indented comment.'], $root->section('Station')->comments('station_type'));
        self::assertSame('# inline', $root->section('Station')->section('Inner')->inlineComment('key'));
        self::assertSame(['', '# The closing block.'], $file->finalComment());
    }

    public function testWritesBackByteForByte(): void
    {
        self::assertSame(self::sample(), ConfFile::parse(self::sample())->toString());
    }

    public function testTheReferenceWeewxConfSurvivesARoundTrip(): void
    {
        $original = file_get_contents(__DIR__ . '/../../fixtures/weewx.conf');
        self::assertNotFalse($original);

        self::assertSame($original, ConfFile::parse($original)->toString());
    }

    public function testQuotesWhatWouldOtherwiseReadBackDifferently(): void
    {
        $file = ConfFile::parse('');
        $root = $file->root();
        $root->set('comma', 'a, b');
        $root->set('hash', 'x#y');
        $root->set('edge', ' padded ');
        $root->set('double', 'say "hi"');
        $root->set('nothing', '');
        $root->set('plain', 'Kirchdorf an der Amper');
        $root->set('list', ['one', 'two, three']);

        $expected = <<<'CONF'
            comma = "a, b"
            hash = "x#y"
            edge = " padded "
            double = 'say "hi"'
            nothing = ""
            plain = Kirchdorf an der Amper
            list = one, "two, three"

            CONF;
        self::assertSame($expected, $file->toString());
        self::assertSame($expected, ConfFile::parse($expected)->toString());
    }

    public function testRefusesAValueWithBothKindsOfQuote(): void
    {
        $file = ConfFile::parse('');
        $file->root()->set('mixed', 'it\'s "odd", yes');

        $this->expectException(ConfigError::class);
        $file->toString();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badLines(): iterable
    {
        yield 'duplicate key' => ["a = 1\na = 2", 'line 2: duplicate key a'];
        yield 'duplicate section' => ["[A]\n[A]", 'line 2: duplicate section [A]'];
        yield 'nesting jump' => ['[[A]]', 'line 1: section A is nested 2 deep'];
        yield 'bracket mismatch' => ['[[A]', 'line 1: opening and closing brackets do not match'];
        yield 'unterminated quote' => ['a = "open', 'line 1: unterminated quote in value'];
        yield 'triple quotes' => ["a = '''x'''", 'line 1: triple-quoted values are not supported'];
        yield 'text after a quoted value' => ['a = "x"y', 'line 1: unexpected text after a quoted value'];
        yield 'no equals' => ['just words', 'line 1: expected key = value or a [section]'];
        yield 'double comma' => ['a = ,,', 'line 1: unexpected comma'];
        yield 'text after an empty list' => ['a = , b', 'line 1: unexpected text after an empty list'];
    }

    /**
     * @dataProvider badLines
     */
    public function testNamesTheLineOfAProblem(string $text, string $message): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage($message);
        ConfFile::parse($text);
    }

    public function testTypedAccessNamesThePath(): void
    {
        $root = ConfFile::parse("[Archives]\n    [[kirchdorf]]\n        interval = 5m\n        latitude = north\n        flag = maybe\n        list = a, b")->root();
        $archive = $root->section('Archives')->section('kirchdorf');

        self::assertSame(300, $archive->value('interval')->duration());
        self::assertSame('[Archives][[kirchdorf]]', $archive->path());
        self::assertNull($archive->optional('missing'));

        foreach ([
            fn() => $archive->value('latitude')->float(),
            fn() => $archive->value('flag')->bool(),
            fn() => $archive->value('list')->string(),
            fn() => $archive->value('missing')->string(),
            fn() => $archive->section('nope'),
            fn() => $root->value('Archives'),
        ] as $call) {
            try {
                $call();
                self::fail('expected a ConfigError');
            } catch (ConfigError $error) {
                self::assertStringStartsWith('[Archives]', $error->getMessage());
            }
        }
    }

    public function testDurationsAndBooleans(): void
    {
        $root = ConfFile::parse("s = 90\nm = 5m\nh = 2h\nd = 7d\nyes = yes\noff = off\n")->root();

        self::assertSame(90, $root->value('s')->duration());
        self::assertSame(300, $root->value('m')->duration());
        self::assertSame(7200, $root->value('h')->duration());
        self::assertSame(604800, $root->value('d')->duration());
        self::assertTrue($root->value('yes')->bool());
        self::assertFalse($root->value('off')->bool());
    }

    public function testWritesAtomicallyAndKeepsTheMode(): void
    {
        $dir = TempDir::create('conf');
        try {
            $path = $dir . '/weewx-php.conf';
            file_put_contents($path, "old = 1\n");
            chmod($path, 0o640);

            $file = ConfFile::parse("new = 2\n");
            $file->write($path);

            self::assertSame("new = 2\n", file_get_contents($path));
            self::assertSame(0o640, fileperms($path) & 0o777);
            self::assertSame([$path], glob($dir . '/*'));
        } finally {
            TempDir::remove($dir);
        }
    }
}
