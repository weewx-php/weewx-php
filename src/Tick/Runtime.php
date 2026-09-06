<?php

declare(strict_types=1);

namespace WeewxPhp\Tick;

use RuntimeException;
use WeewxPhp\Archive\Changes;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Frontend\ArchiveChanges;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Ingest\Store;
use WeewxPhp\Live\LiveDb;
use WeewxPhp\Log\FileLogger;
use WeewxPhp\Log\Logger;
use WeewxPhp\State\StateDb;
use WeewxPhp\Time\Clock;
use WeewxPhp\Time\SystemClock;
use WeewxPhp\Upload\Http\Http;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Net\StreamSocketFactory;
use WeewxPhp\Upload\Uploads;

/**
 * Everything a run stands on: the configuration, a clock, a log, and the
 * two databases of the application's own, opened when first asked for.
 * The tick and every command line start from one of these.
 */
final class Runtime
{
    private ?LiveDb $live = null;
    private int $ingestWait = 5000;

    private ?Store $ingest = null;

    private ?StateDb $state = null;

    private ?Cache $analytics = null;

    private ?HttpClient $http = null;

    private ?SocketFactory $sockets = null;

    /**
     * @param HttpClient|null $http Where uploads make their requests; the host's own unless a test says.
     * @param SocketFactory|null $sockets Where uploads open their sockets; the network unless a test says.
     */
    private function __construct(
        public Config $config,
        public readonly Clock $clock,
        public readonly Logger $log,
        ?HttpClient $http = null,
        ?SocketFactory $sockets = null,
        private readonly ?string $configPath = null,
    ) {
        $this->http = $http;
        $this->sockets = $sockets;
    }

    /**
     * From a configuration file, with the log the file names.
     *
     * @throws ConfigError If the file does not hold together.
     */
    public static function boot(string $configPath, ?Clock $clock = null, ?Logger $log = null): self
    {
        $config = Config::load($configPath);
        $clock ??= new SystemClock();
        $log ??= new FileLogger($config->settings->logPath(), $config->settings->logLevel, $config->settings->timezone, $clock);
        return new self($config, $clock, $log, configPath: $configPath);
    }

    /** Reload only after the application writer lock has been acquired. */
    public function refresh(): void
    {
        if ($this->configPath === null) {
            return;
        }
        \WeewxPhp\Admin\Changes::recover($this->configPath, $this->config->settings);
        $updated = Config::load($this->configPath);
        if ($updated->settings->dataDir !== $this->config->settings->dataDir) {
            throw new ConfigError('Data directory changed; restart the command');
        }
        $this->config = $updated;
        $revisions = \WeewxPhp\Archive\Revisions::open($updated->settings);
        try {
            $revisions->record(\WeewxPhp\Archive\Revisions::snapshot(\WeewxPhp\Config\ConfFile::read($this->configPath), $updated), $updated, $this->clock->now());
        } finally {
            $revisions->close();
        }
    }

    /** From parts already made: what a test does. */
    public static function of(Config $config, Clock $clock, Logger $log, ?HttpClient $http = null, ?SocketFactory $sockets = null): self
    {
        return new self($config, $clock, $log, $http, $sockets);
    }

    /** Original configuration path, required for full installation backups. */
    public function configPath(): ?string
    {
        return $this->configPath;
    }

    /** The uploads as configured, over the state and the transports of this runtime. */
    public function uploads(): Uploads
    {
        $this->http ??= Http::client();
        $this->sockets ??= new StreamSocketFactory();
        return new Uploads($this->config, $this->state(), $this->log, $this->http, $this->sockets);
    }

    /** @return array<string, array<string, int|string>> */
    public function extensions(\WeewxPhp\Archive\Budget $budget): array
    {
        $this->ensureDataDir();
        $this->http ??= Http::client();
        return (new \WeewxPhp\Extension\Registry($this->config))->run($budget, $this->clock->now(), $this->http, $this->log);
    }

    /** HTTP intake never waits on another SQLite writer; failed persistence is not acknowledged. */
    public function nonBlockingIngest(): void
    {
        $this->ingestWait = 0;
    }

    public function live(): LiveDb
    {
        if ($this->live === null) {
            $this->ensureDataDir();
            $this->live = LiveDb::open($this->config->settings->liveDbPath(), $this->config->settings->journalMode, $this->ingestWait);
        }
        return $this->live;
    }

    public function ingest(): Store
    {
        if ($this->ingest === null) {
            $this->ensureDataDir();
            $this->ingest = Store::open($this->config->settings, $this->ingestWait);
        }
        return $this->ingest;
    }

    public function state(): StateDb
    {
        if ($this->state === null) {
            $this->ensureDataDir();
            $this->state = StateDb::open($this->config->settings->stateDbPath(), $this->config->settings->journalMode);
        }
        return $this->state;
    }

    public function close(): void
    {
        $this->ingest?->close();
        $this->ingest = null;
        $this->analytics?->close();
        $this->analytics = null;
        $this->live?->close();
        $this->state?->close();
        $this->live = null;
        $this->state = null;
    }

    public function archiveChanges(ArchiveConfig $archive): ?Changes
    {
        if (!is_file(Cache::path($this->config->settings))) {
            return null;
        }
        $this->analytics ??= new Cache($this->config->settings);
        return new ArchiveChanges($this->analytics, $archive, $this->log);
    }

    /**
     * The data directory, made on first use and told to refuse the web: a
     * host that serves the directory the databases live in would hand the
     * whole archive to anyone who asks. Keeping it outside the web root is
     * better still, and the README says so.
     */
    public function ensureDataDir(): void
    {
        $dir = $this->config->settings->dataDir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create data directory %s', $dir));
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }
}
