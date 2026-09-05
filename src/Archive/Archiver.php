<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Settings;
use WeewxPhp\Db\DbError;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Log\Logger;
use WeewxPhp\State\ArchiveState;
use WeewxPhp\State\StateDb;
use WeewxPhp\Weewx\Accum;
use WeewxPhp\Weewx\AccumError;
use WeewxPhp\Weewx\Intervals;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\SchemaError;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Turns the journal's packets into archive records and daily summaries:
 * WeeWX's `StdArchive`, as weewx-evo rebuilt it.
 *
 * The difference is not what it computes but where from. WeeWX
 * accumulates in memory as packets arrive and writes once when the
 * interval closes, so a restart mid-interval loses the interval and a
 * late packet has nowhere to go. Here the packets are already in the
 * live table, and a record is a function of a time span: {@see build()}
 * can be called now, after a restart, or a week later, and gives the same
 * record each time. That is what makes a tick on a web host enough.
 *
 * What is not different is the arithmetic. Records come out of the same
 * accumulator WeeWX uses, in the same order, with the same weighting,
 * after the same corrections, checks and derivations.
 */
final class Archiver
{
    /**
     * Seconds of packets read before a span to seed what the derivations
     * carry between packets: the rain of the last quarter hour, and the
     * counters, whose last reading before that is read as well.
     */
    public const RUN_UP = Derived::RAIN_PERIOD;

    /** @var array<string, true> */
    private array $saidDialect = [];

    /** @var array<string, true> */
    private array $saidHomeless = [];

    /** @var list<array{boundary: int, config: ArchiveConfig, settings: Settings}> */
    private array $timeline = [];

    public function __construct(
        private readonly ArchiveConfig $config,
        private readonly Settings $settings,
        private readonly LiveDb $live,
        private readonly ArchiveDb $archive,
        private readonly Mapping $mapping,
        private readonly Policy $policy,
        private readonly Logger $log,
        private readonly ?Changes $changes = null,
    ) {}

