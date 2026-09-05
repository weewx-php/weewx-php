<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

use Throwable;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Log\Logger;
use WeewxPhp\State\StateDb;
use WeewxPhp\State\UploadState;
use WeewxPhp\Upload\Http\BudgetedHttpClient;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Net\BudgetedSocketFactory;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Service\Ambient;
use WeewxPhp\Upload\Service\Cwop;
use WeewxPhp\Upload\Service\Influx;
use WeewxPhp\Upload\Service\Mqtt;
use WeewxPhp\Upload\Service\Weathercloud;
use WeewxPhp\Upload\Service\Windy;

/**
 * The uploads an installation has, built from the configuration and run
 * once per tick.
 *
 * An upload is handed nothing. It reads the archive from wherever it last
 * got to, and the number is in `state.sdb`: a restart costs nothing, and a
 * connection that was down for twenty minutes comes back and sends the
 * twenty minutes rather than the current reading and a hole. That is the
 * same rule the rest of the application follows, the parts talk through
 * the databases and not to each other, and it is what lets a tick on a
 * web host do what WeeWX does with a thread per service.
 *
 * A service that says no for good is switched off, said once in the log,
 * and tried again an hour later: a wrong password retried every five
 * minutes for a year is how an account gets blocked, and a service that
 * answered 401 during an outage should not stay off until somebody notices.
 */
final class Uploads
{
    /** Seconds a permanently refused upload stays off before it is tried again: WeeWX's `retry_login`. */
    public const RETRY_BLOCKED = 3600;

    /** Seconds between two announcements to Home Assistant, whose retained definitions do not change. */
    public const ANNOUNCE_EVERY = 6 * 3600;

    public function __construct(
        private readonly Config $config,
        private readonly StateDb $state,
        private readonly Logger $log,
        private readonly HttpClient $http,
        private readonly SocketFactory $sockets,
    ) {}

    /**
     * One upload as configured, over this runtime's transports or over
     * the ones handed in, which a tick binds to its budget.
     *
     * @throws UploadError When it cannot be built as configured.
     */
    public function make(UploadConfig $config, ?HttpClient $http = null, ?SocketFactory $sockets = null): Upload
    {
        $http ??= $this->http;
        $sockets ??= $this->sockets;
        $archive = $this->config->archive($config->archive)
            ?? throw new UploadError(sprintf('%s names the archive %s, which is not configured', $config->id, $config->archive));
        return match ($config->kind) {
            Kind::Wunderground, Kind::PwsWeather, Kind::Wow => new Ambient($config, $http),
            Kind::Windy => new Windy($config, $http),
            Kind::Weathercloud => new Weathercloud($config, $http),
            Kind::Influx => new Influx($config, $http),
            Kind::Mqtt => new Mqtt($config, $archive->name, $sockets),
            Kind::Cwop => new Cwop(
                $config,
                $config->float('latitude') ?? $archive->latitude
                    ?? throw new UploadError(sprintf('%s needs a latitude: the packet is a position report, and the archive %s has none', $config->id, $archive->id)),
                $config->float('longitude') ?? $archive->longitude
                    ?? throw new UploadError(sprintf('%s needs a longitude: the packet is a position report, and the archive %s has none', $config->id, $archive->id)),
                $sockets,
            ),
        };
    }

    /**
     * Every configured upload that is due, as far as the budget reaches,
     * in the order of who has waited longest.
     *
     * @param array<string, Archiver> $archivers The open archivers, by archive id.
     * @param bool $forced Whether to run regardless of trigger, block and budget: the command line asking.
     * @param list<string>|null $only Which uploads, or null for all of them.
     *
     * @return array<string, array<string, mixed>> Per upload, what happened.
     */
    public function run(Budget $budget, array $archivers, int $now, bool $forced = false, ?array $only = null): array
    {
        // Every request keeps to what the tick has left: an upload with a
        // generous timeout runs late in a tick on a short leash rather than
        // waiting for a tick with room for all of it, which might never come.
        $http = new BudgetedHttpClient($this->http, $budget);
        $sockets = new BudgetedSocketFactory($this->sockets, $budget);
        $outcome = [];
        foreach ($this->ordered() as $id => $config) {
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            $known = $this->state->upload($id);
            if (!$forced && $known->blocked !== null && $now - ($known->blockedAt ?? 0) < self::RETRY_BLOCKED) {
                $outcome[$id] = ['blocked' => $known->blocked];
                continue;
            }
            if (!$forced && (!$budget->allows() || $budget->timeLeft() < 1.0)) {
                // Left for the next tick; whoever waited longest goes first then.
                $outcome[$id] = ['skipped' => 'no time left in this tick'];
                continue;
            }
            $archiver = $archivers[$config->archive] ?? null;
            if ($archiver === null) {
                $outcome[$id] = ['error' => sprintf('the archive %s is not open', $config->archive)];
                continue;
            }
            $outcome[$id] = $this->runOne($id, $config, $known, $archiver, $budget, $now, $forced, $http, $sockets);
        }
        return $outcome;
    }

