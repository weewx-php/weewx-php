<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Extension;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Admin\ExtensionService;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Extension\Release;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Lock;

final class StoreTest extends TestCase
{
    private string $dir;
    private string $path;
    private int $now;
    private string $oldLog;
    /** @var array<string, string> */
    private array $bodies;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('extension-store');
        $this->path = $this->dir . '/weather.conf';
        $this->now = time();
        file_put_contents($this->path, "data_dir = {$this->dir}/data\nbackup_enabled = false\n[Archives]\n [[garden]]\n");
        $marker = var_export($this->dir . '/executed', true);
        $this->bodies = ['extension.php' => '<?php file_put_contents(' . $marker . ', "loaded"); return static function (\WeewxPhp\Extension\Registration $r): void { $r->tag("sample", static fn($context, $args) => new \WeewxPhp\Frontend\Value(12.0)); };', 'review.md' => 'Reviewed test fixture.'];
        $this->oldLog = (string) ini_get('error_log');
        ini_set('error_log', $this->dir . '/errors.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->oldLog);
        TempDir::remove($this->dir);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return ['id' => 'sample', 'name' => 'Sample <script>alert(1)</script>', 'description' => 'Comparison data',
            'version' => '0.1.0', 'repository' => 'weewx-php/extension-sample', 'commit' => str_repeat('a', 40),
            'entry' => 'extension.php', 'api' => 1, 'php' => '8.1', 'requires' => ['json'],
            'files' => array_map(static fn(string $body): string => hash('sha256', $body), $this->bodies),
            'reviewed_at' => '2026-09-06', 'review' => 'review.md'];
    }

    private function catalog(Release $release): string
    {
        return '{"schema":1,"extensions":[' . $release->json() . ']}';
    }

    /** @param array<string, string> $extra */
    private function command(string $action, FakeHttpClient $http, Release $release, array $extra = []): void
    {
        (new ExtensionService($this->path, $this->now, $http))->execute(
            'extension.' . $action,
            $extra + ['revision' => (new ReadModel($this->path))->revision, 'extension' => $release->id, 'release' => $release->fingerprint()],
        );
    }

    private function downloads(Release $release): FakeHttpClient
    {
        $http = (new FakeHttpClient())->answer(200, $this->catalog($release));
        foreach (array_keys($release->files) as $path) {
            $http->answer(200, $this->bodies[$path]);
        }
        return $http;
    }

    public function testAuthenticatedShopInstallsDisabledAndOnlyLoadsCodeAfterActivation(): void
    {
        $release = Release::from($this->row());
        $http = $this->downloads($release);
        $read = new ReadModel($this->path);
        $auth = new Auth($read->config->settings);
        try {
            $auth->setPassword('correct horse battery staple', $this->now);
            $anonymous = $auth->session(null, $this->now);
            $session = $auth->login($anonymous['token'], $anonymous['csrf'], 'correct horse battery staple', '127.0.0.1', $this->now);
        } finally {
            $auth->close();
        }
        $controller = new Controller($this->path, $this->now, http: $http);
        $blocked = $controller->handle('POST', [], ['action' => 'extension.install', 'csrf' => 'wrong'], $session['token'], '127.0.0.1', true);
        self::assertSame(403, $blocked->status);
        self::assertCount(0, $http->requests);
        $response = $controller->handle('POST', ['page' => 'extensions'], ['action' => 'extension.install', 'csrf' => $session['csrf'],
            'revision' => $read->revision, 'extension' => $release->id, 'release' => $release->fingerprint()], $session['token'], '127.0.0.1', true);
        self::assertSame(303, $response->status);
        self::assertFileDoesNotExist($this->dir . '/executed');
        $read = new ReadModel($this->path);
        self::assertSame([], $read->config->extensions);
        $page = $controller->handle('GET', ['page' => 'extensions', 'lang' => 'de'], [], $session['token'], '127.0.0.1', true);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Aktivieren', $page->body);
        self::assertStringContainsString('&lt;script&gt;', $page->body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page->body);
        self::assertCount(3, $http->requests);
        $this->command('enable', $http->answer(200, $this->catalog($release)), $release);
        self::assertFileDoesNotExist($this->dir . '/executed');
        $wx = new Weather((new ReadModel($this->path))->config);
        self::assertTrue($wx->hasTag('sample.sample'));
        self::assertFileExists($this->dir . '/executed');
        $wx->close();
        $this->command('disable', $http, $release);
        self::assertSame([], (new ReadModel($this->path))->config->extensions);
        $section = (new ReadModel($this->path))->file->root()->section('Extensions')->section('sample');
        $entry = $section->value('entry')->string();
        $this->command('remove', $http, $release);
        self::assertFalse((new ReadModel($this->path))->file->root()->section('Extensions')->has('sample'));
        self::assertFileExists($entry); // In-flight ticks can finish; data and old releases survive.
    }