    /**
     * An archiver over an archive as configured: the database opened, or
     * created and noted as ours; the configured columns added; the primary
     * sender found; the mapping checked against the database as found.
     *
     * @throws DbError If the database cannot be opened or created.
     * @throws SchemaError If the file is not a usable WeeWX archive.
     * @throws MappingError If the mapping may not write this database.
     */
    public static function open(ArchiveConfig $config, Settings $settings, LiveDb $live, StateDb $state, Logger $log, int $now, ?Changes $changes = null): self
    {
        $policy = $config->policy();
        $directory = dirname($config->database);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DbError(sprintf('Cannot create %s for the archive %s', $directory, $config->id));
        }
        $archive = ArchiveDb::open($config->database, $settings->journalMode, $policy, $config->timezone, create: true);
        if ($archive->created()) {
            $state->markCreated($config->id, $now);
            $log->info(sprintf('%s: created %s', $config->id, $config->database));
        }
        foreach ($config->columns as $name => $type) {
            if ($archive->addColumn($name, $type)) {
                $log->info(sprintf('%s: added column %s %s', $config->id, $name, $type->value));
            }
        }
        $mapping = new Mapping($config, Mapping::resolvePrimary($config, $live->senders()));
        $mapping->verify($archive->schema(), self::isForeign($state->archive($config->id), $archive, $settings));
        $result = new self($config, $settings, $live, $archive, $mapping, $policy, $log, $changes);
        $revisions = Revisions::open($settings);
        try {
            $result->timeline = $revisions->timeline($config->id);
        } finally {
            $revisions->close();
        }
        return $result;
    }

    /**
     * Whether the database is one this application did not make. The
     * state says which files it created; a file that holds records from
     * before the journal could have delivered any was swapped in since.
     */
    private static function isForeign(ArchiveState $known, ArchiveDb $archive, Settings $settings): bool
    {
        if (!$known->createdByApp || $known->dbCreatedAt === null) {
            return true;
        }
        $first = $archive->firstTimestamp();
        return $first !== null && $first < $known->dbCreatedAt - $settings->liveRetention - 3600;
    }

    public function id(): string
    {
        return $this->config->id;
    }

    public function config(): ArchiveConfig
    {
        return $this->config;
    }

    public function archive(): ArchiveDb
    {
        return $this->archive;
    }

    public function mapping(): Mapping
    {
        return $this->mapping;
    }

    public function close(): void
    {
        $this->archive->close();
    }

    // -- building ---------------------------------------------------------

    /**
     * The record for the interval ending at `stop`, or null when no
     * selected sender delivered anything in it. A gap in the data is a gap
     * in the archive; a record invented for it would average badly for
     * years.
     *
     * Deterministic: everything the derivations carry between packets is
     * seeded from the packets before the span, so a rebuild gives what the
     * first build gave.
     */
    public function build(int $stop, ?int $seconds = null): ?Built
    {
        if ($this->timeline !== []) {
            $selected = $this->timeline[0]['config'];
            $settings = $this->timeline[0]['settings'];
            foreach ($this->timeline as $revision) {
                if ($revision['boundary'] < $stop) {
                    $selected = $revision['config'];
                    $settings = $revision['settings'];
                }
            }
            if (!$selected->enabled) {
                return null;
            }
            $worker = new self(
                $selected,
                $settings,
                $this->live,
                $this->archive,
                new Mapping($selected, Mapping::resolvePrimary($selected, $this->live->senders())),
                $selected->policy(),
                $this->log,
                $this->changes,
            );
            return $worker->build($stop, $seconds);
        }
        $seconds ??= $this->config->interval($this->settings);
        $start = $stop - $seconds;
        $senders = $this->config->senders;
        $units = $this->archive->unitSystem() ?? $this->config->unitSystem;
        $derived = Derived::fromConfig($this->config, $this->archive);

        // The run-up, corrected and checked like the span itself, so the
        // counters and the rain rate start where WeeWX's would. Its refusals
        // belong to intervals built already and are not counted again.
        $runUp = Quality::fromConfig($this->config);
        $last = $this->live->lastBefore($start - self::RUN_UP, PacketKind::Loop, $senders);
        if ($last !== null) {
            $this->seed($derived, $runUp, $last, $units);
        }
        foreach ($this->live->packets($start - self::RUN_UP, $start, PacketKind::Loop, $senders) as $packet) {
            $this->seed($derived, $runUp, $packet, $units);
        }

        $quality = Quality::fromConfig($this->config);
        $accum = new Accum($start, $stop, null, $this->policy);
        $packets = 0;
        $hardware = null;
        foreach ($this->live->packets($start, $stop, null, $senders) as $packet) {
            $record = $this->sound($quality, $packet, $units);
            if ($record === null) {
                continue;
            }
            if ($packet->kind === PacketKind::Archive) {
                // The console kept its own record. Several may deliver one;
                // they are laid over each other in arrival order.
                $hardware = array_replace($hardware ?? [], $record);
                continue;
            }
            // Derived per packet, before accumulating: the dew point of an
            // average hour is not a thing that happened.
            $accum->addRecord($derived->applyPacket($record, $packet->sender), $this->settings->loopHilo, 1);
            ++$packets;
        }
        if ($hardware === null && $packets === 0) {
            return null;
        }

        if ($hardware !== null) {
            // The console's record wins: computed from readings we never saw.
            // WeeWX derives on it first and fills in from the LOOP packets
            // afterwards, without overwriting anything it carries.
            $record = $hardware;
            $record['interval'] ??= $seconds / 60;
            $record = $derived->applyRecord($record);
            if ($packets > 0) {
                $record = $accum->augment($record);
            }
        } else {
            $record = $accum->record();
            $record['interval'] = $seconds / 60;
            $record = $derived->applyRecord($record);
        }

        if ($quality->dropped() !== []) {
            $this->log->info(sprintf('%s: interval ending %d refused %s', $this->config->id, $stop, $quality->summary()));
        }
        return new Built($stop, $seconds, $record, $accum, $packets, $hardware !== null, $quality->dropped());
    }

    /**
     * The newest LOOP packet of the primary sender, or of any selected
     * sender while there is no primary, through the same steps as an
     * interval's packets: placed, converted, corrected, checked and
     * derived, with the run-up before it seeding the derivations. For a
     * broker that wants what it is like now rather than the average of
     * the last five minutes.
     *
     * @return array<string, mixed>|null Null when nothing has arrived, or nothing of it belongs here.
     */
    public function latest(): ?array
    {
        $primary = $this->mapping->primary();
        $senders = $primary === null ? $this->config->senders : [$primary];
        $newest = $this->live->lastBefore(PHP_INT_MAX, PacketKind::Loop, $senders);
        if ($newest === null) {
            return null;
        }
        $units = $this->archive->unitSystem() ?? $this->config->unitSystem;
        $derived = Derived::fromConfig($this->config, $this->archive);
        $runUp = Quality::fromConfig($this->config);
        $before = $this->live->lastBefore($newest->dateTime - self::RUN_UP, PacketKind::Loop, $senders);
        if ($before !== null) {
            $this->seed($derived, $runUp, $before, $units);
        }
        $packets = iterator_to_array($this->live->packets($newest->dateTime - self::RUN_UP, $newest->dateTime, PacketKind::Loop, $senders), false);
        // The last one read is the newest itself: the same order and the same senders.
        $last = array_pop($packets);
        foreach ($packets as $packet) {
            $this->seed($derived, $runUp, $packet, $units);
        }
        if ($last === null) {
            return null;
        }
        $record = $this->sound(Quality::fromConfig($this->config), $last, $units);
        return $record === null ? null : $derived->applyPacket($record, $last->sender);
    }

    private function seed(Derived $derived, Quality $quality, Packet $packet, UnitSystem $units): void
    {
        $record = $this->sound($quality, $packet, $units);
        if ($record !== null) {
            $derived->seed($record, $packet->sender);
        }
    }

    /**
     * One packet placed, converted, corrected and checked: what WeeWX's
     * StdConvert, StdCalibrate and StdQC make of it, in that order. Null
     * when nothing of it belongs here.
     *
     * @return array<string, mixed>|null
     */
    private function sound(Quality $quality, Packet $packet, UnitSystem $units): ?array
    {
        if ($packet->dialect !== null) {
            $this->sayUnplaced($packet->dialect);
            return null;
        }
        $record = $this->mapping->place($packet);
        if ($record === null) {
            return null;
        }
        $record = Units::toSystem($record, $units, $this->config->groups());
        $record = $quality->calibrate($record, $packet->sender);
        return $quality->check($record);
    }

    /**
     * Say once that packets arrive in a vocabulary nothing here translates.
     * Their readings keep the names the console used and the archive has a
     * column for none of them; they wait in the journal for an ingest
     * catalog that can.
     */
    private function sayUnplaced(string $dialect): void
    {
        if (isset($this->saidDialect[$dialect])) {
            return;
        }
        $this->saidDialect[$dialect] = true;
        $this->log->error(sprintf(
            '%s: packets arrive as %s and nothing here translates that; they stay in the journal unread',
            $this->config->id,
            $dialect,
        ));
    }

    // -- writing ----------------------------------------------------------

    /**
     * Write one built interval, then let its LOOP packets sharpen the day.
     * The record goes in first and carries the sums; the accumulator
     * afterwards touches only the highs and lows. Returns false when the
     * record was already there and `replace` is not set.
     */
    public function store(Built $built, bool $replace = false): bool
    {
        $this->changes?->before($built->stop - $this->config->interval($this->settings), $built->stop);
        try {
            $written = $this->archive->transaction(function () use ($built, $replace): bool {
                if (!$this->archive->addRecord($built->record, $replace)) {
                    return false;
                }
                if ($this->settings->loopHilo && $built->packets > 0) {
                    $this->sharpenDay($built);
                }
                return true;
            });
            $this->sayHomeless();
            return $written;
        } finally {
            $this->changes?->after($built->stop - $this->config->interval($this->settings), $built->stop);
        }
    }

    /**
     * Fold an interval's highs and lows into its day: WeeWX's
     * `_updateHiLo`. Only extremes move; the sums reached the day through
     * the record.
     */
    private function sharpenDay(Built $built): void
    {
        $sod = Intervals::startOfArchiveDay($built->stop, $this->config->timezone);
        $day = $this->archive->loadDay($sod, $built->accumulator->unitSystem());
        try {
            $day->mergeHilo($built->accumulator);
        } catch (AccumError $error) {
            // An interval that straddles midnight: WeeWX refuses it too.
            $this->log->warning(sprintf('%s: the interval ending %d does not sharpen its day: %s', $this->config->id, $built->stop, $error->getMessage()));
            return;
        }
        $this->archive->storeDay($sod, $day, null);
    }

    /**
     * Say once, per name, that a reading has nowhere to live. Dropping is
     * normal, a console can send four times the columns; dropping silently
     * is how a sensor goes missing from a series for a year.
     */
    private function sayHomeless(): void
    {
        foreach ($this->archive->homeless() as $name => $count) {
            if (isset($this->saidHomeless[$name])) {
                continue;
            }
            $this->saidHomeless[$name] = true;
            $this->log->info(sprintf(
                '%s: no column for %s, so it is not archived; add it under [[[columns]]] or place it with [[[fields]]]',
                $this->config->id,
                $name,
            ));
        }
    }

    // -- the loop ---------------------------------------------------------

    /**
     * Build and store every interval marked for this archive that has
     * closed, oldest first, as far as the budget reaches. Returns how many
     * records were written.
     *
     * Safe to call at any moment and safe to interrupt: an interval is
     * cleared from `pending` once its record is in the archive, so a crash
     * costs a repeated computation and nothing else.
     *
     * @param bool $replace Whether an interval that is archived already is built again for a
     *     late packet. Otherwise the mark is cleared and the record left alone.
     */
    public function processDue(int $now, Budget $budget, bool $replace = false): int
    {
        $done = 0;
        foreach ($this->live->due($now, $this->settings->archiveDelay, $this->config->id) as ['stop' => $stop, 'seconds' => $seconds]) {
            if (!$budget->allows()) {
                // The mark stays; the next tick takes the interval up.
                break;
            }
            $grid = $this->config->interval($this->settings);
            if ($seconds !== $grid || $stop % $grid !== 0) {
                // Old external producers may still mark their global grid.
                $this->live->clearPending($stop, $this->config->id);
                $this->live->markPending($stop, $grid, [$this->config->id]);
                continue;
            }
            $existing = $this->archive->exists($stop);
            if ($existing && !$replace) {
                $this->log->debug(sprintf('%s: interval ending %d is archived already; a late packet is left alone', $this->config->id, $stop));
                $this->live->clearPending($stop, $this->config->id);
                continue;
            }
            $built = $this->build($stop, $seconds);
            if ($built !== null && $this->store($built, $existing)) {
                ++$done;
                $budget->spent();
            }
            $this->live->clearPending($stop, $this->config->id);
        }
        return $done;
    }

    /** Native replay repairs subsequent calculations and whole-day extrema, in tick-sized steps. */
    public function processReplay(int $now, Budget $budget): int
    {
        $replay = $this->live->replay();
        $done = 0;
        while ($budget->allows() && ($job = $replay->job($this->config->id)) !== null) {
            $seconds = $this->config->interval($this->settings);
            if ($job['seconds'] !== $seconds) {
                $replay->mark($this->config, $job['cursor'], $now, $seconds);
                continue;
            }
            $stop = $this->nextReplayStop($job['cursor'], $seconds);
            if ($stop + $this->settings->archiveDelay > $now) {
                break;
            }
            $sod = Intervals::startOfArchiveDay($stop, $this->config->timezone);
            $eod = Intervals::endOfDay($sod, $this->config->timezone);
            $units = $this->archive->unitSystem() ?? $this->config->unitSystem;
            $day = ReplayDay::restore($job['stats'], $sod, $eod, $units, $this->policy);
            $built = $stop % $seconds === 0 ? $this->build($stop, $seconds) : null;
            if ($built !== null) {
                $this->store($built, true);
                ++$done;
            }
            // Existing records without retained LOOP input are preserved.
            // Every record in the day contributes exactly once to this checkpoint.
            $record = $this->archive->record($stop);
            if ($record !== null) {
                $interval = $record['interval'] ?? null;
                if ((is_int($interval) || is_float($interval)) && $interval > 0) {
                    $day->addRecord($record, true, 60 * $interval);
                    if ($this->settings->loopHilo && $built !== null && $built->packets > 0
                        && $built->accumulator->start() >= $sod && $built->accumulator->stop() <= $eod) {
                        $day->mergeHilo($built->accumulator);
                    }
                }
            }
            $dayEnded = Intervals::startOfArchiveDay($this->nextReplayStop($stop, $seconds), $this->config->timezone) !== $sod;
            $finished = $stop >= $job['stop'];
            if ($dayEnded || $finished) {
                $this->changes?->before($sod, $eod);
                try {
                    $this->archive->replaceDay($sod, $day);
                } finally {
                    $this->changes?->after($sod, $eod);
                }
            }
            $budget->spent();
            // The archive commit precedes the cursor: a crash can only repeat work.
            // A concurrent delivery changes generation, so it cannot be cleared here.
            if ($replay->advance(
                $this->config->id,
                $job['generation'],
                $job['cursor'],
                $stop,
                $dayEnded ? null : ReplayDay::snapshot($day),
            )) {
                $this->live->clearPending($stop, $this->config->id);
            }
        }
        return $done;
    }

    /** Include existing records on older/foreign grids when rebuilding a day's summaries. */
    private function nextReplayStop(int $cursor, int $seconds): int
    {
        $grid = Intervals::stop($cursor + 1, $seconds);
        $next = $this->archive->recordsAfter($cursor, 1)[0]['dateTime'] ?? null;
        return is_int($next) ? min($grid, $next) : $grid;
    }

    /**
     * Build every interval the journal covers that is not archived, from
     * the packets rather than the marks, so that intervals whose marks were
     * lost are filled too. Used after a start, after downtime, and by the
     * command line.
     *
     * What is archived already is not built again, one lookup per
     * interval; a gap in the journal is jumped rather than walked.
     *
     * @param int|null $since Where to start, or null for the journal's first packet.
     * @param int|null $until Where to stop, or null for its last.
     */
    public function catchUp(?int $since, ?int $until, Budget $budget, bool $replace = false): Progress
    {
        [$first, $last] = $this->live->span();
        if ($first === null || $last === null) {
            return new Progress(0, true, null);
        }
        $since = $since === null ? $first : max($since, $first);
        $until = $until === null ? $last : min($until, $last);
        $seconds = $this->config->interval($this->settings);
        $senders = $this->config->senders;

        $done = 0;
        $stop = Intervals::stop($since, $seconds);
        $end = Intervals::stop($until, $seconds);
        while ($stop <= $end) {
            if (!$budget->allows()) {
                return new Progress($done, false, $stop);
            }
            $existing = $this->archive->exists($stop);
            if ($existing && !$replace) {
                $this->live->clearPending($stop, $this->config->id);
                $stop += $seconds;
                continue;
            }
            $built = $this->build($stop, $seconds);
            if ($built === null) {
                $this->live->clearPending($stop, $this->config->id);
                $next = $this->live->nextPacketAfter($stop, $senders);
                if ($next === null) {
                    break;
                }
                $stop = Intervals::stop($next, $seconds);
                continue;
            }
            if ($this->store($built, $existing)) {
                ++$done;
                $budget->spent();
            }
            $this->live->clearPending($stop, $this->config->id);
            $stop += $seconds;
        }
        return new Progress($done, true, null);
    }

    /**
     * Work out every interval in (start, stop] again and replace what is
     * there, day by day: the day's records first, then its summaries from
     * the archive table, then the LOOP extremes on top. Extremes cannot be
     * subtracted, a maximum does not remember the runner-up, so a day is
     * corrected by building it from nothing. Returns how many records were
     * written.
     */
    public function rebuild(int $start, int $stop): int
    {
        $this->changes?->before($start, $stop);
        try {
            $seconds = $this->config->interval($this->settings);
            $zone = $this->config->timezone;
            $ts = Intervals::stop($start + 1, $seconds);
            $last = Intervals::stop($stop, $seconds);
            $done = 0;
            while ($ts <= $last) {
                $sod = Intervals::startOfArchiveDay($ts, $zone);
                $done += $this->archive->transaction(function () use (&$ts, $last, $seconds, $sod, $zone): int {
                    $builts = [];
                    for (; $ts <= $last && Intervals::startOfArchiveDay($ts, $zone) === $sod; $ts += $seconds) {
                        $built = $this->build($ts, $seconds);
                        if ($built === null) {
                            continue;
                        }
                        $this->archive->addRecord($built->record, replace: true, updateDaily: false);
                        $builts[] = $built;
                    }
                    $this->archive->rebuildDay($sod);
                    if ($this->settings->loopHilo) {
                        foreach ($builts as $built) {
                            if ($built->packets > 0) {
                                $this->sharpenDay($built);
                            }
                        }
                    }
                    return count($builts);
                });
            }
            $this->sayHomeless();
            return $done;
        } finally {
            $this->changes?->after($start, $stop);
        }
    }
}
