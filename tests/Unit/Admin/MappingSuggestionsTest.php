<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\MappingSuggestions;
use WeewxPhp\Admin\Page;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Admin\Translator;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tests\Support\TempDir;

final class MappingSuggestionsTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('mapping-suggestions');
        mkdir($this->dir . '/data');
        $this->path = $this->dir . '/weather.conf';
        file_put_contents($this->path, "data_dir = data\nbackup_enabled = false\n[Stations]\n [[roof]]\n name = Roof\n [[garden]]\n name = Garden\n");
        $this->command('archive.create', ['archive' => 'old', 'name' => 'Existing history', 'timezone' => 'UTC']);
        $this->command('archive.stations', ['archive' => 'old', 'senders' => ['roof']]);
        $db = Sqlite::open($this->archive()->database, false, JournalMode::Delete);
        $db->exec('INSERT INTO archive(dateTime, usUnits, interval, outTemp, outHumidity) VALUES(1000, 1, 5, 60.5, 50), (1300, 1, 5, NULL, 55)');
        $db->close();
        $store = \WeewxPhp\Ingest\Store::open(Config::load($this->path)->settings);
        $store->close();
        $db = Sqlite::open($this->dir . '/data/ingest.sdb', false, JournalMode::Delete);
        $db->exec("INSERT INTO ingest_field(sender,native,observation,value,unit,first_seen,last_seen) VALUES('roof','tempf','outTemp',72.5,'degree_F',1500,1500), ('roof','humidity','outHumidity',61,'percent',1500,1500), ('roof','unknown',NULL,5,NULL,1500,1500)");
        $db->close();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function archive(): ArchiveConfig
    {
        return Config::load($this->path)->archive('old') ?? throw new \RuntimeException('Missing test archive');
    }

    /** @param array<string, mixed> $input */
    private function command(string $action, array $input): void
    {
        (new Service($this->path, 2000))->execute($action, $input + ['revision' => (new ReadModel($this->path))->revision]);
    }

    public function testExactProposalsShowCurrentAndLastNonNullHistoricalValuesWithoutWriting(): void
    {
        $before = file_get_contents($this->path);
        $archive = $this->archive();
        $dbHash = hash_file('sha256', $archive->database);
        $read = new ReadModel($this->path);
        $candidates = MappingSuggestions::candidates($read, $archive);
        self::assertSame(['outHumidity', 'outTemp'], array_keys($candidates));
        self::assertSame(72.5, $candidates['outTemp']['value']);
        $last = MappingSuggestions::lastValues($archive, array_keys($candidates));
        self::assertSame(60.5, $last['outTemp']['value']);
        self::assertSame(1000, $last['outTemp']['last_seen']);
        self::assertSame('degree_F', $last['outTemp']['unit']);
        self::assertSame(1300, $last['outHumidity']['last_seen']);
        $html = (new Page($read, new Translator('de'), 'test'))->render('fields', ['archive' => 'old']);
        self::assertStringContainsString('Alle Vorschläge übernehmen', $html);
        self::assertStringContainsString('Ja, Zuordnungen übernehmen', $html);
        self::assertStringContainsString('72,50', $html);
        self::assertStringContainsString('60,50', $html);
        self::assertSame($before, file_get_contents($this->path));
        self::assertSame($dbHash, hash_file('sha256', $archive->database));
    }

    public function testBulkAcceptRequiresConfirmationAndMatchingPreviewAndPreservesOldReadings(): void
    {
        $archive = $this->archive();
        $candidates = MappingSuggestions::candidates(new ReadModel($this->path), $archive);
        $fingerprint = MappingSuggestions::fingerprint($archive, $candidates);
        $before = file_get_contents($this->path);
        foreach ([['suggestion' => $fingerprint], ['suggestion' => 'wrong', 'confirm' => 'yes']] as $bad) {
            try {
                $this->command('mapping.accept_suggestions', ['archive' => 'old'] + $bad);
                self::fail('Unconfirmed or altered proposal accepted');
            } catch (Problem) {
                self::assertSame($before, file_get_contents($this->path));
            }
        }
        $hash = hash_file('sha256', $archive->database);
        $this->command('mapping.accept_suggestions', ['archive' => 'old', 'suggestion' => $fingerprint, 'confirm' => 'yes']);
        self::assertSame(['outHumidity' => 'outHumidity', 'outTemp' => 'outTemp'], $this->archive()->fields['roof']);
        self::assertSame($hash, hash_file('sha256', $archive->database));
        self::assertSame([], MappingSuggestions::candidates(new ReadModel($this->path), $this->archive()));
    }

    public function testAmbiguousSourcesAndMultipleStationsAreNotSuggested(): void
    {
        $db = Sqlite::open($this->dir . '/data/ingest.sdb', false, JournalMode::Delete);
        $db->exec("INSERT INTO ingest_field(sender,native,observation,value,unit,first_seen,last_seen) VALUES('roof','temperature_alias','outTemp',73,'degree_F',1600,1600)");
        $db->close();
        self::assertSame(['outHumidity'], array_keys(MappingSuggestions::candidates(new ReadModel($this->path), $this->archive())));
        $this->command('archive.stations', ['archive' => 'old', 'senders' => ['roof', 'garden']]);
        self::assertSame([], MappingSuggestions::candidates(new ReadModel($this->path), $this->archive()));
    }
}