    public function testAnonymousRequestsCannotFetchCatalogOrInstallPackages(): void
    {
        $http = new FakeHttpClient();
        $controller = new Controller($this->path, $this->now, http: $http);
        $page = $controller->handle('GET', ['page' => 'extensions'], [], null, '127.0.0.1', true);
        self::assertStringNotContainsString('extension.install', $page->body);
        self::assertCount(0, $http->requests);
        $auth = new Auth((new ReadModel($this->path))->config->settings);
        try {
            $session = $auth->session(null, $this->now);
        } finally {
            $auth->close();
        }
        $response = $controller->handle('POST', ['page' => 'extensions'], ['action' => 'extension.install', 'csrf' => $session['csrf']], $session['token'], '127.0.0.1', true);
        self::assertSame(403, $response->status);
        self::assertCount(0, $http->requests);
        self::assertDirectoryDoesNotExist($this->dir . '/data/extension-store');
    }

    public function testFailedUpdateRetainsActiveVersionAndResumesVerifiedDownloads(): void
    {
        $first = Release::from($this->row());
        $http = $this->downloads($first);
        $this->command('install', $http, $first);
        $this->command('enable', $http->answer(200, $this->catalog($first)), $first);
        $file = (new ReadModel($this->path))->file;
        $file->root()->section('Extensions')->section('sample')->addSection('options')->set('window_days', '5');
        $file->write($this->path);
        $before = file_get_contents($this->path);
        $this->bodies['extension.php'] .= ' // updated';
        $row = $this->row();
        $row['version'] = '0.2.0';
        $row['commit'] = str_repeat('b', 40);
        $next = Release::from($row);
        $http->answer(200, $this->catalog($next))->answer(200, $this->bodies['extension.php'])->answer(200, 'tampered review');
        try {
            $this->command('install', $http, $next);
            self::fail('Tampered download accepted');
        } catch (Problem $error) {
            self::assertSame('error.extension_install', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
        self::assertFileDoesNotExist($this->dir . '/executed');
        $count = count($http->requests);
        $this->command('install', $http->answer(200, $this->catalog($next))->answer(200, $this->bodies['review.md']), $next);
        self::assertCount($count + 2, $http->requests);
        $read = new ReadModel($this->path);
        self::assertStringContainsString($next->fingerprint(), $read->config->extensions['sample']->entry);
        self::assertSame(5, $read->config->extensions['sample']->options->value('window_days')->int());
        self::assertFileExists((new Installer(new Files($this->dir . '/data/extension-store'), $http))->entry($first));
    }

    public function testFreshApprovalIsRequiredDespiteCachedCatalog(): void
    {
        $release = Release::from($this->row());
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        $before = file_get_contents($this->path);
        $http->answer(200, '{"schema":1,"extensions":[]}');
        try {
            $this->command('enable', $http, $release);
            self::fail('Revoked release enabled');
        } catch (Problem $error) {
            self::assertSame('error.extension_unapproved', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
        $http->answer(503, 'unavailable');
        try {
            $this->command('enable', $http, $release);
            self::fail('Offline activation accepted');
        } catch (Problem $error) {
            self::assertSame(502, $error->status);
        }
        self::assertSame($before, file_get_contents($this->path));
        $this->command('remove', $http, $release); // Removal does not need a network response.
    }

    public function testConcurrentConfigurationChangeAndWriterConflictDoNotActivateStagedCode(): void
    {
        $release = Release::from($this->row());
        $http = new FakeHttpClient();
        try {
            $this->command('install', $http, $release, ['revision' => str_repeat('0', 64)]);
            self::fail('Stale revision accepted');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        }
        self::assertCount(0, $http->requests);
        mkdir($this->dir . '/data', 0750, true);
        $lock = Lock::tryAcquire((new ReadModel($this->path))->config->settings->lockPath());
        self::assertNotNull($lock);
        $http = $this->downloads($release);
        try {
            $this->command('install', $http, $release);
            self::fail('Busy configuration saved');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        } finally {
            $lock->release();
        }
        self::assertSame([], (new ReadModel($this->path))->config->extensions);
        self::assertFileDoesNotExist($this->dir . '/executed');
        self::assertCount(3, $http->requests); // Downloading never needs the archive writer lock.
        $this->command('install', $http->answer(200, $this->catalog($release)), $release);
        self::assertCount(4, $http->requests);
    }

    public function testUnsafePathsOriginsMutableReferencesAndDuplicateNamesAreRejected(): void
    {
        foreach (['../evil.php', '/evil.php', 'a/../../evil.php', 'a\\evil.php', 'php://evil', '.htaccess', 'a/CON.php', 'a/file.', 'a//b.php', 'a/%2e%2e/b.php'] as $path) {
            $row = $this->row();
            $row['files'] = [$path => str_repeat('a', 64)];
            try {
                Release::from($row);
                self::fail('Unsafe path accepted: ' . $path);
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach (['repository' => 'attacker/package', 'commit' => 'main', 'id' => '../sample'] as $key => $value) {
            $row = $this->row();
            $row[$key] = $value;
            try {
                Release::from($row);
                self::fail('Invalid origin accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $row = $this->row();
        $row['files'] = ['extension.php' => str_repeat('a', 64), 'Extension.php' => str_repeat('a', 64), 'review.md' => str_repeat('b', 64)];
        $this->expectException(\InvalidArgumentException::class);
        Release::from($row);
    }

    public function testCompatibilityAndDisplayedReleaseAreCheckedBeforeDownloads(): void
    {
        $row = $this->row();
        $row['api'] = 99;
        $release = Release::from($row);
        self::assertFalse($release->compatible());
        $http = (new FakeHttpClient())->answer(200, $this->catalog($release));
        try {
            $this->command('install', $http, $release);
            self::fail('Incompatible API installed');
        } catch (Problem $error) {
            self::assertSame('error.extension_incompatible', $error->getMessage());
        }
        self::assertCount(1, $http->requests);
        $release = Release::from($this->row());
        $http->answer(200, $this->catalog($release));
        try {
            $this->command('install', $http, $release, ['release' => str_repeat('0', 64)]);
            self::fail('Changed catalog selection accepted');
        } catch (Problem $error) {
            self::assertSame('error.extension_changed', $error->getMessage());
        }
        self::assertCount(2, $http->requests);
    }

    public function testModifiedInstalledCodeCannotBeActivated(): void
    {
        $release = Release::from($this->row());
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        $installer = new Installer(new Files($this->dir . '/data/extension-store'), $http);
        file_put_contents($installer->entry($release), '<?php throw new RuntimeException("tampered");');
        $before = file_get_contents($this->path);
        try {
            $this->command('enable', $http->answer(200, $this->catalog($release)), $release);
            self::fail('Modified installed code activated');
        } catch (Problem $error) {
            self::assertSame('error.extension_install', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testRestoreCanReinstallMissingManagedPackageButCannotReplaceManualCode(): void
    {
        $release = Release::from($this->row());
        $file = (new ReadModel($this->path))->file;
        $section = $file->root()->addSection('Extensions')->addSection('sample');
        $section->set('entry', '/old-host/extension.php');
        $section->set('enabled', 'false');
        $section->set('managed_release', $release->fingerprint());
        $section->addSection('options')->set('window_days', '5');
        $file->write($this->path);
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        $read = new ReadModel($this->path);
        $section = $read->file->root()->section('Extensions')->section('sample');
        self::assertSame(5, $section->section('options')->value('window_days')->int());
        self::assertNotNull((new Installer(new Files($this->dir . '/data/extension-store'), $http))->installed('sample', $section));
        $section->remove('managed_release');
        $section->set('entry', 'my-local-extension.php');
        $read->file->write($this->path);
        $before = file_get_contents($this->path);
        try {
            $this->command('install', $http->answer(200, $this->catalog($release)), $release);
            self::fail('Manual extension replaced');
        } catch (Problem $error) {
            self::assertSame('error.extension_manual', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testStoreLockAndFileSizeLimitLeaveConfigurationUntouched(): void
    {
        $release = Release::from($this->row());
        $files = new Files($this->dir . '/data/extension-store');
        $lock = Lock::tryAcquire($files->directory() . '/operation.lock');
        self::assertNotNull($lock);
        $http = new FakeHttpClient();
        try {
            $this->command('install', $http, $release);
            self::fail('Concurrent installation accepted');
        } catch (Problem $error) {
            self::assertSame(409, $error->status);
        } finally {
            $lock->release();
        }
        self::assertCount(0, $http->requests);
        $this->bodies['extension.php'] = str_repeat('x', Release::FILE_LIMIT + 1);
        $release = Release::from($this->row());
        try {
            $this->command('install', $this->downloads($release), $release);
            self::fail('Oversized file accepted');
        } catch (Problem $error) {
            self::assertSame('error.extension_install', $error->getMessage());
        }
        self::assertNull((new ReadModel($this->path))->file->root()->optionalSection('Extensions'));
    }

    public function testExtensionOperationCannotBypassArchiveValidationForOtherEdits(): void
    {
        mkdir($this->dir . '/data', 0750, true);
        $read = new ReadModel($this->path);
        $before = file_get_contents($this->path);
        try {
            (new \WeewxPhp\Admin\Changes($this->path, $this->now))->apply(
                $read->revision,
                'extension.install.sample',
                static function (\WeewxPhp\Config\ConfFile $file): void {
                    $file->root()->section('Archives')->section('garden')->set('database', '../../outside.sdb');
                },
            );
            self::fail('Archive validation bypassed');
        } catch (Problem $error) {
            self::assertSame('error.path', $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testSettingsForDisabledPackageAreValidatedAndSecretsStayOutOfHtml(): void
    {
        $this->bodies['settings.json'] = '{"schema":1,"scope":"global","fields":['
            . '{"key":"start","type":"integer","default":1991,"min":1940,"max":"previous_year","label":{"en":"Start","de":"Beginn"}},'
            . '{"key":"end","type":"integer","default":2020,"min":1949,"max":"previous_year","label":{"en":"End"}},'
            . '{"key":"archives","type":"archives","default":[],"label":{"en":"Archives"}},'
            . '{"key":"api_key","type":"secret","format":"api_key","default":"","label":{"en":"API key"}}],'
            . '"rules":[{"from":"start","to":"end","min":9,"max":79}]}';
        $row = $this->row();
        $row['settings'] = 'settings.json';
        $row['api'] = 2;
        $release = Release::from($row);
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        $options = ['start' => '1991', 'end' => '2020', 'archives' => ['garden'], 'api_key' => 'secret_test_key'];
        $save = function (array $options, array $clear = []) use ($http): void {
            (new ExtensionService($this->path, $this->now, $http))->execute('extension.settings', [
                'revision' => (new ReadModel($this->path))->revision, 'extension' => 'sample', 'complete' => '1',
                'schema' => hash('sha256', $this->bodies['settings.json']), 'options' => $options, 'clear' => $clear,
            ]);
        };
        $save($options);
        self::assertFileDoesNotExist($this->dir . '/executed');
        $read = new ReadModel($this->path);
        self::assertSame([], $read->config->extensions);
        $html = (new \WeewxPhp\Admin\Page($read, new \WeewxPhp\Admin\Translator('de'), 'csrf'))->render('extensions', ['extension' => 'sample']);
        self::assertStringContainsString('Beginn', $html);
        self::assertStringContainsString('extension-nav', $html);
        self::assertStringNotContainsString('secret_test_key', $html);
        self::assertStringContainsString('type="password" value=""', $html);
        $options['api_key'] = '';
        $save($options);
        self::assertSame('secret_test_key', (new ReadModel($this->path))->file->root()->section('Extensions')->section('sample')->section('options')->value('api_key')->string());
        $before = file_get_contents($this->path);
        foreach ([['end' => '1995'], ['archives' => ['unknown']], ['start' => '2099'], ['api_key' => "bad\nkey"]] as $bad) {
            try {
                $save(array_replace($options, $bad));
                self::fail('Invalid settings accepted');
            } catch (Problem $error) {
                self::assertSame('error.extension_settings', $error->getMessage());
            }
            self::assertSame($before, file_get_contents($this->path));
        }
        $save($options, ['api_key' => '1']);
        self::assertSame('', (new ReadModel($this->path))->file->root()->section('Extensions')->section('sample')->section('options')->value('api_key')->string());
        self::assertCount(4, $http->requests); // Saving and rendering settings never fetches or executes package code.
    }

    public function testArchiveSettingsOverrideDefaultsInExtensionContext(): void
    {
        $this->bodies['settings.json'] = '{"schema":1,"scope":"archive","fields":[{"key":"days","type":"integer","default":7,"min":1,"max":16,"label":{"en":"Days"}}]}';
        $row = $this->row();
        $row['api'] = 2;
        $row['settings'] = 'settings.json';
        $release = Release::from($row);
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        foreach (['' => '5', 'garden' => '10'] as $archive => $days) {
            (new ExtensionService($this->path, $this->now, $http))->execute('extension.settings', [
                'revision' => (new ReadModel($this->path))->revision, 'extension' => 'sample', 'complete' => '1',
                'schema' => hash('sha256', $this->bodies['settings.json']), 'options' => ['days' => $days], 'archive' => $archive,
            ]);
        }
        $this->command('enable', $http->answer(200, $this->catalog($release)), $release);
        $definition = (new ReadModel($this->path))->config->extensions['sample'];
        self::assertSame(5, $definition->optionsFor('another')->value('days')->int());
        self::assertSame(10, $definition->optionsFor('garden')->value('days')->int());
        $html = (new \WeewxPhp\Admin\Page(new ReadModel($this->path), new \WeewxPhp\Admin\Translator(), 'csrf'))->render('extensions', ['extension' => 'sample', 'archive' => 'garden']);
        self::assertStringContainsString('value="10"', $html);
        self::assertStringContainsString('extension-scopes', $html);
        self::assertFileDoesNotExist($this->dir . '/executed');
    }
}
