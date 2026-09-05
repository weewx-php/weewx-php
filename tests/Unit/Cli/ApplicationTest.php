<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Cli;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Console;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\UnitSystem;

final class ApplicationTest extends TestCase
{
    private const T0 = 1_787_734_200;

    private string $dir;
    private string $configPath;
    private MemoryLogger $log;
    private FixedClock $clock;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('cli');
        $this->configPath = $this->dir . '/weewx-php.conf';
        file_put_contents($this->configPath, implode("\n", [
            'data_dir = data',
            'timezone = Europe/Berlin',
            'archive_interval = 300',
            'colour = blue',
            '',
            '[Stations]',
            '    [[ecowitt]]',
            '        name = "HP2561AE Pro"',
            '        expected_interval = 16',
            '    [[dwd]]',
            '',
            '[Archives]',
            '    [[kirchdorf]]',
            '        name = "Kirchdorf an der Amper"',
            '        latitude = 48.4596',
            '        longitude = 11.6539',
            '        altitude = 440, meter',
            '        unit_system = METRICWX',
            '        primary = ecowitt',
            '        [[[columns]]]',
            '            extraTemp9 = REAL',
            '        [[[fields]]]',
            '            [[[[dwd]]]]',
            '                outTemp = extraTemp1',
            '',
            '[Uploads]',
            '    [[wu]]',
            '        kind = wunderground',
            '        station = ITEST1',
            '        password = "p"',
            '        trigger = manual',
            '',
        ]));
        $this->log = new MemoryLogger();
        $this->clock = new FixedClock(self::T0 + 3600);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        $this->out = $out;
        $this->err = $err;
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testHelpAndTheUsageErrors(): void
    {
        self::assertSame(0, $this->execute(['help']));
        self::assertStringContainsString('mapping-suggest <archive>', $this->printed());
        self::assertSame(Application::USAGE_ERROR, $this->execute([]));
        self::assertSame(Application::USAGE_ERROR, $this->execute(['frobnicate']));
        self::assertStringContainsString('unknown command frobnicate', $this->errors());
        self::assertSame(Application::USAGE_ERROR, $this->execute(['--config']));
    }

    public function testCheckConfigReportsWarningsAndWhatTheFirstTickWillDo(): void
    {
        self::assertSame(0, $this->execute(['check-config']));
        $output = $this->printed();
        self::assertStringContainsString('2 station(s), 1 archive(s)', $output);
        self::assertStringContainsString('warning: colour: unknown setting, ignored', $output);
        self::assertStringContainsString('does not exist yet', $output);

        file_put_contents($this->configPath, "archive_interval = soon\n");
        self::assertSame(1, $this->execute(['check-config']));
        self::assertStringContainsString('archive_interval', $this->errors());
    }

    public function testTheWholeRoundTrip(): void
    {
        $this->feed();

        self::assertSame(0, $this->execute(['tick']));
        self::assertStringContainsString('"status": "ok"', $this->printed());
        self::assertFileExists($this->dir . '/data/archives/kirchdorf.sdb');

        self::assertSame(0, $this->execute(['status']));
        $status = $this->printed();
        self::assertStringContainsString('kirchdorf  Kirchdorf an der Amper', $status);
        self::assertStringContainsString('2 record(s)', $status);
        self::assertStringContainsString('ecowitt', $status);
        self::assertStringContainsString('never heard', $status);

        self::assertSame(0, $this->execute(['check-config']));
        self::assertStringContainsString('our database, 2 record(s), mapping ok', $this->printed());

        self::assertSame(0, $this->execute(['columns', 'kirchdorf']));
        $columns = $this->printed();
        self::assertStringContainsString('116 column(s)', $columns);
        self::assertStringContainsString('extraTemp9', $columns);
        self::assertStringContainsString('outTemp', $columns);

        self::assertSame(0, $this->execute(['mapping-suggest', 'kirchdorf']));
        $suggested = $this->printed();
        self::assertStringContainsString('[[[[ecowitt]]]]', $suggested);
        self::assertStringContainsString('# outTemp -> outTemp', $suggested);
        self::assertStringContainsString('# unicorns = -', $suggested);

        self::assertSame(0, $this->execute(['verify', 'kirchdorf']));
        self::assertStringContainsString('integrity ok', $this->printed());
        self::assertStringContainsString('0 problem(s)', $this->printed());

        self::assertSame(0, $this->execute(['catchup']));
        self::assertStringContainsString('kirchdorf: 0 record(s) written', $this->printed());

        self::assertSame(0, $this->execute(['rebuild', 'kirchdorf', (string) self::T0, '2026-08-26 12:00']));
        self::assertStringContainsString('kirchdorf: 2 record(s) rebuilt', $this->printed());
        self::assertSame(Application::USAGE_ERROR, $this->execute(['rebuild', 'kirchdorf', 'yesterday-ish', 'now']));

        $target = $this->dir . '/copy.sdb';
        self::assertSame(0, $this->execute(['backup', 'kirchdorf', $target]));
        self::assertFileExists($target);
        self::assertSame(1, $this->execute(['backup', 'kirchdorf', $target]));
        self::assertStringContainsString('exists already', $this->errors());
    }

