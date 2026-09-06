<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\ThemeRegistry;
use WeewxPhp\BasicTheme\View;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Frontend\ThemeSite;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\UnitSystem;

require_once dirname(__DIR__, 3) . '/themes/basic/View.php';

final class ThemeSiteTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('theme-site');
        $this->path = $this->dir . '/station.conf';
        file_put_contents($this->path, "data_dir = data\n[Archives]\n    [[garden]]\n        database = missing.sdb\n        name = Garden\n        unit_system = METRICWX\n");
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testBasicUsesLiveMeasurementsWithoutAnArchiveOrAnalyticsDatabase(): void
    {
        $archive = Archives::config(database: $this->dir . '/absent.sdb');
        $config = new Config(Archives::settings($this->dir), [], [$archive->id => $archive], []);
        $live = LiveDb::open($config->settings->liveDbPath(), JournalMode::Wal);
        $live->add(new Packet(1000, UnitSystem::US, ['outTemp' => 68, 'outHumidity' => 0, 'windSpeed' => 0, 'inTemp' => 90], 'ecowitt'), [], 300);
        $live->close();
        $wx = new Weather($config, clock: new FixedClock(1010));
        try {
            $snapshot = View::snapshot(...);
            $data = $snapshot($wx);
            self::assertSame('20.0 °C', $data['fields']['outTemp']['formatted']);
            self::assertSame('en', $data['language']);
            self::assertSame('0%', $data['fields']['outHumidity']['formatted']);
            self::assertSame('—', $data['fields']['barometer']['formatted']);
            self::assertSame('ready', $data['fields']['outTemp']['status']);
            self::assertSame(1000, $data['fields']['outTemp']['asOf']);
            self::assertArrayNotHasKey('inTemp', $data['fields']);
            $html = View::render($wx);
            self::assertStringContainsString('Kirchdorf', $html);
            self::assertStringContainsString('20.0 °C', $html);
            $german = \WeewxPhp\Frontend\Theme::configured($this->path, language: 'de');
            self::assertSame('20,0 °C', $snapshot($wx, $german)['fields']['outTemp']['formatted']);
            self::assertStringContainsString('Live-Daten', View::render($wx, $german));
            $stale = $snapshot(new Weather($config, clock: new FixedClock(1201)));
            self::assertSame('stale', $stale['fields']['outTemp']['status']);
            self::assertSame(1000, $stale['fields']['outTemp']['asOf']);
            self::assertFileDoesNotExist($archive->database);
            self::assertFileDoesNotExist($this->dir . '/analytics.sdb');
        } finally {
            $wx->close();
        }
    }

    public function testDefaultAndMissingThemeRenderBasicAndIgnoreHttpThemePaths(): void
    {
        $config = file_get_contents($this->path);
        self::assertIsString($config);
        file_put_contents($this->path, str_replace('name = Garden', 'name = "<script>garden</script>"', $config));
        foreach (['', "\n[Themes]\n    active = removed\n"] as $suffix) {
            file_put_contents($this->path, $suffix, FILE_APPEND);
            $response = ThemeSite::respond($this->path, 'GET', ['theme' => '../../secret', 'range' => ['bad']]);
            self::assertSame(200, $response->status, $response->body);
            self::assertStringContainsString('Live data', $response->body);
            self::assertStringContainsString('lang="en"', $response->body);
            self::assertStringContainsString('&lt;script&gt;garden&lt;/script&gt;', $response->body);
            self::assertStringNotContainsString('Atmos', $response->body);
            self::assertSame('', ThemeSite::respond($this->path, 'HEAD', [])->body);
            self::assertSame(405, ThemeSite::respond($this->path, 'POST', [])->status);
        }
    }

    public function testExternalPackageDispatchAndAssetsDoNotExecuteUnselectedPhp(): void
    {
        mkdir($this->dir . '/outside/assets', 0777, true);
        file_put_contents($this->dir . '/outside/settings.json', '{"theme":"custom","schema_version":1,"fields":[]}');
        file_put_contents($this->dir . '/outside/theme.php', '<?php return static fn(): string => "external page";');
        file_put_contents($this->dir . '/outside/snapshot.php', '<?php return static fn(): array => ["external" => true];');
        file_put_contents($this->dir . '/outside/assets/site.css', 'body {color: red}');
        file_put_contents($this->path, "\n[Themes]\n    active = custom\n    [[custom]]\n        directory = outside\n", FILE_APPEND);
        $registry = ThemeRegistry::configured($this->path);
        self::assertContains('custom', $registry->themes());
        self::assertContains('basic', $registry->themes());
        self::assertSame('custom', $registry->active(ConfFile::read($this->path)));
        self::assertSame('external page', ThemeSite::respond($this->path, 'GET', [])->body);
        self::assertSame('{"external":true}', ThemeSite::respond($this->path, 'GET', [], snapshot: true)->body);
        $asset = ThemeSite::asset($this->path, 'GET', '/custom/site.css');
        self::assertSame(200, $asset->status);
        self::assertSame('text/css; charset=utf-8', $asset->headers['Content-Type']);
        self::assertSame('body {color: red}', $asset->body);
        foreach (['/custom/../theme.php', '/custom/../settings.json', '/custom/../../outside/assets/site.css',
            '/custom/%2e%2e/site.css', '/custom/..\\site.css', '/unknown/site.css', '/custom/site.php', '/custom/site.css:secret', '/custom/.hidden.css'] as $info) {
            self::assertSame(404, ThemeSite::asset($this->path, 'GET', $info)->status, $info);
        }
        self::assertSame('', ThemeSite::asset($this->path, 'HEAD', '/custom/site.css')->body);
        self::assertSame(405, ThemeSite::asset($this->path, 'POST', '/custom/site.css')->status);
    }

    public function testAdminCanActivateAnExternalPackageWithoutExecutingItsCode(): void
    {
        mkdir($this->dir . '/package');
        file_put_contents($this->dir . '/package/settings.json', '{"theme":"custom","schema_version":1,"fields":[]}');
        file_put_contents($this->dir . '/package/theme.php', '<?php throw new RuntimeException("must not execute in admin");');
        file_put_contents($this->path, "\n[Themes]\n    active = basic\n    [[custom]]\n        directory = package\n", FILE_APPEND);
        $read = new \WeewxPhp\Admin\ReadModel($this->path);
        mkdir($read->config->settings->dataDir, 0700, true);
        (new \WeewxPhp\Admin\Service($this->path, 1000))->execute('theme.save', [
            'theme' => 'custom', 'activate' => 'true', 'revision' => $read->revision, 'directory' => 'https://invalid.example',
        ]);
        $file = ConfFile::read($this->path);
        self::assertSame('custom', ThemeRegistry::configured($this->path)->active($file));
        self::assertSame('package', $file->root()->section('Themes')->section('custom')->value('directory')->string());
    }

    public function testVisitorUnitsReachHtmlAndSnapshotsAndOnlySuccessfulPagesSetCookies(): void
    {
        mkdir($this->dir . '/units');
        file_put_contents($this->dir . '/units/settings.json', '{"theme":"prefs","schema_version":1,"fields":[]}');
        file_put_contents($this->dir . '/units/theme.php', '<?php return static fn($wx, $theme): string => $theme->units->profile . ":" . $wx->live("outTemp")->unit;');
        file_put_contents($this->dir . '/units/snapshot.php', '<?php return static fn($wx, $theme): array => ["profile" => $theme->units->profile, "unit" => $wx->live("outTemp")->unit];');
        file_put_contents($this->path, "\n[Themes]\n    active = prefs\n    units = metricwx\n    [[prefs]]\n        directory = units\n", FILE_APPEND);
        $selected = ThemeSite::respond($this->path, 'GET', ['units' => 'us'], secure: true);
        self::assertSame('us:degree_F', $selected->body);
        self::assertSame('weewx_units=us; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure', $selected->headers['Set-Cookie']);
        $cookies = ['weewx_units' => 'us'];
        self::assertSame('us:degree_F', ThemeSite::respond($this->path, 'GET', [], cookies: $cookies)->body);
        $snapshot = ThemeSite::respond($this->path, 'GET', [], snapshot: true, cookies: $cookies);
        self::assertSame('{"profile":"us","unit":"degree_F"}', $snapshot->body);
        self::assertArrayNotHasKey('Set-Cookie', $snapshot->headers);
        $reset = ThemeSite::respond($this->path, 'GET', ['units' => 'station'], cookies: $cookies);
        self::assertSame('metricwx:degree_C', $reset->body);
        self::assertStringContainsString('Max-Age=0', $reset->headers['Set-Cookie']);
        foreach ([['units' => ['us']], ['units' => "us\r\nSet-Cookie: bad"]] as $query) {
            $response = ThemeSite::respond($this->path, 'GET', $query);
            self::assertSame('metricwx:degree_C', $response->body);
            self::assertArrayNotHasKey('Set-Cookie', $response->headers);
        }
        self::assertArrayNotHasKey('Set-Cookie', ThemeSite::respond($this->path, 'HEAD', ['units' => 'us'])->headers);
    }

    public function testRemotePackageDirectoriesAreRejected(): void
    {
        file_put_contents($this->path, "\n[Themes]\n    [[custom]]\n        directory = https://invalid.example/package\n", FILE_APPEND);
        $this->expectException(\WeewxPhp\Admin\Problem::class);
        ThemeRegistry::configured($this->path);
    }

    public function testMissingConfigurationReturnsOnlyGenericErrorAndNoHeadBody(): void
    {
        $missing = $this->dir . '/absent.conf';
        $response = ThemeSite::respond($missing, 'GET', []);
        self::assertSame(503, $response->status);
        self::assertStringContainsString('No weather data', $response->body);
        self::assertStringNotContainsString($missing, $response->body);
        self::assertSame('', ThemeSite::respond($missing, 'HEAD', [])->body);
        self::assertSame('{"error":"unavailable"}', ThemeSite::respond($missing, 'GET', [], snapshot: true)->body);
    }

    public function testConfiguredLanguageAppliesToPageAndSnapshotWithEnglishFallback(): void
    {
        file_put_contents($this->path, "\n[Themes]\n    language = de\n", FILE_APPEND);
        self::assertSame('de', \WeewxPhp\Frontend\Theme::configured($this->path)->language);
        file_put_contents($this->path, "    [[basic]]\n        language = en\n", FILE_APPEND);
        self::assertSame('en', \WeewxPhp\Frontend\Theme::configured($this->path)->language);
        $file = ConfFile::read($this->path);
        $file->root()->section('Themes')->section('basic')->set('language', 'de');
        $file->write($this->path);
        $response = ThemeSite::respond($this->path, 'GET', []);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('lang="de"', $response->body);
        self::assertStringContainsString('Live-Daten', $response->body);
        self::assertStringContainsString('"language":"de"', ThemeSite::respond($this->path, 'GET', [], snapshot: true)->body);
        $fallback = \WeewxPhp\Frontend\Theme::configured($this->path, language: '../../private');
        self::assertSame('en', $fallback->language);
        self::assertSame('Live data', $fallback->text('Live data'));
        self::assertSame('Untranslated text', $fallback->text('Untranslated text'));
    }
}
