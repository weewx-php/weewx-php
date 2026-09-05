<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\JournalMode;
use WeewxPhp\DemoTheme\View;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\UnitSystem;

require_once dirname(__DIR__, 3) . '/themes/demo/View.php';

final class DemoThemeTest extends TestCase
{
    public function testUsArchiveConvertsUnitsAndPreservesMissingReadingsAndChartGaps(): void
    {
        $dir = TempDir::create('demo-theme');
        $archive = Archives::config(database: $dir . '/weather.sdb', unitSystem: UnitSystem::US);
        $config = new Config(Archives::settings($dir), [], [$archive->id => $archive], []);
        $db = ArchiveDb::open($archive->database, JournalMode::Wal, new Policy(), $archive->timezone, create: true);
        $start = Span::timestamp('2026-08-26 12:00:00', $archive->timezone);
        foreach ([68.0, null, 86.0] as $i => $temperature) {
            $db->addRecord(['dateTime' => $start + $i * 900, 'usUnits' => 1, 'interval' => 15,
                'outTemp' => $temperature, 'rain' => 0.0, 'windSpeed' => 10.0, 'barometer' => 29.92126]);
        }
        $wx = new Weather($config, clock: new FixedClock($start + 1800), budget: new ReadBudget(maxStatements: 2048, milliseconds: 5000));
        try {
            $view = new View($wx, '../../invalid');
            self::assertSame('24h', $view->range);
            self::assertSame('30,0', $view->number('temperature'));
            self::assertSame('16,1', $view->number('wind'));
            self::assertSame('1.013', $view->number('pressure'));
            self::assertSame('0,0', $view->number('rainDay'));
            self::assertSame('—', $view->number('humidity'));
            $chart = $view->chart();
            self::assertSame([20.0, null, 30.0], array_column(array_slice($chart['points'], -3), 'value'));
            self::assertCount(2, $chart['paths']);
        } finally {
            $wx->close();
            $db->close();
            TempDir::remove($dir);
        }
    }

    public function testStationTextIsSafeForHtmlAndAttributes(): void
    {
        self::assertSame('&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &#039;Ort&#039;', View::escape('<img src=x onerror="alert(1)"> & \'Ort\''));
    }

    public function testMissingConfigurationRendersWithoutInternalPaths(): void
    {
        $view = null;
        ob_start();
        try {
            require dirname(__DIR__, 3) . '/themes/demo/template.php';
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('Keine Wetterdaten', $html);
        self::assertStringNotContainsString('weewx-php.conf', $html);
        self::assertStringNotContainsString(dirname(__DIR__), $html);
    }
}
