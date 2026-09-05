<?php

declare(strict_types=1);

namespace WeewxPhp\Cli;

use Throwable;
use WeewxPhp\Cli\Commands\AnalyticsCommand;
use WeewxPhp\Cli\Commands\BackupCommand;
use WeewxPhp\Cli\Commands\CatchupCommand;
use WeewxPhp\Cli\Commands\CheckConfigCommand;
use WeewxPhp\Cli\Commands\ColumnsCommand;
use WeewxPhp\Cli\Commands\IngestCommand;
use WeewxPhp\Cli\Commands\MappingSuggestCommand;
use WeewxPhp\Cli\Commands\RebuildCommand;
use WeewxPhp\Cli\Commands\StatusCommand;
use WeewxPhp\Cli\Commands\TickCommand;
use WeewxPhp\Cli\Commands\UploadCommand;
use WeewxPhp\Cli\Commands\VerifyCommand;
use WeewxPhp\Config\ConfigError;
use WeewxPhp\Log\Logger;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\Clock;

/**
 * `weewx-php <command> [arguments]`, with `--config <file>` before the
 * command when the file is not beside the application.
 *
 * Exit status 0 when the command did what it said, 1 when something in
 * the installation stopped it, 2 when the command line itself was wrong.
 */
final class Application
{
    public const USAGE_ERROR = 2;

    private ?Runtime $runtime = null;

    private string $configPath;

    /**
     * @param Clock|null $clock The clock the runtime uses; the system's unless a test says otherwise.
     * @param Logger|null $log The log the runtime uses; the configured file unless given.
     */
    public function __construct(
        private readonly Console $console,
        private readonly ?Clock $clock = null,
        private readonly ?Logger $log = null,
    ) {
        $fromEnvironment = getenv('WEEWX_PHP_CONF');
        $this->configPath = $fromEnvironment === false || $fromEnvironment === ''
            ? dirname(__DIR__, 2) . '/weewx-php.conf'
            : $fromEnvironment;
    }

    /** @param list<string> $argv The arguments after the program's name. */
    public function run(array $argv): int
    {
        $args = $this->takeOptions($argv);
        if ($args === null) {
            return self::USAGE_ERROR;
        }
        $name = array_shift($args);
        if ($name === null || $name === 'help' || $name === '--help' || $name === '-h') {
            $this->help();
            return $name === null ? self::USAGE_ERROR : 0;
        }
        $command = $this->commands()[$name] ?? null;
        if ($command === null) {
            $this->console->error(sprintf('unknown command %s; try help', $name));
            return self::USAGE_ERROR;
        }
        try {
            return $command->run($this, $args);
        } catch (ConfigError $error) {
            $this->console->error('configuration: ' . $error->getMessage());
            return 1;
        } catch (Throwable $error) {
            $this->console->error($error->getMessage());
            return 1;
        } finally {
            $this->runtime?->close();
        }
    }

    public function console(): Console
    {
        return $this->console;
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    /** The runtime, booted on first use from the configuration file. */
    public function runtime(): Runtime
    {
        return $this->runtime ??= Runtime::boot($this->configPath, $this->clock, $this->log);
    }

    /**
     * The command line without the options that belong to the program
     * itself, or null after saying what was wrong with them.
     *
     * @param list<string> $argv
     *
     * @return list<string>|null
     */
    private function takeOptions(array $argv): ?array
    {
        $rest = [];
        for ($i = 0, $n = count($argv); $i < $n; ++$i) {
            $arg = $argv[$i];
            if ($arg === '--config' || $arg === '-c') {
                $path = $argv[$i + 1] ?? null;
                if ($path === null) {
                    $this->console->error('--config needs a file');
                    return null;
                }
                $this->configPath = $path;
                ++$i;
            } elseif (str_starts_with($arg, '--config=')) {
                $this->configPath = substr($arg, strlen('--config='));
            } else {
                $rest[] = $arg;
            }
        }
        return $rest;
    }

    private function help(): void
    {
        $this->console->line('usage: weewx-php [--config <file>] <command> [arguments]');
        $this->console->line();
        $width = 0;
        foreach ($this->commands() as $command) {
            $width = max($width, strlen($command->usage()));
        }
        foreach ($this->commands() as $command) {
            $this->console->line(sprintf('  %s  %s', str_pad($command->usage(), $width), $command->summary()));
        }
        $this->console->line();
        $this->console->line(sprintf('The configuration is read from %s unless --config or WEEWX_PHP_CONF says otherwise.', $this->configPath));
    }

    /** @return array<string, Command> By name, in the order help lists them. */
    private function commands(): array
    {
        $commands = [];
        foreach ([
            new Commands\AdminCommand(),
            new TickCommand(),
            new AnalyticsCommand(),
            new StatusCommand(),
            new CheckConfigCommand(),
            new MappingSuggestCommand(),
            new CatchupCommand(),
            new RebuildCommand(),
            new ColumnsCommand(),
            new BackupCommand(),
            new VerifyCommand(),
            new UploadCommand(),
            new IngestCommand(),
            new Commands\CollectorCommand(),
        ] as $command) {
            $commands[$command->name()] = $command;
        }
        return $commands;
    }
}
