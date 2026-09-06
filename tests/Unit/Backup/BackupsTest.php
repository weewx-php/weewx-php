<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Backup;

use PharData;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Backup\Backups;
use WeewxPhp\Backup\Health;
use WeewxPhp\Backup\Restore;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Db\Json;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;

final class BackupsTest extends TestCase
{
    private const NOW = 1788652800;
    private string $dir;
    private string $path;
    private FixedClock $clock;
    private Runtime $runtime;
    private Backups $backups;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('backup');
        $this->path = $this->dir . '/weather.conf';
        file_put_contents($this->path, "data_dir = data\ntimezone = Europe/Berlin\ntick_token = secret-test-token\n[Archives]\n    [[garden]]\n        database = weather.sdb\n        enabled = false\n");
        $this->clock = new FixedClock(self::NOW);
        $this->runtime = Runtime::boot($this->path, $this->clock, new MemoryLogger());
        $this->runtime->ensureDataDir();
        $this->runtime->live();
        $this->runtime->state();
        $this->runtime->ingest();
        $archive = ArchiveDb::open($this->dir . '/data/weather.sdb', JournalMode::Wal, new Policy(), $this->runtime->config->settings->timezone, true);
        $archive->close();
        $this->backups = new Backups($this->runtime->config->settings);
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        TempDir::remove($this->dir);
    }

    private function backup(bool $force = false): string
    {
        $lock = Lock::tryAcquire($this->runtime->config->settings->lockPath());
        self::assertNotNull($lock);
        try {
            $this->runtime->refresh();
            $result = $this->backups->run($this->runtime, $force);
            self::assertSame('complete', $result['status']);
            return $this->backups->directory() . '/' . $result['filename'];
        } finally {
            $lock->release();
        }
    }

    public function testWalOnlyRecordsConfigurationCredentialsAndStateSurviveRestore(): void
    {
        $writer = Sqlite::open($this->dir . '/data/weather.sdb', false, JournalMode::Wal);
        try {
            $writer->exec('PRAGMA wal_autocheckpoint=0');
            $writer->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $writer->exec('INSERT INTO archive(dateTime, usUnits, interval, outTemp) VALUES (?, 17, 5, 21.75)', [self::NOW]);
            self::assertGreaterThan(0, filesize($this->dir . '/data/weather.sdb-wal'));
            $auth = new Auth($this->runtime->config->settings);
            $auth->setPassword('backup-test-password', self::NOW);
            $session = $auth->session(null, self::NOW);
            $session = $auth->login($session['token'], $session['csrf'], 'backup-test-password', '127.0.0.1', self::NOW);
            $auth->close();
            $credentials = $this->runtime->ingest()->credentials();
            $package = $this->backup();
            // The original changes again, while the package retains the earlier snapshot.
            $writer->exec('UPDATE archive SET outTemp = 99');
            $restored = Restore::run($package, $this->dir . '/restored');
            $config = Config::load($restored);
            self::assertSame('secret-test-token', $config->settings->tickToken);
            $archive = $config->archive('garden');
            self::assertNotNull($archive);
            $db = Sqlite::readOnly($archive->database);
            self::assertSame(21.75, $db->scalar('SELECT outTemp FROM archive'));
            self::assertSame('ok', $db->scalar('PRAGMA integrity_check'));
            $db->close();
            $runtime = Runtime::boot($restored, $this->clock, new MemoryLogger());
            try {
                self::assertSame($credentials, $runtime->ingest()->credentials());
                $restoredAuth = new Auth($config->settings);
                self::assertTrue($restoredAuth->configured());
                self::assertFalse($restoredAuth->session($session['token'], self::NOW)['authenticated']);
                $restoredAuth->close();
                self::assertSame('ok', (new Tick($runtime))->run('test')->status);
            } finally {
                $runtime->close();
            }
            self::assertSame(99.0, $writer->scalar('SELECT outTemp FROM archive'));
        } finally {
            $writer->close();
        }
    }

    public function testDailyScheduleRetentionAndManualRequest(): void
    {
        self::assertSame(3, $this->runtime->config->settings->backupRetentionDays);
        $first = $this->backup();
        $this->clock->advance(60);
        self::assertSame($first, $this->backup());
        self::assertCount(1, $this->backups->files());
        $this->backups->request($this->clock->now());
        self::assertNotSame($first, $this->backup());
        self::assertCount(2, $this->backups->files());
        $this->clock->advance(4 * 86400);
        $new = $this->backup();
        self::assertFileExists($new);
        self::assertFileDoesNotExist($first);
        self::assertCount(1, $this->backups->files());
    }

    public function testFailureKeepsLastBackupAndRetriesAfterBackoff(): void
    {
        $first = $this->backup();
        $this->clock->advance(4 * 86400);
        rename($this->dir . '/data/weather.sdb', $this->dir . '/data/weather.saved');
        $outcome = (new Tick($this->runtime))->run('test');
        self::assertSame('error', $outcome->status);
        self::assertSame('failed', $outcome->backup['status']);
        self::assertFileExists($first);
        rename($this->dir . '/data/weather.saved', $this->dir . '/data/weather.sdb');
        self::assertSame('failed', (new Tick($this->runtime))->run('test')->backup['status']);
        $this->clock->advance(601);
        self::assertSame('complete', (new Tick($this->runtime))->run('test')->backup['status']);
        self::assertFileDoesNotExist($first);
    }

    public function testDownloadRequiresAuthenticationAndRejectsTraversalAndCsrf(): void
    {
        $package = $this->backup();
        $controller = new Controller($this->path, self::NOW);
        $query = ['page' => 'backups', 'download' => basename($package)];
        self::assertSame(403, $controller->handle('GET', $query, [], null, '127.0.0.1', true)->status);
        $auth = new Auth($this->runtime->config->settings);
        $auth->setPassword('backup-test-password', self::NOW);
        $session = $auth->session(null, self::NOW);
        $session = $auth->login($session['token'], $session['csrf'], 'backup-test-password', '127.0.0.1', self::NOW);
        $auth->close();
        $response = $controller->handle('GET', $query, [], $session['token'], '127.0.0.1', true);
        self::assertSame(200, $response->status);
        self::assertIsResource($response->download);
        self::assertSame(hash_file('sha256', $package), hash('sha256', stream_get_contents($response->download)));
        fclose($response->download);
        $query['download'] = '../weather.conf';
        self::assertSame(404, $controller->handle('GET', $query, [], $session['token'], '127.0.0.1', true)->status);
        $response = $controller->handle('POST', ['page' => 'backups'], ['action' => 'backup.create', 'csrf' => 'wrong'], $session['token'], '127.0.0.1', true);
        self::assertSame(403, $response->status);
        self::assertSame(0, $this->backups->status()['requested']);
        self::assertSame(403, $controller->handle('GET', ['page' => 'backups'], [], $session['token'], '127.0.0.1', false)->status);
    }

    public function testRestoreRejectsCorruptedFilesWithoutPublishingTarget(): void
    {
        $package = $this->backup();
        $tar = new PharData($package);
        $original = $tar['weewx-php.conf']->getContent();
        $tar['weewx-php.conf'] = str_replace('secret-test-token', 'broken-test-token', $original);
        unset($tar);
        try {
            Restore::run($package, $this->dir . '/bad');
            self::fail('Corrupt backup was accepted');
        } catch (RuntimeException) {
            self::assertDirectoryDoesNotExist($this->dir . '/bad');
            self::assertSame([], glob($this->dir . '/.restore-*'));
        }
    }

    public function testRestoreRejectsTraversalInManifestAndExistingTarget(): void
    {
        $package = $this->backup();
        try {
            Restore::run($package, $this->dir);
            self::fail('Existing target was accepted');
        } catch (RuntimeException) {
            self::assertFileExists($this->path);
        }
        $tar = new PharData($package);
        $manifest = Json::object($tar['manifest.json']->getContent());
        $manifest['archives'] = ['garden' => '../outside.sdb'];
        $tar['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        unset($tar);
        $this->expectException(RuntimeException::class);
        Restore::run($package, $this->dir . '/bad');
    }

    public function testConfigurationCanDisableDailyBackupButManualStillWorks(): void
    {
        $file = ConfFile::read($this->path);
        $file->root()->set('backup_enabled', 'false');
        $file->root()->set('backup_retention_days', '7');
        $file->write($this->path);
        $this->runtime->refresh();
        $this->backups = new Backups($this->runtime->config->settings);
        self::assertSame(7, $this->runtime->config->settings->backupRetentionDays);
        self::assertSame('pending', (new Tick($this->runtime))->run('test')->backup['status']);
        self::assertCount(0, $this->backups->files());
        $this->backup(true);
        self::assertCount(1, $this->backups->files());
        $file->root()->set('backup_retention_days', '0');
        $file->write($this->path);
        $this->expectException(ConfigError::class);
        Config::load($this->path);
    }

    public function testHealthDetectsStoppedTickAndOverdueBackup(): void
    {
        (new Tick($this->runtime))->run('test');
        self::assertSame([], Health::warnings($this->runtime->config, self::NOW));
        self::assertSame(['health.tick', 'health.backup'], Health::warnings($this->runtime->config, self::NOW + 2 * 86400));
    }

    public function testSnapshotAllowsWritersBeforeCopyAndRetainsTheFrozenData(): void
    {
        $file = $this->runtime->config->settings->liveDbPath();
        $writer = Sqlite::open($file, false, JournalMode::Wal);
        $writer->exec('CREATE TABLE backup_probe(revision INTEGER)');
        $writer->exec('INSERT INTO backup_probe VALUES (1)');
        $called = false;
        $result = $this->backups->run($this->runtime, snapshotReady: static function () use ($writer, &$called): void {
            $writer->exec('UPDATE backup_probe SET revision = 2');
            $called = true;
        });
        self::assertTrue($called);
        self::assertSame('complete', $result['status']);
        self::assertSame(2, $writer->scalar('SELECT revision FROM backup_probe'));
        $writer->close();
        $restored = Config::load(Restore::run($this->backups->directory() . '/' . $result['filename'], $this->dir . '/snapshot'));
        $db = Sqlite::readOnly($restored->settings->liveDbPath());
        self::assertSame(1, $db->scalar('SELECT revision FROM backup_probe'));
        $db->close();
    }

    public function testLargeSnapshotRemainsConsistentWithConcurrentIngest(): void
    {
        $directory = $this->runtime->config->settings->dataDir;
        foreach (['live.sdb', 'ingest.sdb'] as $file) {
            $db = Sqlite::open($directory . '/' . $file, false, JournalMode::Wal);
            $db->exec('CREATE TABLE backup_probe(revision INTEGER)');
            $db->exec('INSERT INTO backup_probe VALUES (0)');
            $db->close();
        }
        $archive = Sqlite::open($directory . '/weather.sdb', false, JournalMode::Wal);
        $archive->exec('CREATE TABLE backup_payload(data BLOB)');
        $archive->exec('INSERT INTO backup_payload VALUES (zeroblob(33554432))');
        $archive->close();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/backup-writer.php', $directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 10;
            while (!is_file($directory . '/writer-ready') && microtime(true) < $deadline) {
                usleep(10000);
                clearstatcache();
            }
            self::assertFileExists($directory . '/writer-ready');
            $before = memory_get_peak_usage(true);
            $package = $this->backup();
            self::assertGreaterThan(33554432, filesize($package));
            $restored = Config::load(Restore::run($package, $this->dir . '/large'));
            $revisions = [];
            foreach (['live.sdb', 'ingest.sdb'] as $file) {
                $db = Sqlite::readOnly($restored->settings->dataDir . '/' . $file);
                $revisions[] = $db->scalar('SELECT revision FROM backup_probe');
                $db->close();
            }
            self::assertSame($revisions[0], $revisions[1]);
            self::assertGreaterThan(0, $revisions[0]);
            self::assertLessThan(16 * 1048576, memory_get_peak_usage(true) - $before);
        } finally {
            file_put_contents($directory . '/writer-stop', '1');
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }
    }

    public function testInterruptedWorkIsRetriedAndStagingIsRemoved(): void
    {
        $first = $this->backup();
        $work = $this->backups->directory() . '/.work-0123456789abcdef';
        mkdir($work, 0700);
        file_put_contents($work . '/package.tar', 'incomplete');
        $db = Sqlite::open($this->runtime->config->settings->stateDbPath(), false, JournalMode::Wal);
        $db->exec("UPDATE backup_status SET attempted = ?, requested = ?, status = 'running'", [self::NOW + 1, self::NOW + 1]);
        $db->close();
        $this->clock->advance(602);
        self::assertNotSame($first, $this->backup());
        self::assertDirectoryDoesNotExist($work);
        self::assertCount(2, $this->backups->files());
    }

    public function testRetentionExpiresAtThreeDaysAndScheduleUsesLocalMidnight(): void
    {
        $this->clock->set(1788728400); // 2026-09-06 23:00 Europe/Berlin.
        $first = $this->backup();
        $this->clock->advance(3600);
        self::assertNotSame($first, $this->backup());
        $this->clock->advance(3 * 86400 - 3600);
        $this->backup();
        self::assertFileDoesNotExist($first);
    }

    public function testFailedMaintenanceJobIsReportedByTick(): void
    {
        $db = \WeewxPhp\Admin\Changes::store($this->runtime->config->settings);
        $db->close();
        (new \WeewxPhp\Admin\Jobs($this->path, self::NOW))->queue(['archive' => 'garden', 'kind' => 'backup', 'job_key' => str_repeat('a', 32)]);
        $db = Sqlite::open($this->runtime->config->settings->stateDbPath(), false, JournalMode::Wal);
        $db->exec("UPDATE admin_job SET archive = 'removed'");
        $db->close();
        $outcome = (new Tick($this->runtime))->run('test');
        self::assertSame('error', $outcome->status);
        self::assertFalse($outcome->maintenanceOk);
        self::assertSame('complete', $outcome->backup['status']);
    }

    public function testBackupSettingsAndManualQueueWorkThroughAuthenticatedAdmin(): void
    {
        $auth = new Auth($this->runtime->config->settings);
        $auth->setPassword('backup-test-password', self::NOW);
        $session = $auth->session(null, self::NOW);
        $session = $auth->login($session['token'], $session['csrf'], 'backup-test-password', '127.0.0.1', self::NOW);
        $auth->close();
        $controller = new Controller($this->path, self::NOW);
        $input = ['csrf' => $session['csrf'], 'action' => 'settings.save', 'backup_enabled' => 'false', 'backup_retention_days' => '9', 'revision' => hash_file('sha256', $this->path)];
        $response = $controller->handle('POST', ['page' => 'settings'], $input, $session['token'], '127.0.0.1', true);
        self::assertSame(303, $response->status);
        $config = Config::load($this->path);
        self::assertFalse($config->settings->backupEnabled);
        self::assertSame(9, $config->settings->backupRetentionDays);
        $response = $controller->handle('POST', ['page' => 'backups'], ['csrf' => $session['csrf'], 'action' => 'backup.create'], $session['token'], '127.0.0.1', true);
        self::assertSame(303, $response->status);
        self::assertSame('queued', $this->backups->status()['status']);
        self::assertSame('complete', (new Tick($this->runtime))->run('test')->backup['status']);
        $response = $controller->handle('GET', ['page' => 'backups', 'lang' => 'de'], [], $session['token'], '127.0.0.1', true);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Herunterladen', $response->body);
        self::assertStringNotContainsString('secret-test-token', $response->body);
    }

    public function testFullBackupAndRestoreCommandsDoNotNeedAnExistingRestoreConfig(): void
    {
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        self::assertIsResource($errors);
        try {
            $app = new \WeewxPhp\Cli\Application(new \WeewxPhp\Cli\Console($output, $errors), $this->clock, new MemoryLogger());
            self::assertSame(0, $app->run(['--config', $this->path, 'backup']));
            rewind($output);
            $package = trim(stream_get_contents($output));
            self::assertFileExists($package);
            $app = new \WeewxPhp\Cli\Application(new \WeewxPhp\Cli\Console($output, $errors), $this->clock, new MemoryLogger());
            self::assertSame(0, $app->run(['--config', $this->dir . '/missing.conf', 'restore', $package, $this->dir . '/cli-restore']));
            self::assertFileExists($this->dir . '/cli-restore/weewx-php.conf');
        } finally {
            fclose($output);
            fclose($errors);
        }
    }

    public function testRestoreRejectsTarLinks(): void
    {
        $package = $this->backup();
        $bytes = file_get_contents($package);
        self::assertIsString($bytes);
        $header = substr($bytes, 0, 512);
        $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
        // Replace the first regular file with a symlink; recompute a valid TAR
        // checksum so rejection tests link handling, not a broken TAR header.
        $header = substr_replace($header, str_pad('0', 11, '0', STR_PAD_LEFT) . "\0", 124, 12);
        $header = substr_replace($header, '2', 156, 1);
        $header = substr_replace($header, str_pad($this->path, 100, "\0"), 157, 100);
        $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $checksum = array_sum(array_map(ord(...), str_split($header)));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
        $malicious = $this->dir . '/linked.tar';
        file_put_contents($malicious, $header . substr($bytes, 512 + (int) (ceil($size / 512) * 512)));
        $this->expectException(RuntimeException::class);
        Restore::run($malicious, $this->dir . '/linked');
    }
}
