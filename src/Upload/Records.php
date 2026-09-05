<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use DateTimeZone;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Weewx\Intervals;

/**
 * The records an upload owes a service, in the shape the service expects.
 *
 * The archive stores `rain`: how much fell in one interval. Every service
 * here wants the last hour and the day so far, and CWOP the last
 * twenty-four hours as well. None is a column, all are a sum over a span,
 * and summing them once here is what keeps six uploads from each having
 * their own quietly different SQL.
 *
 * The spans are WeeWX's, from `RESTThread.get_record`, exactly: the hour
 * and the day exclusive on the left and inclusive on the right, except that
 * the day begins at local midnight inclusive, because Weather Underground
 * counts the midnight record into the day it starts. A total that differs
 * from the one WeeWX posted from the same database would be a
 * transcription error, not an improvement.
 */
final class Records
{
    /**
     * How far back a catch-up reaches, whatever the limit says. A station
     * off for a month should come back and post the current reading, not
     * two weeks of history nobody is waiting for.
     */
    public const HORIZON = 6 * 3600;

    public function __construct(
        private readonly ArchiveDb $archive,
        private readonly DateTimeZone $zone,
    ) {}

    /**
     * Up to `limit` records newer than a moment, oldest first. A moment of
     * 0 means "the newest one", not "all of history": an upload configured
     * today has nothing to catch up on, and posting a station's whole
     * archive to Weather Underground on first run is not a thing to do by
     * accident.
     *
     * @return list<array<string, mixed>>
     */
    public function after(int $through, int $limit): array
    {
        if ($through <= 0) {
            $newest = $this->newest();
            return $newest === null ? [] : [$newest];
        }
        return array_map($this->augment(...), $this->archive->recordsAfter($through, $limit));
    }

    /** @return array<string, mixed>|null */
    public function newest(): ?array
    {
        $record = $this->archive->newest();
        return $record === null ? null : $this->augment($record);
    }

    /**
     * A record with the rain totals the services ask for, where the
     * archive can say. A record that carries a total already keeps it.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function augment(array $record): array
    {
        $timestamp = $record['dateTime'] ?? null;
        if (!is_int($timestamp)) {
            return $record;
        }
        $units = $record['usUnits'] ?? null;
        $units = is_int($units) ? $units : null;
        foreach ([
            'hourRain' => [$timestamp - 3600, false],
            'rain24' => [$timestamp - 86400, false],
            'dayRain' => [Intervals::startOfDay($timestamp, $this->zone), true],
        ] as $name => [$start, $inclusive]) {
            if (array_key_exists($name, $record)) {
                continue;
            }
            $sum = $this->rain($start, $timestamp, $inclusive, $units);
            if ($sum !== null) {
                $record[$name] = $sum;
            }
        }
        return $record;
    }

    /**
     * Rain in a span, or null when the units in it are not all the record's.
     * The units check is not pedantry: a database whose station was swapped
     * from a US console to a metric one has both in it, and a sum across
     * that boundary is neither a downpour nor a drizzle but published as fact.
     */
    private function rain(int $start, int $stop, bool $inclusiveStart, ?int $units): ?float
    {
        $found = $this->archive->rainSum($start, $stop, $inclusiveStart);
        if ($found === null || $found[0] === null) {
            return null;
        }
        if ($units !== null && ($found[1] !== $units || $found[2] !== $units)) {
            return null;
        }
        return $found[0];
    }
}
