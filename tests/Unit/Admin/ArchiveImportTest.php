<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\ArchiveImport;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\DailyStatistics;
use WeewxPhp\Admin\ImportController;
use WeewxPhp\Admin\ImportFiles;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Weewx\Policy;

final class ArchiveImportTest extends TestCase
{
    private string $dir;
    private string $path;
    private int $now;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('archive-import');
        mkdir($this->dir . '/data');
        $this->path = $this->dir . '/weewx-php.conf';
        file_put_contents($this->path, "data_dir = data\ntimezone = Europe/Berlin\nbackup_enabled = false\n");
        $this->now = (new DateTimeImmutable('2026-09-06T12:00:00Z'))->getTimestamp();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @param list<string> $dates */
    private function archive(string $zoneName = 'Europe/Berlin', array $dates = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04']): string
    {
        $path = $this->dir . '/source-' . bin2hex(random_bytes(3)) . '.sdb';
        $zone = new DateTimeZone($zoneName);
        $db = ArchiveDb::open($path, JournalMode::Wal, new Policy(), $zone, create: true);
        try {
            foreach ($dates as $index => $date) {
                $start = new DateTimeImmutable($date . ' 00:00:00', $zone);
                $stop = $start->modify('+1 day')->getTimestamp();
                for ($stamp = $start->getTimestamp() + 3600; $stamp <= $stop; $stamp += 3600) {
                    $db->addRecord(['dateTime' => $stamp, 'usUnits' => 1, 'interval' => 60,
                        'outTemp' => 40.0 + $index * 10 + ($stamp % 7), 'rain' => .01 * ($stamp % 3), 'windSpeed' => 4.0, 'windDir' => 90.0]);
                }
            }
        } finally {
            $db->close();
        }
        return $path;
    }

    private function importer(): ArchiveImport
    {
        return new ArchiveImport($this->path, $this->now, $this->dir);
    }

    /** @return array<string, mixed> */
    private function upload(ArchiveImport $import, string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $started = $import->files->upload('My weather.sdb', strlen($bytes));
        self::assertIsString($started['id']);
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $chunk = substr($bytes, $offset, ImportFiles::CHUNK);
            $result = $import->files->chunk($started['id'], $offset, $chunk);
            $offset += strlen($chunk);
            self::assertSame($offset, $result['received']);
        }
        return $import->inspect($started['id']);
    }

    public function testDetectUsesWinterSummerAndFractionalOffsetsAndPreservesArchive(): void
    {
        foreach (['Europe/Berlin', 'Asia/Kathmandu', 'Australia/Adelaide'] as $zone) {
            $source = $this->archive($zone, ['2026-01-01', '2026-01-02', '2026-07-01', '2026-07-02']);
            $hash = hash_file('sha256', $source);
            $result = DailyStatistics::detect($source, $this->now);
            self::assertSame(2, $result['days']);
            self::assertContains($zone, $result['zones']);
            self::assertNotContains('UTC', $result['zones']);
            self::assertSame($hash, hash_file('sha256', $source));
        }
    }

