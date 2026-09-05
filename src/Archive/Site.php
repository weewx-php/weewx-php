<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use DateTimeZone;
use WeewxPhp\Config\Altitude;
use WeewxPhp\Config\ArchiveConfig;

/**
 * Where a series is measured: what the formulas need to know about the
 * place, and nothing about its database or senders. A second place
 * derived with the first one's numbers is wrong in a way nothing
 * downstream can see, which is why this travels with the archive.
 */
final class Site
{
    /**
     * @param float|null $latitude Decimal degrees, negative south; null when nobody said.
     * @param float|null $longitude Decimal degrees, negative west.
     * @param DateTimeZone $zone The zone a day is counted in.
     */
    public function __construct(
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?Altitude $altitude,
        public readonly DateTimeZone $zone,
    ) {}

    public static function of(ArchiveConfig $config): self
    {
        return new self($config->latitude, $config->longitude, $config->altitude, $config->timezone);
    }
}
