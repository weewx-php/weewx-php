<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use DateTimeZone;
use WeewxPhp\Archive\How;
use WeewxPhp\Weewx\ColumnType;
use WeewxPhp\Weewx\Extractor;
use WeewxPhp\Weewx\UnitSystem;

/**
 * One place: its database, its coordinates, and which senders it reads and
 * how. Everything about a series hangs here, so a second place is a second
 * section and nothing else.
 */
final class ArchiveConfig
{
    /**
     * @param Altitude|null $altitude Height above sea level; null when nobody said, and then
     *     nothing that needs it is derived.
     * @param string $database Absolute path of the WeeWX database.
     * @param UnitSystem $unitSystem The unit system a new database is created in. An existing
     *     database keeps its own.
     * @param DateTimeZone $timezone The zone the daily summaries' days are counted in.
     * @param string|null $primary The one sender this series is taken from, or null to take the
     *     sender heard first.
     * @param list<string>|null $senders The senders this place reads, or null for every arrival.
     * @param bool $autoMapping Whether the primary's readings go to their same-named columns
     *     even in a database this application did not create.
     * @param array<string, bool> $indoor Per sender, whether its room readings belong here.
     * @param array<string, ColumnType> $columns Columns to add to the database if missing.
     * @param array<string, array<string, string>> $fields Per sender, raw name to column, or '-'.
     * @param array<string, Extractor> $extractors Observation types aggregated other than by default.
     * @param array<string, How> $calculate Which derived readings to work out, and when.
     * @param UnitSystem|null $qcUnitSystem The system the limits without a unit are written in.
     * @param array<string, QcRule> $qc Limits by observation type.
     * @param array<string, array<string, Calibration>> $calibrate Per sender, corrections by observation type.
     * @param array<string, string> $measurementKinds Custom observation definitions.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $location,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?Altitude $altitude,
        public readonly string $database,
        public readonly UnitSystem $unitSystem,
        public readonly DateTimeZone $timezone,
        public readonly ?string $primary,
        public readonly ?array $senders,
        public readonly bool $autoMapping,
        public readonly array $indoor,
        public readonly array $columns,
        public readonly array $fields,
        public readonly array $extractors,
        public readonly array $calculate,
        public readonly ?UnitSystem $qcUnitSystem,
        public readonly array $qc,
        public readonly array $calibrate,
        public readonly bool $explicitMapping = false,
        public readonly bool $enabled = true,
        public readonly ?int $archiveInterval = null,
        public readonly array $measurementKinds = [],
    ) {}

    /** Whether a sender is one this place reads. */
    public function selects(string $sender): bool
    {
        return $this->senders === null || in_array($sender, $this->senders, true);
    }

    public function interval(Settings $settings): int
    {
        return $this->archiveInterval ?? $settings->archiveInterval;
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return \WeewxPhp\Measurement\Catalog::groups($this->measurementKinds);
    }

    public function policy(): \WeewxPhp\Weewx\Policy
    {
        $kinds = $this->measurementKinds;
        foreach ($this->fields as $fields) {
            foreach ($fields as $source => $target) {
                $kind = \WeewxPhp\Measurement\Catalog::kind($source, $this->measurementKinds);
                // This mapping feeds Derived's counter delta; interval rain is summed.
                if ($target === 'rain' && $kind === 'rain_counter') {
                    continue;
                }
                if ($target !== '-' && $kind !== null) {
                    // A counter mapped to rain has already become a delta before
                    // accumulation; its destination keeps the rainfall sum policy.
                    $kinds[$target] ??= \WeewxPhp\Measurement\Catalog::kind($target, $this->measurementKinds) ?? $kind;
                }
            }
        }
        return new \WeewxPhp\Weewx\Policy($this->extractors, $kinds);
    }

    /** Whether a sender's `inTemp`, `inHumidity` and `inDewpoint` belong in this series. */
    public function takesIndoor(string $sender): bool
    {
        return $this->indoor[$sender] ?? true;
    }
}