    public function testTheUploadsFromTheCommandLine(): void
    {
        $this->feed();
        self::assertSame(0, $this->execute(['tick']));

        self::assertSame(0, $this->execute(['upload', 'list']));
        $listed = $this->printed();
        self::assertStringContainsString('wu  Weather Underground, archive kirchdorf, only when asked', $listed);
        self::assertStringContainsString('host weatherstation.wunderground.com, station ITEST1', $listed);
        self::assertStringContainsString('sent up to nothing yet', $listed);

        // The container has no network: the service cannot be reached, and that is what is said.
        self::assertSame(1, $this->execute(['upload', 'check', 'wu']));
        self::assertStringContainsString('wu: refused: ', $this->printed());
        self::assertSame(1, $this->execute(['upload', 'run', 'wu']));
        self::assertStringContainsString('wu: ', $this->printed());
        self::assertSame(Application::USAGE_ERROR, $this->execute(['upload', 'run', 'nobody']));
        self::assertStringContainsString('no upload nobody; there are: wu', $this->errors());
        self::assertSame(Application::USAGE_ERROR, $this->execute(['upload', 'run', 'wu', '--since', 'whenever']));
        self::assertSame(Application::USAGE_ERROR, $this->execute(['upload']));

        self::assertSame(0, $this->execute(['check-config']));
        self::assertStringContainsString('upload wu: Weather Underground for kirchdorf, ok', $this->printed());
    }

    // -- helpers ----------------------------------------------------------

    /** Two intervals' worth of packets into the journal the application will read. */
    private function feed(): void
    {
        mkdir($this->dir . '/data');
        $live = LiveDb::open($this->dir . '/data/live.sdb', JournalMode::Wal);
        foreach ([[self::T0 + 10, 10.0], [self::T0 + 200, 12.0], [self::T0 + 400, 14.0]] as [$when, $temperature]) {
            $packet = new Packet($when, UnitSystem::METRICWX, ['outTemp' => $temperature, 'outHumidity' => 50.0, 'unicorns' => 1], 'ecowitt', 'ecowitt', 'ID');
            $live->add($packet, ['kirchdorf'], 300, now: $when + 1);
        }
        $live->close();
    }

    /** @param list<string> $args */
    private function execute(array $args): int
    {
        ftruncate($this->out, 0);
        rewind($this->out);
        ftruncate($this->err, 0);
        rewind($this->err);
        $application = new Application(new Console($this->out, $this->err), $this->clock, $this->log);
        return $application->run(['--config', $this->configPath, ...$args]);
    }

    private function printed(): string
    {
        rewind($this->out);
        return (string) stream_get_contents($this->out);
    }

    private function errors(): string
    {
        rewind($this->err);
        return (string) stream_get_contents($this->err);
    }
}
