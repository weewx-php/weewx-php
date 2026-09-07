<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Admin\CoreUpdateService;
use WeewxPhp\Admin\Page;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Admin\Translator;
use WeewxPhp\CoreUpdate\Builder;
use WeewxPhp\CoreUpdate\GitHub;
use WeewxPhp\CoreUpdate\Guard;
use WeewxPhp\CoreUpdate\Installer;
use WeewxPhp\CoreUpdate\Package;
use WeewxPhp\CoreUpdate\Release;
use WeewxPhp\Extension\Files;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Http\HttpResponse;
use WeewxPhp\Version;

final class CoreUpdateTest extends TestCase
{
    private string $dir;
    private string $root;
    private string $path;
    private int $now = 1788710400;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('core-update');
        $this->root = $this->dir . '/core';
        $this->path = $this->root . '/weewx-php.conf';
        foreach (['src/CoreUpdate', 'public/admin', 'themes/basic', 'themes/custom', 'data', 'extensions/custom'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
        }
        foreach (['src/autoload.php', 'src/CoreUpdate/Guard.php', 'src/CoreUpdate/Installer.php'] as $file) {
            copy(dirname(__DIR__, 3) . '/' . $file, $this->root . '/' . $file);
        }
        file_put_contents($this->path, 'data_dir = ' . $this->root . "/data\ntimezone = UTC\n");
        file_put_contents($this->root . '/public/index.php', '<?php echo "old";');
        file_put_contents($this->root . '/themes/custom/theme.php', 'custom theme');
        file_put_contents($this->root . '/extensions/custom/extension.php', 'custom extension');
        file_put_contents($this->root . '/data/observations.sdb', 'weather data');
    }

    protected function tearDown(): void
    {
        Guard::leave($this->root);
        TempDir::remove($this->dir);
    }

    /** @param array<string, string> $changes */
    private function package(string $version = '99.0.0', array $changes = []): string
    {
        $bodies = array_fill_keys(Package::MARKERS, '<?php /* release */');
        $bodies['src/autoload.php'] = (string) file_get_contents(dirname(__DIR__, 3) . '/src/autoload.php');
        $bodies['src/CoreUpdate/Guard.php'] = (string) file_get_contents(dirname(__DIR__, 3) . '/src/CoreUpdate/Guard.php');
        $bodies['src/Version.php'] = "<?php namespace WeewxPhp; final class Version { public const STRING = '" . $version . "'; }";
        $bodies['public/index.php'] = '<?php echo "new";';
        $files = [];
        foreach (array_replace($bodies, $changes) as $name => $body) {
            $files[$name] = ['body' => base64_encode($body), 'sha256' => hash('sha256', $body)];
        }
        return json_encode(['schema' => 1, 'version' => $version, 'php' => '8.1', 'requires' => ['json', 'sqlite3', 'phar'], 'files' => $files], JSON_THROW_ON_ERROR);
    }

