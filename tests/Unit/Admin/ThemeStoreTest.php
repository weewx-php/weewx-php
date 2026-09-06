<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Admin\Page;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Admin\ThemeRegistry;
use WeewxPhp\Admin\ThemeService;
use WeewxPhp\Admin\Translator;
use WeewxPhp\Extension\Catalog;
use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Extension\Release;
use WeewxPhp\Frontend\ThemeSite;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Lock;

final class ThemeStoreTest extends TestCase
{
    private string $dir;
    private string $path;
    private int $now;
    private string $oldLog;
    /** @var array<string, string> */
    private array $bodies;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('theme-store');
        $this->path = $this->dir . '/weather.conf';
        $this->now = time();
        file_put_contents($this->path, "data_dir = {$this->dir}/data\nbackup_enabled = false\n[Archives]\n [[garden]]\n");
        $marker = var_export($this->dir . '/executed', true);
        $this->bodies = [
            'theme.php' => '<?php file_put_contents(' . $marker . ', "loaded"); return static fn(): string => "<h1>Sample</h1>";',
            'settings.json' => '{"theme":"sample","schema_version":1,"fields":[{"key":"days","type":"integer","default":3,"min":1,"max":10}]}',
            'locales/en.json' => '{"days":"Days"}',
            'assets/sample.css' => 'body { color: black; }',
            'review.md' => 'Reviewed test fixture.',
        ];
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
        return ['id' => 'sample', 'name' => 'Sample <script>alert(1)</script>', 'description' => 'Live readings',
            'version' => '0.1.0', 'repository' => 'weewx-php/theme-sample', 'commit' => str_repeat('a', 40),
            'entry' => 'theme.php', 'api' => 1, 'php' => '8.1', 'requires' => ['json'],
            'files' => array_map(static fn(string $body): string => hash('sha256', $body), $this->bodies),
            'reviewed_at' => '2026-09-06', 'review' => 'review.md',
            'translations' => ['de' => ['description' => 'Live-Messwerte']]];
    }

    private function catalog(Release $release): string
    {
        return '{"schema":1,"themes":[' . $release->json() . ']}';
    }

    /** @param array<string, string> $extra */
    private function command(string $action, FakeHttpClient $http, Release $release, array $extra = []): void
    {
        (new ThemeService($this->path, $this->now, $http))->execute(
            'theme.' . $action,
            $extra + ['revision' => (new ReadModel($this->path))->revision, 'theme' => $release->id, 'release' => $release->fingerprint()],
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

    private function installer(): Installer
    {
        return new Installer(new Files($this->dir . '/data/theme-store'), new FakeHttpClient());
    }

    public function testInstallIsInactiveAndCodeLoadsOnlyOnPublicRequestAfterActivation(): void
    {
        $release = Release::from($this->row());
        $http = $this->downloads($release);
        $this->command('install', $http, $release);
        $read = new ReadModel($this->path);
        self::assertSame('basic', ThemeRegistry::configured($this->path)->active($read->file));
        self::assertFileDoesNotExist($this->dir . '/executed');
        self::assertSame(Catalog::THEME_URL, $http->requests[0]->url);
        self::assertCount(6, $http->requests);
        $this->command('enable', $http->answer(200, $this->catalog($release)), $release);
        self::assertFileDoesNotExist($this->dir . '/executed');
        $response = ThemeSite::respond($this->path, 'GET', []);
        self::assertSame(200, $response->status);
        self::assertSame('<h1>Sample</h1>', $response->body);
        self::assertFileExists($this->dir . '/executed');
        self::assertSame($this->bodies['assets/sample.css'], ThemeSite::asset($this->path, 'GET', '/sample/sample.css')->body);
        self::assertSame(404, ThemeSite::asset($this->path, 'GET', '/sample/theme.php')->status);
    }

    public function testUpdatesRetainActivationAndSettingsAndRemovalFallsBackToBasic(): void
    {
        $first = Release::from($this->row());
        $this->command('install', $this->downloads($first), $first);
        $this->command('enable', (new FakeHttpClient())->answer(200, $this->catalog($first)), $first);
        (new Service($this->path, $this->now))->execute('theme.save', ['theme' => 'sample', 'days' => '7', 'revision' => (new ReadModel($this->path))->revision]);
        $this->bodies['assets/sample.css'] = 'body { color: blue; }';
        $row = $this->row();
        $row['version'] = '0.2.0';
        $second = Release::from($row);
        $this->command('install', $this->downloads($second), $second);
        $read = new ReadModel($this->path);
        self::assertSame('sample', ThemeRegistry::configured($this->path)->active($read->file));
        self::assertSame('7', $read->file->root()->section('Themes')->section('sample')->value('days')->string());
        self::assertSame($this->installer()->directory($second), $read->file->root()->section('Themes')->section('sample')->value('directory')->string());
        $this->command('disable', new FakeHttpClient(), $second);
        self::assertSame('basic', ThemeRegistry::configured($this->path)->active((new ReadModel($this->path))->file));
        $this->command('enable', (new FakeHttpClient())->answer(200, $this->catalog($second)), $second);
        $this->command('remove', new FakeHttpClient(), $second);
        $read = new ReadModel($this->path);
        self::assertSame('basic', ThemeRegistry::configured($this->path)->active($read->file));
        self::assertFalse($read->file->root()->section('Themes')->has('sample'));
        self::assertFileExists($this->installer()->entry($first));
        self::assertFileExists($this->installer()->entry($second));
    }

    public function testFailedUpdateKeepsWorkingVersion(): void
    {
        $first = Release::from($this->row());
        $this->command('install', $this->downloads($first), $first);
        $before = file_get_contents($this->path);
        $this->bodies['assets/sample.css'] = 'new';
        $second = Release::from($this->row());
        try {
            $this->command('install', (new FakeHttpClient())->answer(200, $this->catalog($second))->answer(200, 'wrong checksum'), $second);
            self::fail('Corrupt downloads must fail');
        } catch (Problem $problem) {
            self::assertSame('error.theme_install', $problem->getMessage());
        }
        self::assertSame($before, file_get_contents($this->path));
        self::assertFileExists($this->installer()->entry($first));
        self::assertFileDoesNotExist($this->dir . '/executed');
    }

    public function testRevokedAndChangedApprovalsCannotActivateFromCachedCatalog(): void
    {
        $release = Release::from($this->row());
        $this->command('install', $this->downloads($release), $release);
        foreach ([[200, '{"schema":1,"themes":[]}', 'error.theme_unapproved'], [503, '', 'error.theme_install']] as [$status, $body, $error]) {
            try {
                $this->command('enable', (new FakeHttpClient())->answer($status, $body), $release);
                self::fail('Fresh approval required');
            } catch (Problem $problem) {
                self::assertSame($error, $problem->getMessage());
            }
        }
        $row = $this->row();
        $row['version'] = '0.2.0';
        $changed = Release::from($row);
        $this->expectExceptionMessage('error.theme_changed');
        $this->command('enable', (new FakeHttpClient())->answer(200, $this->catalog($changed)), $release);
    }

    public function testModifiedInstalledCodeCannotActivate(): void
    {
        $release = Release::from($this->row());
        $this->command('install', $this->downloads($release), $release);
        file_put_contents($this->installer()->entry($release), '<?php throw new Exception();');
        $this->expectExceptionMessage('error.theme_install');
        $this->command('enable', (new FakeHttpClient())->answer(200, $this->catalog($release)), $release);
    }

    public function testSettingsCannotBypassManagedActivation(): void
    {
        $release = Release::from($this->row());
        $this->command('install', $this->downloads($release), $release);
        $this->expectExceptionMessage('error.theme_activation');
        (new Service($this->path, $this->now))->execute('theme.save', ['theme' => 'sample', 'days' => '3', 'activate' => 'true', 'revision' => (new ReadModel($this->path))->revision]);
    }

    public function testBasicThemeCannotBeInstalledRemovedOrDisabled(): void
    {
        $release = Release::from($this->row());
        foreach (['install', 'remove', 'disable'] as $action) {
            $http = new FakeHttpClient();
            try {
                $this->command($action, $http, $release, ['theme' => 'basic']);
                self::fail('Core theme must remain available');
            } catch (Problem $problem) {
                self::assertSame('error.theme_basic', $problem->getMessage());
            }
            self::assertCount(0, $http->requests);
        }
        $this->command('enable', new FakeHttpClient(), $release, ['theme' => 'basic']);
    }

    public function testManualThemeCannotBeReplacedOrRemoved(): void
    {
        file_put_contents($this->path, "\n[Themes]\n [[sample]]\n  directory = {$this->dir}/manual\n", FILE_APPEND);
        $release = Release::from($this->row());
        foreach (['install', 'remove'] as $action) {
            try {
                $this->command($action, new FakeHttpClient(), $release);
                self::fail('Manual packages belong to their operator');
            } catch (Problem $problem) {
                self::assertSame('error.theme_manual', $problem->getMessage());
            }
        }
    }

    public function testInvalidThemeMetadataDoesNotRegisterPackage(): void
    {
        $this->bodies['settings.json'] = '{"theme":"sample","schema_version":1,"fields":[{"key":"managed_release","type":"text"}]}';
        $release = Release::from($this->row());
        try {
            $this->command('install', $this->downloads($release), $release);
            self::fail('Reserved metadata keys must fail');
        } catch (Problem) {
            self::assertNull((new ReadModel($this->path))->file->root()->optionalSection('Themes'));
        }
        self::assertFileDoesNotExist($this->dir . '/executed');
    }

    public function testCatalogRejectsOtherPackageKindsAndMissingEnglishLocale(): void
    {
        foreach (['core', 'reserved', 'repository', 'locale', 'entry'] as $mutation) {
            $row = $this->row();
            if ($mutation === 'core') {
                $row['id'] = 'basic';
            } elseif ($mutation === 'reserved') {
                $row['id'] = 'active';
            } elseif ($mutation === 'repository') {
                $row['repository'] = 'weewx-php/extension-sample';
            } elseif ($mutation === 'locale') {
                self::assertIsArray($row['files']);
                unset($row['files']['locales/en.json']);
            } else {
                $row['entry'] = 'other.php';
            }
            try {
                Catalog::parse(json_encode(['schema' => 1, 'themes' => [$row]], JSON_THROW_ON_ERROR), themes: true);
                self::fail('Invalid theme manifest accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testFutureThemeApiIsNotInstallable(): void
    {
        $row = $this->row();
        $row['api'] = 2;
        $release = Release::from($row);
        $this->expectExceptionMessage('error.theme_incompatible');
        $this->command('install', (new FakeHttpClient())->answer(200, $this->catalog($release)), $release);
    }

    public function testStaleConfigurationAndBusyStoreDoNotDownload(): void
    {
        $release = Release::from($this->row());
        $http = new FakeHttpClient();
        try {
            $this->command('install', $http, $release, ['revision' => 'stale']);
            self::fail('Stale revision accepted');
        } catch (Problem $problem) {
            self::assertSame(409, $problem->status);
        }
        $files = new Files($this->dir . '/data/theme-store');
        $files->directory();
        $lock = Lock::tryAcquire($files->root . '/operation.lock');
        self::assertNotNull($lock);
        try {
            $this->command('install', $http, $release);
            self::fail('Concurrent operation accepted');
        } catch (Problem $problem) {
            self::assertSame('error.busy', $problem->getMessage());
        } finally {
            $lock->release();
        }
        self::assertCount(0, $http->requests);
    }

    public function testShopAndSettingsStayUsableDuringCatalogOutage(): void
    {
        $read = new ReadModel($this->path);
        $html = (new Page($read, new Translator(), 'csrf', http: (new FakeHttpClient())->answer(503)))->render('themes', []);
        self::assertStringContainsString('Could not load the theme catalog.', $html);
        self::assertStringContainsString('Basic', $html);
        self::assertStringNotContainsString('value="theme.remove"', $html);
        $http = new FakeHttpClient();
        $settings = (new Page($read, new Translator(), 'csrf', http: $http))->render('themes', ['theme' => 'basic']);
        self::assertStringContainsString('theme.save', $settings);
        self::assertCount(0, $http->requests);
    }

    public function testControllerEnforcesAuthenticationCsrfAndShopRedirects(): void
    {
        $release = Release::from($this->row());
        $http = $this->downloads($release);
        $controller = new Controller($this->path, $this->now, http: $http);
        $controller->handle('GET', ['page' => 'themes'], [], null, '127.0.0.1', true);
        self::assertCount(0, $http->requests);
        $auth = new Auth((new ReadModel($this->path))->config->settings);
        try {
            $auth->setPassword('correct horse battery staple', $this->now);
            $anonymous = $auth->session(null, $this->now);
            $session = $auth->login($anonymous['token'], $anonymous['csrf'], 'correct horse battery staple', '127.0.0.1', $this->now);
        } finally {
            $auth->close();
        }
        $post = ['action' => 'theme.install', 'theme' => 'sample', 'release' => $release->fingerprint(), 'revision' => (new ReadModel($this->path))->revision];
        $blocked = $controller->handle('POST', ['page' => 'themes'], $post + ['csrf' => 'wrong'], $session['token'], '127.0.0.1', true);
        self::assertSame(403, $blocked->status);
        self::assertCount(0, $http->requests);
        $response = $controller->handle('POST', ['page' => 'themes'], $post + ['csrf' => $session['csrf']], $session['token'], '127.0.0.1', true);
        self::assertSame(303, $response->status);
        self::assertSame('?page=themes&saved=1&result=installed&lang=en', $response->location);
        $page = $controller->handle('GET', ['page' => 'themes', 'lang' => 'de'], [], $session['token'], '127.0.0.1', true);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Live-Messwerte', $page->body);
        self::assertStringContainsString('Aktivieren', $page->body);
        self::assertStringContainsString('&lt;script&gt;', $page->body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page->body);
        self::assertFileDoesNotExist($this->dir . '/executed');
    }
}
