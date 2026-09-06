<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Admin\Auth;
use WeewxPhp\Admin\Controller;
use WeewxPhp\Admin\Geocoding;
use WeewxPhp\Admin\Page;
use WeewxPhp\Admin\Problem;
use WeewxPhp\Admin\ReadModel;
use WeewxPhp\Admin\Service;
use WeewxPhp\Admin\Translator;
use WeewxPhp\Config\Config;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Http\HttpResponse;

final class ArchiveSettingsTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('archive-settings');
        mkdir($this->dir . '/data');
        $this->path = $this->dir . '/weather.conf';
        file_put_contents($this->path, "data_dir = data\nbackup_enabled = false\n[Ingest]\n enabled = true\n public_url = http://weather.example:8080/weather\n");
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @param array<string, mixed> $input */
    private function command(string $action, array $input): void
    {
        (new Service($this->path, 1788681600))->execute($action, $input + ['revision' => (new ReadModel($this->path))->revision]);
    }

    public function testSeparateAltitudeFieldsAndMinutesSaveWithoutChangingTheirMeaning(): void
    {
        $this->command('archive.create', ['archive' => 'garden', 'name' => 'Garden', 'timezone' => 'Europe/Berlin', 'archive_interval_minutes' => '5']);
        $this->command('archive.save', ['archive' => 'garden', 'name' => 'Garden', 'location' => 'Kirchdorf', 'latitude' => '48,459', 'longitude' => '11,654', 'altitude_value' => '1443,57', 'altitude_unit' => 'foot', 'archive_interval_minutes' => '10']);
        $read = new ReadModel($this->path);
        $archive = $read->config->archive('garden');
        self::assertNotNull($archive);
        self::assertSame(600, $archive->interval($read->config->settings));
        self::assertSame(48.459, $archive->latitude);
        self::assertSame(11.654, $archive->longitude);
        self::assertNotNull($archive->altitude);
        self::assertSame('foot', $archive->altitude->unit);
        self::assertSame(1443.57, $archive->altitude->value);
        $html = (new Page($read, new Translator('de'), 'test'))->render('archives', ['archive' => 'garden']);
        self::assertStringContainsString('name="altitude_value"', $html);
        self::assertStringContainsString('name="altitude_unit"', $html);
        self::assertStringNotContainsString('name="altitude"', $html);
        $this->command('archive.save', ['archive' => 'garden', 'name' => 'Garden', 'altitude_value' => '', 'altitude_unit' => 'meter']);
        self::assertNull(Config::load($this->path)->archive('garden')?->altitude);
    }

    public function testInvalidHeightUnitAndCoordinatesDoNotAlterConfiguration(): void
    {
        $this->command('archive.create', ['archive' => 'garden', 'name' => 'Garden', 'timezone' => 'UTC']);
        $before = file_get_contents($this->path);
        foreach ([['altitude_value' => '10', 'altitude_unit' => 'banana'], ['latitude' => 'north'], ['archive_interval_minutes' => '0']] as $invalid) {
            try {
                $this->command('archive.save', ['archive' => 'garden', 'name' => 'Garden'] + $invalid);
                self::fail('Invalid input accepted');
            } catch (Problem) {
                self::assertSame($before, file_get_contents($this->path));
            }
        }
    }

    public function testConnectingStationPreservesArchiveSettingsAndSettingsSavePreservesConnection(): void
    {
        file_put_contents($this->path, "\n[Stations]\n [[roof]]\n name = Roof\n", FILE_APPEND);
        $this->command('archive.create', ['archive' => 'garden', 'name' => 'Garden', 'timezone' => 'Europe/Berlin']);
        $this->command('archive.save', ['archive' => 'garden', 'name' => 'Garden', 'enabled' => 'true', 'latitude' => '48.4', 'altitude_value' => '440']);
        $before = Config::load($this->path)->archive('garden');
        $this->command('archive.stations', ['archive' => 'garden', 'senders' => ['roof']]);
        $archive = Config::load($this->path)->archive('garden');
        self::assertNotNull($archive);
        self::assertSame(['roof'], $archive->senders);
        self::assertSame($before?->latitude, $archive->latitude);
        self::assertSame($before?->altitude?->value, $archive->altitude?->value);
        self::assertTrue($archive->enabled);
        $this->command('archive.save', ['archive' => 'garden', 'name' => 'Renamed', 'enabled' => 'true', 'preserve_senders' => '1']);
        self::assertSame(['roof'], Config::load($this->path)->archive('garden')?->senders);
        $beforeText = file_get_contents($this->path);
        try {
            $this->command('archive.stations', ['archive' => 'garden', 'senders' => ['unknown']]);
            self::fail('Unapproved station accepted');
        } catch (Problem) {
            self::assertSame($beforeText, file_get_contents($this->path));
        }
    }

    public function testStationConnectionRequiresCompleteAuthenticatedFormAndRedirectsToFields(): void
    {
        file_put_contents($this->path, "\n[Stations]\n [[roof]]\n name = Roof\n", FILE_APPEND);
        $this->command('archive.create', ['archive' => 'garden', 'name' => 'Garden', 'timezone' => 'UTC']);
        $read = new ReadModel($this->path);
        $now = 1788681600;
        $auth = new Auth($read->config->settings);
        $auth->setPassword('archive-settings-test', $now);
        $session = $auth->session(null, $now);
        $session = $auth->login($session['token'], $session['csrf'], 'archive-settings-test', '127.0.0.1', $now);
        $controller = new Controller($this->path, $now);
        $post = ['action' => 'archive.stations', 'archive' => 'garden', 'senders' => ['roof'], 'revision' => $read->revision, 'csrf' => $session['csrf']];
        self::assertSame(403, $controller->handle('POST', ['page' => 'archives'], $post, null, '127.0.0.1', true)->status);
        self::assertSame(422, $controller->handle('POST', ['page' => 'archives'], $post, $session['token'], '127.0.0.1', true)->status);
        self::assertSame([], Config::load($this->path)->archive('garden')?->senders);
        $response = $controller->handle('POST', ['page' => 'archives'], $post + ['complete' => '1'], $session['token'], '127.0.0.1', true);
        self::assertSame(303, $response->status);
        self::assertSame('?page=fields&saved=1&lang=en&archive=garden', $response->location);
        self::assertSame(['roof'], Config::load($this->path)->archive('garden')?->senders);
        $auth->close();
    }

    public function testConnectionSeparatesPortHostAndPathAndUsesConfiguredCustomPort(): void
    {
        $html = (new Page(new ReadModel($this->path), new Translator('de'), 'csrf'))->connection(['ecowitt' => 'abcdefghijkl', 'wunderground' => 'example-password']);
        self::assertStringContainsString('<dt>Port</dt><dd><code>8080</code>', $html);
        self::assertStringContainsString('<dt>Server</dt><dd><code>weather.example</code>', $html);
        self::assertStringContainsString('/weather/abcdefghijkl/ecowitt/', $html);
        self::assertStringContainsString('<dt>Verbindung</dt><dd><code>HTTP</code>', $html);
    }

    public function testPlaceSearchIsBoundedCachedAndRequiresAuthenticationAndCsrf(): void
    {
        $http = new class implements HttpClient {
            public int $calls = 0;
            public function send(HttpRequest $request): HttpResponse
            {
                ++$this->calls;
                TestCase::assertSame('geocoding-api.open-meteo.com', parse_url($request->url, PHP_URL_HOST));
                TestCase::assertSame(5, $request->timeout);
                TestCase::assertSame(131072, $request->maxResponseBytes);
                return new HttpResponse(200, '{"results":[{"name":"Kirchdorf","admin1":"Bayern","country":"Deutschland","latitude":48.46,"longitude":11.65,"elevation":440,"timezone":"Europe/Berlin"},{"name":"Invalid","latitude":900,"longitude":0}]}');
            }
        };
        $now = 1788681600;
        $read = new ReadModel($this->path);
        $controller = new Controller($this->path, $now, http: $http);
        $post = ['action' => 'location.search', 'query' => 'Kirchdorf'];
        self::assertSame(403, $controller->handle('POST', ['page' => 'archives'], $post, null, '127.0.0.1', true)->status);
        self::assertSame(0, $http->calls);
        $auth = new Auth($read->config->settings);
        $auth->setPassword('archive-settings-test', $now);
        $session = $auth->session(null, $now);
        $session = $auth->login($session['token'], $session['csrf'], 'archive-settings-test', '127.0.0.1', $now);
        self::assertSame(403, $controller->handle('POST', ['page' => 'archives'], $post + ['csrf' => 'bad'], $session['token'], '127.0.0.1', true)->status);
        self::assertSame(0, $http->calls);
        $response = $controller->handle('POST', ['page' => 'archives', 'lang' => 'de'], $post + ['csrf' => $session['csrf']], $session['token'], '127.0.0.1', true);
        self::assertSame(200, $response->status);
        $cached = (new Geocoding($read->config->settings, $http, $now))->search('Kirchdorf', 'de');
        self::assertIsArray($cached['results']);
        self::assertCount(1, $cached['results']);
        self::assertSame(1, $http->calls);
        $auth->close();
    }
}
