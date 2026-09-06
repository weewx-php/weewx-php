<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Extension;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Extension\Registry;
use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;

final class ExtensionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('extensions');
    }
    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function config(string $extra): Config
    {
        $path = $this->dir . '/station.conf';
        file_put_contents($path, "data_dir = {$this->dir}/data\nbackup_enabled = false\n[Archives]\n [[station]]\n$extra");
        return Config::load($path);
    }

    public function testConfigDoesNotExecuteCodeAndDisabledEntriesAreNotLoaded(): void
    {
        file_put_contents($this->dir . '/plugin.php', '<?php throw new RuntimeException("executed");');
        $config = $this->config("[Extensions]\n [[off]]\n  enabled = false\n  entry = missing.php\n [[broken]]\n  enabled = true\n  entry = plugin.php\n");
        self::assertSame(['broken'], array_keys($config->extensions));
        $registry = new Registry($config);
        self::assertSame([], $registry->tags());
        $log = new MemoryLogger();
        self::assertSame(['broken' => ['status' => 'error']], $registry->run(Budget::unlimited(new FixedClock(1000)), 1000, new FakeHttpClient(), $log));
        self::assertStringContainsString('executed', implode("\n", $log->messages()));
    }

    public function testNamespaceCollisionsRollbackOnePackageAndKeepOthersUsable(): void
    {
        file_put_contents($this->dir . '/good.php', <<<'PHP'
<?php
return static function (\WeewxPhp\Extension\Registration $r): void {
    $r->tag('temperature', static fn($c, $o) => new \WeewxPhp\Frontend\Value(20.0, 'degree_C', 'group_temperature', observation: 'outTemp'));
};
PHP);
        file_put_contents($this->dir . '/bad.php', <<<'PHP'
<?php
return static function (\WeewxPhp\Extension\Registration $r): void {
    $r->tag('temperature', static fn($c, $o) => new \WeewxPhp\Frontend\Value(99));
    $r->tag('temperature', static fn($c, $o) => new \WeewxPhp\Frontend\Value(99));
};
PHP);
        $config = $this->config("[Extensions]\n [[good]]\n  enabled = true\n  entry = good.php\n [[bad]]\n  enabled = true\n  entry = bad.php\n");
        $wx = new Weather($config, clock: new FixedClock(1000));
        self::assertTrue($wx->hasTag('good.temperature'));
        self::assertFalse($wx->hasTag('bad.temperature'));
        $value = $wx->output(new Output(units: ['group_temperature' => 'degree_F']))->tag('good.temperature');
        self::assertInstanceOf(\WeewxPhp\Frontend\Value::class, $value);
        self::assertSame(68.0, $value->raw);
        self::assertDirectoryDoesNotExist($this->dir . '/data/extensions');
        $wx->close();
        $this->expectException(QueryError::class);
        $wx->tag('bad.temperature');
    }

    public function testRemoteEntryIsRejected(): void
    {
        $this->expectException(ConfigError::class);
        $this->config("[Extensions]\n [[remote]]\n  enabled = true\n  entry = https://example.org/plugin.php\n");
    }

    public function testMissingPackageDoesNotPreventCoreConfigurationLoading(): void
    {
        $config = $this->config("[Extensions]\n [[missing]]\n  enabled = true\n  entry = removed/extension.php\n");
        self::assertNotNull($config->archive('station'));
        self::assertSame([], (new Registry($config))->tags());
    }
}