    private function metadata(string $version = '99.0.0', ?string $body = null): string
    {
        $body ??= $this->package($version);
        return json_encode(['tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => str_contains($version, '-beta.'), 'assets' => [[
            'name' => Release::ASSET, 'state' => 'uploaded', 'browser_download_url' => 'https://github.com/' . Release::REPOSITORY . '/releases/download/v' . $version . '/' . Release::ASSET,
            'size' => strlen($body), 'digest' => 'sha256:' . hash('sha256', $body),
        ]]], JSON_THROW_ON_ERROR);
    }

    private function release(string $version = '99.0.0', bool $beta = false): Release
    {
        return Release::from(json_decode($this->metadata($version), true, 32, JSON_THROW_ON_ERROR), $beta);
    }

    public function testStableIsDefaultAndBetaRequiresExplicitSelection(): void
    {
        $http = (new FakeHttpClient())->answer(200, $this->metadata());
        $files = new Files($this->root . '/data/core-updates');
        $github = new GitHub($files, $http);
        self::assertSame('99.0.0', $github->check()?->version);
        self::assertSame(Release::API, $http->last()->url);
        self::assertNull((new GitHub($files, $http, 'beta'))->cached());
        $this->expectException(\InvalidArgumentException::class);
        $http->answer(200, $this->metadata('100.0.0-beta.1'));
        $github->check();
    }

    public function testBetaChannelSelectsNewestVersionAndStablePromotions(): void
    {
        $files = new Files($this->root . '/data/core-updates');
        $http = new FakeHttpClient();
        $github = new GitHub($files, $http, 'beta');
        $http->answer(200, '[' . $this->metadata('99.0.0-beta.2') . ',' . $this->metadata('98.0.0') . ',' . $this->metadata('99.0.0-beta.10') . ']');
        self::assertSame('99.0.0-beta.10', $github->check()?->version);
        self::assertSame('https://api.github.com/repos/weewx-php/weewx-php/releases?per_page=100', $http->last()->url);
        self::assertSame('99.0.0-beta.10', $github->cached()?->version);
        self::assertNull((new GitHub($files, $http))->cached());
        $http->answer(200, '[' . $this->metadata('99.0.0-beta.10') . ',' . $this->metadata('99.0.0') . ']');
        self::assertSame('99.0.0', $github->check()->version);
    }

    public function testNoReleaseAndFailedChecksNeverInstallFromCache(): void
    {
        $files = new Files($this->root . '/data/core-updates');
        $http = (new FakeHttpClient())->answer(404);
        $github = new GitHub($files, $http);
        self::assertNull($github->check());
        self::assertTrue($github->checked());
        $http->answer(200, $this->metadata());
        $github->check();
        $http->answer(403);
        try {
            (new CoreUpdateService($this->path, $this->now, $http, $this->root))->execute('core.install', ['revision' => (new ReadModel($this->path))->revision, 'release' => $this->release()->fingerprint()]);
            self::fail('An offline cached release was installed');
        } catch (Problem $error) {
            self::assertSame('error.core_update', $error->getMessage());
        }
        self::assertSame('<?php echo "old";', file_get_contents($this->root . '/public/index.php'));
    }

    public function testDraftsMislabeledBetasAndNonCanonicalVersionsAreRejected(): void
    {
        $valid = json_decode($this->metadata(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($valid);
        foreach ([['draft' => true], ['prerelease' => true], ['tag_name' => 'v99.0.0-beta.1'], ['tag_name' => 'v099.0.0'], ['tag_name' => 'v99.0.0-beta.01'], ['tag_name' => 'v99.0.0-rc.1']] as $change) {
            try {
                Release::from(array_replace($valid, $change), true);
                self::fail('Accepted invalid release metadata');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid core release', $error->getMessage());
            }
        }
        $http = (new FakeHttpClient())->answer(200, json_encode([array_replace($valid, ['draft' => true])], JSON_THROW_ON_ERROR));
        self::assertNull((new GitHub(new Files($this->root . '/data/core-updates'), $http, 'beta'))->check());
    }

    public function testCurrentOrOlderReleaseCannotBeInstalled(): void
    {
        file_put_contents($this->path, "[Admin]\nupdate_channel = beta\n", FILE_APPEND);
        foreach ([Version::STRING, '0.0.0'] as $version) {
            $http = (new FakeHttpClient())->answer(200, '[' . $this->metadata($version) . ']');
            try {
                (new CoreUpdateService($this->path, $this->now, $http, $this->root))->execute('core.install', [
                    'revision' => (new ReadModel($this->path))->revision,
                    'release' => $this->release($version, true)->fingerprint(),
                ]);
                self::fail('Installed a current or older version');
            } catch (Problem $error) {
                self::assertSame('error.core_changed', $error->getMessage());
            }
            self::assertCount(1, $http->requests);
            self::assertSame('<?php echo "old";', file_get_contents($this->root . '/public/index.php'));
        }
    }

    public function testReleasePackageInstallsAndBootsFromDisk(): void
    {
        $path = getenv('WEEWX_CORE_PACKAGE');
        $json = $path === false ? Builder::build(dirname(__DIR__, 3), 'v' . Version::STRING) : file_get_contents($path);
        self::assertIsString($json);
        $package = Package::parse($json, Version::STRING);
        (new Installer($this->root))->install($package);
        foreach ($package->files as $name => $body) {
            self::assertSame(hash('sha256', $body), hash_file('sha256', $this->root . '/' . $name), $name);
        }
        // A separate process must resolve all runtime dependencies from the installed package.
        $code = 'require ' . var_export($this->root . '/src/autoload.php', true) . '; echo \\WeewxPhp\\Version::STRING;';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertSame(Version::STRING, $output);

        $process = proc_open([PHP_BINARY, $this->root . '/bin/weewx-php', 'help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertStringContainsString('weewx-php', (string) $output);
        self::assertSame('weather data', file_get_contents($this->root . '/data/observations.sdb'));
        self::assertSame('custom theme', file_get_contents($this->root . '/themes/custom/theme.php'));
        self::assertFileDoesNotExist($this->root . '/.weewx-deploy/pending.json');
    }

    public function testDownloadFollowsOnlyGithubAssetHostsAndChecksDigest(): void
    {
        $package = $this->package();
        $http = new class ($package) implements HttpClient {
            public int $calls = 0;
            public string $redirect = 'https://release-assets.githubusercontent.com/asset?signature=test';
            public function __construct(public string $body) {}
            public function send(HttpRequest $request): HttpResponse
            {
                ++$this->calls;
                return $this->calls % 2 === 1 ? new HttpResponse(302, '', ['location' => $this->redirect]) : new HttpResponse(200, $this->body);
            }
        };
        $github = new GitHub(new Files($this->root . '/data/core-updates'), $http);
        self::assertSame('99.0.0', $github->download($this->release())->version);
        $http->redirect = 'https://127.0.0.1/private';
        try {
            $github->download($this->release());
            self::fail('An off-host redirect was followed');
        } catch (\RuntimeException $error) {
            self::assertSame('Unsafe core download redirect', $error->getMessage());
        }
        self::assertSame(3, $http->calls);
        $this->expectException(\RuntimeException::class);
        (new GitHub(new Files($this->root . '/data/core-updates'), (new FakeHttpClient())->answer(200, $package . ' ')))->download($this->release());
    }

    public function testPackageRejectsTraversalProtectedFilesAndInvalidPhpBeforeInstallation(): void
    {
        foreach (['../weewx-php.conf', 'data/archive.php', 'themes/custom/theme.php', 'public/../../data/archive.php', 'public/.htaccess', 'src/../Version.php'] as $path) {
            try {
                Package::parse($this->package(changes: [$path => 'changed']), '99.0.0');
                self::fail('Accepted unsafe path: ' . $path);
            } catch (\InvalidArgumentException) {
                self::assertFileDoesNotExist($this->root . '/.weewx-deploy/pending.json');
            }
        }
        $this->expectException(\ParseError::class);
        Package::parse($this->package(changes: ['src/broken.php' => '<?php syntax error']), '99.0.0');
    }

    public function testInstallPreservesDataConfigurationExtensionsAndThemes(): void
    {
        $protected = ['weewx-php.conf', 'data/observations.sdb', 'themes/custom/theme.php', 'extensions/custom/extension.php'];
        $before = [];
        foreach ($protected as $path) {
            $before[$path] = file_get_contents($this->root . '/' . $path);
        }
        $id = (new Installer($this->root))->install(Package::parse($this->package(), '99.0.0'));
        self::assertSame('<?php echo "new";', file_get_contents($this->root . '/public/index.php'));
        self::assertSame('<?php echo "old";', file_get_contents($this->root . '/.weewx-deploy/' . $id . '/backup/public/index.php'));
        self::assertFileDoesNotExist($this->root . '/.weewx-deploy/pending.json');
        foreach ($before as $path => $body) {
            self::assertSame($body, file_get_contents($this->root . '/' . $path), $path);
        }
    }

    public function testCorruptOrIncompatiblePackagesAndChangedReleasesAreRejected(): void
    {
        $valid = $this->package();
        foreach ([str_replace(hash('sha256', '<?php echo "new";'), str_repeat('0', 64), $valid), str_replace('"php":"8.1"', '"php":"99.0"', $valid)] as $package) {
            try {
                Package::parse($package, '99.0.0');
                self::fail('Accepted invalid core package');
            } catch (\InvalidArgumentException) {
                self::assertSame('<?php echo "old";', file_get_contents($this->root . '/public/index.php'));
            }
        }
        $http = (new FakeHttpClient())->answer(200, $this->metadata('99.0.1'));
        $input = ['revision' => (new ReadModel($this->path))->revision, 'release' => $this->release()->fingerprint()];
        try {
            (new CoreUpdateService($this->path, $this->now, $http, $this->root))->execute('core.install', $input);
            self::fail('Installed a release different from the reviewed form');
        } catch (Problem $error) {
            self::assertSame('error.core_changed', $error->getMessage());
        }
        self::assertCount(1, $http->requests);
        $http->answer(200, $this->metadata())->answer(200, $valid);
        (new CoreUpdateService($this->path, $this->now, $http, $this->root))->execute('core.install', $input);
        self::assertSame('<?php echo "new";', file_get_contents($this->root . '/public/index.php'));
    }

    public function testActiveRequestsBlockReplacementAndRetryWorks(): void
    {
        Guard::enter($this->root);
        $peer = fopen($this->root . '/.weewx-deploy/runtime.lock', 'r');
        self::assertIsResource($peer);
        flock($peer, LOCK_SH);
        try {
            (new Installer($this->root))->install(Package::parse($this->package(), '99.0.0'));
            self::fail('Replaced files used by another request');
        } catch (\RuntimeException $error) {
            self::assertSame('Other PHP requests are still running', $error->getMessage());
        } finally {
            fclose($peer);
        }
        self::assertSame('<?php echo "old";', file_get_contents($this->root . '/public/index.php'));
        (new Installer($this->root))->install(Package::parse($this->package(), '99.0.0'));
        self::assertSame('<?php echo "new";', file_get_contents($this->root . '/public/index.php'));
    }

    public function testSymlinksCannotRedirectAnUpdateOutsideTheCore(): void
    {
        symlink($this->path, $this->root . '/public/link.php');
        $before = file_get_contents($this->path);
        try {
            (new Installer($this->root))->install(Package::parse($this->package(changes: ['public/link.php' => '<?php echo "bad";']), '99.0.0'));
            self::fail('Followed a core symlink');
        } catch (\RuntimeException $error) {
            self::assertSame('Symlink in core update path', $error->getMessage());
        } finally {
            unlink($this->root . '/public/link.php');
        }
        self::assertSame($before, file_get_contents($this->path));
        self::assertFileDoesNotExist($this->root . '/.weewx-deploy/pending.json');
    }

    public function testInterruptedUpdateRecoversBeforeLoadingTheNewCore(): void
    {
        $package = $this->package(changes: ['src/CoreUpdate/Installer.php' => '<?php throw new RuntimeException("Do not execute the new installer");']);
        $id = (new Installer($this->root))->install(Package::parse($package, '99.0.0'));
        $directory = $this->root . '/.weewx-deploy';
        copy($directory . '/' . $id . '/manifest.json', $directory . '/pending.json');
        Guard::leave($this->root);
        $code = 'require ' . var_export($this->root . '/src/autoload.php', true) . '; echo file_get_contents(' . var_export($this->root . '/public/index.php', true) . ');';
        // A new process has neither the old autoloader nor installer in memory.
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertSame('<?php echo "old";', $output);
        self::assertFileDoesNotExist($directory . '/pending.json');
    }

    public function testAdminRequiresLoginAndCsrfAndPersistsChannelSelection(): void
    {
        $http = (new FakeHttpClient())->answer(404);
        $controller = new Controller($this->path, $this->now, http: $http);
        $auth = new Auth((new ReadModel($this->path))->config->settings);
        try {
            $auth->setPassword('core update test password', $this->now);
            $anonymous = $auth->session(null, $this->now);
            $post = ['action' => 'core.check', 'revision' => (new ReadModel($this->path))->revision, 'csrf' => $anonymous['csrf']];
            self::assertSame(403, $controller->handle('POST', ['page' => 'settings'], $post, $anonymous['token'], '127.0.0.1', true)->status);
            $session = $auth->login($anonymous['token'], $anonymous['csrf'], 'core update test password', '127.0.0.1', $this->now);
            self::assertSame(403, $controller->handle('POST', ['page' => 'settings'], $post, $session['token'], '127.0.0.1', true)->status);
            self::assertCount(0, $http->requests);
            $post['csrf'] = $session['csrf'];
            self::assertSame(303, $controller->handle('POST', ['page' => 'settings'], $post, $session['token'], '127.0.0.1', true)->status);
            self::assertCount(1, $http->requests);
            (new Service($this->path, $this->now))->execute('settings.save', ['revision' => $post['revision'], 'update_channel' => 'beta']);
            $read = new ReadModel($this->path);
            self::assertSame('beta', $read->file->root()->section('Admin')->value('update_channel')->string());
            $html = (new Page($read, new Translator(), 'csrf'))->render('settings', []);
            self::assertStringContainsString('value="beta" selected', $html);
        } finally {
            $auth->close();
        }
    }

    public function testReleaseBuilderUsesRuntimePayloadAndRejectsWrongTag(): void
    {
        $root = dirname(__DIR__, 3);
        $package = Package::parse(Builder::build($root, 'v' . Version::STRING), Version::STRING);
        self::assertArrayHasKey('src/CoreUpdate/Installer.php', $package->files);
        self::assertArrayHasKey('public/assets/vendor/echarts/LICENSE', $package->files);
        self::assertArrayNotHasKey('weewx-php.conf', $package->files);
        self::assertArrayNotHasKey('tests/admin.test.mjs', $package->files);
        $this->expectException(\InvalidArgumentException::class);
        Builder::build($root, 'v999.0.0');
    }

    public function testInstallArchiveContainsOnlyRuntimeAndBoots(): void
    {
        $root = dirname(__DIR__, 3);
        $jsonPath = getenv('WEEWX_CORE_PACKAGE');
        $json = $jsonPath === false ? Builder::build($root, 'v' . Version::STRING) : file_get_contents($jsonPath);
        self::assertIsString($json);
        $zipPath = getenv('WEEWX_CORE_ZIP');
        if ($zipPath === false) {
            $zipPath = $this->dir . '/core.zip';
            Builder::installation($root, $json, $zipPath);
        }
        $archive = new \PharData($zipPath);
        $expected = Package::parse($json, Version::STRING)->files;
        foreach (['public/.htaccess' => 'public/.htaccess', 'weewx-php.conf.example' => 'weewx-php.conf.example', 'INSTALL.md' => 'docs/install-release.md'] as $name => $source) {
            $expected[$name] = (string) file_get_contents($root . '/' . $source);
        }
        $entries = iterator_to_array(new \RecursiveIteratorIterator($archive));
        self::assertCount(count($expected), $entries);
        $destination = $this->dir . '/installation';
        $archive->extractTo($destination);
        foreach ($expected as $name => $body) {
            self::assertSame($body, file_get_contents($destination . '/' . $name), $name);
        }
        foreach (['weewx-php.conf', 'data', 'tests', 'vendor', '.git', '.github', '.deploy', 'extensions', 'themes/custom'] as $name) {
            self::assertFalse(file_exists($destination . '/' . $name), $name);
        }
        copy($destination . '/weewx-php.conf.example', $destination . '/weewx-php.conf');
        $process = proc_open([PHP_BINARY, $destination . '/bin/weewx-php', 'check-config'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $destination);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $output . (string) $error);
    }
}
