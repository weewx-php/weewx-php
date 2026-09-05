<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use Throwable;
use WeewxPhp\Archive\Changes;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Log\Logger;
use WeewxPhp\Weewx\Intervals;

final class ArchiveChanges implements Changes
{
    public function __construct(private readonly Cache $cache, private readonly ArchiveConfig $archive, private readonly Logger $log) {}

    public function before(int $start, int $end): void
    {
        try {
            $this->cache->beginMutation($this->archive, $this->span($start, $end));
        } catch (Throwable $error) {
            // Failed cache notifications must not prevent station data being archived.
            // The next fingerprint observation conservatively invalidates foreign changes.
            $this->log->warning('analytics invalidation: ' . $error->getMessage());
        }
    }

    public function after(int $start, int $end): void
    {
        try {
            $this->cache->endMutation($this->archive, $this->span($start, $end));
        } catch (Throwable $error) {
            $this->log->warning('analytics invalidation: ' . $error->getMessage());
        }
    }

    private function span(int $start, int $end): Span
    {
        $zone = $this->archive->timezone;
        return new Span(Intervals::startOfArchiveDay($start + 1, $zone), Intervals::endOfDay(Intervals::startOfArchiveDay($end, $zone), $zone));
    }
}