    /** @return array<string, mixed> */
    private function runOne(
        string $id,
        UploadConfig $config,
        UploadState $known,
        Archiver $archiver,
        Budget $budget,
        int $now,
        bool $forced,
        HttpClient $http,
        SocketFactory $sockets,
    ): array {
        try {
            $upload = $this->make($config, $http, $sockets);
            $records = $this->pending($config, $known, $archiver, $now, $forced);
            if ($records === []) {
                $this->state->noteUploadRun($id, $now, 0, null, 'nothing new');
                return ['sent' => 0];
            }
            $announcing = $upload instanceof Mqtt && $upload->announces()
                && ($known->announcedAt === null || $now - $known->announcedAt >= self::ANNOUNCE_EVERY);
            if ($announcing && $upload instanceof Mqtt) {
                $upload->announce();
            }
            $posted = $upload->post($records);
            $budget->spent();
        } catch (Rejected $error) {
            if ($error->permanent) {
                $this->state->blockUpload($id, $now, $error->getMessage());
                if ($known->blocked === null || $forced) {
                    // Said once, and then not again until the hour is up.
                    $this->log->error(sprintf('upload %s is switched off: %s', $id, $error->getMessage()));
                }
                return ['error' => $error->getMessage(), 'blocked' => true];
            }
            $this->state->noteUploadFailure($id, $now, $error->getMessage());
            $this->log->warning(sprintf('upload %s failed: %s', $id, $error->getMessage()));
            return ['error' => $error->getMessage()];
        } catch (Throwable $error) {
            // One service failing is not the others' problem, and certainly
            // not the archive's: the readings are safe there either way.
            $this->state->noteUploadFailure($id, $now, $error->getMessage());
            $this->log->warning(sprintf('upload %s failed: %s', $id, $error->getMessage()));
            return ['error' => $error->getMessage()];
        }

        $summary = $posted->summary();
        if ($posted->failures === []) {
            $this->state->noteUploadRun($id, $now, $posted->sent, $posted->through, $summary);
            if ($known->blocked !== null) {
                $this->log->info(sprintf('upload %s is working again', $id));
            }
            if ($announcing) {
                $this->state->noteAnnounced($id, $now);
            }
            $this->log->info(sprintf('upload %s: %s', $id, $summary));
        } else {
            if ($posted->through !== null) {
                $this->state->advanceUpload($id, $now, $posted->sent, $posted->through);
            }
            $this->state->noteUploadFailure($id, $now, $summary);
            $this->log->warning(sprintf('upload %s: %s', $id, $summary));
        }
        return ['sent' => $posted->sent, 'skipped' => $posted->skipped, 'failed' => count($posted->failures), 'summary' => $summary];
    }

    /**
     * The records an upload owes, oldest first; empty when it is up to date.
     *
     * @return list<array<string, mixed>>
     */
    private function pending(UploadConfig $config, UploadState $known, Archiver $archiver, int $now, bool $forced): array
    {
        $trigger = $config->trigger;
        if ($trigger === Trigger::Manual && !$forced) {
            return [];
        }
        if ($trigger === Trigger::Live) {
            // Only what is current: a dashboard wants now, and a packet
            // published twice is a dashboard that does not move.
            $latest = $archiver->latest();
            if ($latest === null || (!$forced && (new Readings($latest))->timestamp() <= $known->through)) {
                return [];
            }
            return [$latest];
        }
        $zone = $archiver->config()->timezone;
        if ($trigger === Trigger::Interval && !$forced && !Schedule::due($now, $known->lastRunAt, $config->every, $zone)) {
            return [];
        }
        $records = new Records($archiver->archive(), $zone);
        if ($config->kind->backfill() && $config->catchUp > 0) {
            // Never reach further back than the horizon, whatever the limit
            // says: the limit caps the requests, the horizon caps the age of
            // what gets posted as though it mattered.
            $after = $known->through > 0 ? max($known->through, $now - Records::HORIZON) : 0;
            return $records->after($after, $config->catchUp);
        }
        // No backfill: the newest, and only if it is still current and new.
        $newest = $records->newest();
        if ($newest === null) {
            return [];
        }
        $timestamp = (new Readings($newest))->timestamp();
        if (!$forced && ($timestamp <= $known->through || $now - $timestamp > $config->stale)) {
            return [];
        }
        return [$newest];
    }

    /**
     * The uploads, whoever waited longest first, so that a slow service
     * does not push the same others past the budget every tick.
     *
     * @return array<string, UploadConfig>
     */
    private function ordered(): array
    {
        $uploads = $this->config->uploads;
        $waited = [];
        foreach (array_keys($uploads) as $id) {
            $waited[$id] = $this->state->upload($id)->lastRunAt ?? 0;
        }
        uksort($uploads, static function (string $a, string $b) use ($waited): int {
            $order = $waited[$a] <=> $waited[$b];
            return $order === 0 ? strcmp($a, $b) : $order;
        });
        return $uploads;
    }
}
