<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\How;
use WeewxPhp\Config\Config;
use WeewxPhp\Weewx\UnitSystem;

/** The file people copy has to load clean: every option it shows, no warning. */
final class ExampleConfigTest extends TestCase
{
    public function testTheExampleLoadsWithoutAWarning(): void
    {
        $config = Config::load(dirname(__DIR__, 3) . '/weewx-php.conf.example');

        self::assertSame([], $config->warnings);
        self::assertSame(300, $config->settings->archiveInterval);
        self::assertSame('change-me', $config->settings->tickToken);
        self::assertSame(['ecowitt_kirchdorf', 'dwd_freising'], array_keys($config->stations));
        self::assertSame(3600, $config->stations['dwd_freising']->expectedInterval);

        $archive = $config->archives['kirchdorf'];
        self::assertSame('ecowitt_kirchdorf', $archive->primary);
        self::assertSame(UnitSystem::METRICWX, $archive->unitSystem);
        self::assertFalse($archive->takesIndoor('dwd_freising'));
        self::assertSame(['inTemp' => '-'], $archive->fields['ecowitt_kirchdorf']);
        self::assertSame(How::PreferHardware, $archive->calculate['ET']);
        self::assertSame(UnitSystem::METRIC, $archive->qcUnitSystem);
        self::assertSame('degree_C', $archive->qc['outTemp']->unit);
        self::assertSame(-0.4, $archive->calibrate['ecowitt_kirchdorf']['outTemp']->offset);
    }
}