    public function testMatchingUploadPublishesUnchangedAndGetsAutomaticId(): void
    {
        $source = $this->archive();
        // Make the SQLite file larger than individual HTTP/PHP upload limits.
        $db = Sqlite::open($source, false, JournalMode::Wal);
        $db->exec('CREATE TABLE extra_payload(value BLOB)');
        $db->exec('INSERT INTO extra_payload VALUES(zeroblob(4194304))');
        $db->close();
        $import = $this->importer();
        $ready = $this->upload($import, $source);
        self::assertSame('US', $ready['units']);
        self::assertIsString($ready['id']);
        $done = $import->commit($ready['id'], 'Garden', 'Europe/Berlin');
        self::assertSame('complete', $done['phase']);
        self::assertIsString($done['archive']);
        $archive = Config::load($this->path)->archive($done['archive']);
        self::assertNotNull($archive);
        self::assertFalse($archive->enabled);
        self::assertSame([], $archive->senders);
        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $archive->database));
        self::assertSame($done, $import->commit($ready['id'], 'Garden', 'Europe/Berlin'));
    }

    public function testWrongTimezoneRebuildsOnlyStagedSummariesAndPreservesKnownExtrema(): void
    {
        $source = $this->archive();
        $original = Sqlite::open($source, false, JournalMode::Wal);
        $stamp = (new DateTimeImmutable('2026-09-02T14:00:00Z'))->getTimestamp();
        $original->exec('UPDATE archive_day_outTemp SET max = 150, maxtime = ? WHERE dateTime = ?', [$stamp, (new DateTimeImmutable('2026-09-02T00:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp()]);
        $records = iterator_to_array($original->query('SELECT * FROM archive ORDER BY dateTime'), false);
        $original->close();
        $hash = hash_file('sha256', $source);
        $import = $this->importer();
        $ready = $this->upload($import, $source);
        self::assertIsString($ready['id']);
        $state = $import->commit($ready['id'], 'UTC archive', 'UTC');
        self::assertSame('repair', $state['phase']);
        self::assertSame([], Config::load($this->path)->archives);
        for ($i = 0; $state['phase'] === 'repair' && $i < 20; ++$i) {
            $state = $this->importer()->step($ready['id']);
        }
        self::assertSame('complete', $state['phase']);
        self::assertIsString($state['archive']);
        $archive = Config::load($this->path)->archive($state['archive']);
        self::assertNotNull($archive);
        $check = DailyStatistics::check($archive->database, new DateTimeZone('UTC'), DailyStatistics::samples($archive->database, $this->now));
        self::assertSame('match', $check['status']);
        $db = Sqlite::readOnly($archive->database);
        try {
            self::assertSame($records, iterator_to_array($db->query('SELECT * FROM archive ORDER BY dateTime'), false));
            self::assertEquals(150, $db->scalar('SELECT max FROM archive_day_outTemp WHERE dateTime = ?', [(new DateTimeImmutable('2026-09-02T00:00:00Z'))->getTimestamp()]));
            self::assertSame(0, $db->scalar('SELECT COUNT(*) FROM archive_day_outTemp WHERE dateTime % 86400 != 0'));
            self::assertSame([], array_values(array_filter($db->tables(), static fn(string $table): bool => str_starts_with($table, '_import_day_'))));
        } finally {
            $db->close();
        }
        self::assertSame($hash, hash_file('sha256', $source));
        self::assertDirectoryDoesNotExist($this->dir . '/data/backups');
        self::assertFileDoesNotExist($import->files->directory($ready['id']) . '/original.sdb');
    }

    public function testSearchCopiesUnboundArchivesAndThenExcludesTheirSources(): void
    {
        $source = $this->archive();
        file_put_contents($this->dir . '/fake.sdb', '<?php echo "not a database";');
        $import = $this->importer();
        $scan = $import->files->search(null);
        for ($i = 0; $scan['done'] !== true && $i < 20; ++$i) {
            self::assertIsString($scan['id']);
            $scan = $import->files->search($scan['id']);
        }
        self::assertTrue($scan['done']);
        self::assertIsArray($scan['files']);
        self::assertCount(1, $scan['files']);
        $entry = $scan['files'][0];
        self::assertIsArray($entry);
        self::assertIsString($entry['key']);
        self::assertIsString($scan['id']);
        $selected = $import->files->select($scan['id'], $entry['key']);
        self::assertIsString($selected['id']);
        $ready = $import->inspect($selected['id']);
        $import->commit($selected['id'], 'Found archive', 'Europe/Berlin');
        self::assertFileExists($source);
        $scan = $this->importer()->files->search(null);
        self::assertSame([], $scan['files']);
    }

    public function testChunkResumptionRejectsWrongOffsetsAndPathTraversal(): void
    {
        $import = $this->importer();
        $state = $import->files->upload('../../evil.php', 512);
        self::assertIsString($state['id']);
        $import->files->chunk($state['id'], 0, str_repeat('a', 256));
        try {
            $import->files->chunk($state['id'], 0, str_repeat('b', 256));
            self::fail('Wrong offset accepted');
        } catch (Problem $error) {
            self::assertSame('error.import_offset', $error->getMessage());
        }
        $again = $this->importer();
        self::assertSame(256, $again->files->read($state['id'])['received']);
        $again->files->chunk($state['id'], 256, str_repeat('c', 256));
        $this->expectException(Problem::class);
        $again->files->directory('../escape');
    }

    public function testHttpImportRejectsAnonymousAndCsrfBeforeReadingBody(): void
    {
        $config = Config::load($this->path);
        $auth = new Auth($config->settings);
        $session = $auth->session(null, $this->now);
        $controller = new ImportController($this->path, $this->now, $this->dir);
        $body = static function (int $limit): string {
            self::fail('Unauthorized body read');
        };
        self::assertSame(403, $controller->handle('POST', ['action' => 'begin'], null, '', '127.0.0.1', true, 100, $body)->status);
        $auth->setPassword('test-import-password', $this->now);
        $session = $auth->session(null, $this->now);
        $login = $auth->login($session['token'], $session['csrf'], 'test-import-password', '127.0.0.1', $this->now);
        self::assertSame(403, $controller->handle('POST', ['action' => 'begin'], $login['token'], 'wrong', '127.0.0.1', true, 100, $body)->status);
        self::assertSame(413, $controller->handle('POST', ['action' => 'chunk'], $login['token'], $login['csrf'], '127.0.0.1', true, ImportFiles::CHUNK + 1, $body)->status);
        $auth->close();
    }

    public function testNewArchiveDoesNotRequireUserAssignedId(): void
    {
        $read = new ReadModel($this->path);
        (new Service($this->path, $this->now))->execute('archive.create', ['revision' => $read->revision, 'name' => 'Garden', 'timezone' => 'Europe/Berlin', 'unit_system' => 'US', 'archive_interval' => '300']);
        $archives = Config::load($this->path)->archives;
        self::assertCount(1, $archives);
        $id = array_key_first($archives);
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^archive-[a-f0-9]{16}$/D', $id);
    }

    public function testInterruptedPublicationResumesAndRemovesOnlyTemporaryPayload(): void
    {
        $source = $this->archive();
        $import = $this->importer();
        $ready = $this->upload($import, $source);
        self::assertIsString($ready['id']);
        $id = $ready['id'];
        $state = $import->files->read($id);
        $state['archive'] = 'archive-' . substr($id, 0, 16);
        $state['label'] = 'Interrupted';
        $state['timezone'] = 'Europe/Berlin';
        $state['phase'] = 'publishing';
        $import->files->save($id, $state);
        mkdir($this->dir . '/data/archives');
        $target = $this->dir . '/data/archives/' . $state['archive'] . '.sdb';
        self::assertTrue(link($import->files->directory($id) . '/archive.sdb', $target));
        $done = $this->importer()->step($id);
        self::assertSame('complete', $done['phase']);
        self::assertFileExists($target);
        self::assertFileDoesNotExist($import->files->directory($id) . '/archive.sdb');
        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $target));
        $import->files->discard($id);
        self::assertFileExists($target);
        self::assertCount(1, Config::load($this->path)->archives);
    }

    public function testDiscardRemovesAnUnfinishedUpload(): void
    {
        $import = $this->importer();
        $state = $import->files->upload('unfinished.sdb', 2048);
        self::assertIsString($state['id']);
        $id = $state['id'];
        $import->files->chunk($id, 0, str_repeat('a', 1024));
        $import->files->discard($id);
        self::assertFileDoesNotExist($import->files->directory($id) . '/archive.sdb');
        self::assertSame('discarded', $import->files->read($id)['phase']);
        self::assertSame([], Config::load($this->path)->archives);
    }

    public function testDetectionHandlesBothDstTransitionDays(): void
    {
        $this->now = (new DateTimeImmutable('2026-11-01T12:00:00Z'))->getTimestamp();
        $source = $this->archive('Europe/Berlin', ['2026-03-28', '2026-03-29', '2026-10-24', '2026-10-25']);
        $result = DailyStatistics::detect($source, $this->now);
        self::assertContains('Europe/Berlin', $result['zones']);
        self::assertNotContains('Africa/Johannesburg', $result['zones']);
        self::assertSame('match', DailyStatistics::check($source, new DateTimeZone('Europe/Berlin'), DailyStatistics::samples($source, $this->now))['status']);
    }
}
