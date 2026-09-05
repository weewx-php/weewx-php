<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use Throwable;
use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Mapping;
use WeewxPhp\Archive\MappingError;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\ConfigError;

/**
 * Reads the configuration the way a tick reads it and says what a tick
 * would say, before anything runs: errors, warnings, and for every archive
 * whose database exists already, whether the mapping may write it.
 */
final class CheckConfigCommand implements Command
{
    public function name(): string
    {
        return 'check-config';
    }

    public function usage(): string
    {
        return 'check-config';
    }

    public function summary(): string
    {
        return 'read the configuration and report what is wrong with it';
    }

    public function run(Application $app, array $args): int
    {
        $console = $app->console();
        try {
            $config = Config::load($app->configPath());
        } catch (ConfigError $error) {
            $console->error(sprintf('%s: %s', $app->configPath(), $error->getMessage()));
            return 1;
        }
        $console->line(sprintf('%s: %d station(s), %d archive(s)', $app->configPath(), count($config->stations), count($config->archives)));
        foreach ($config->warnings as $warning) {
            $console->line('warning: ' . $warning);
        }

        $problems = 0;
        foreach ($config->archives as $id => $archive) {
            if (!is_file($archive->database)) {
                $console->line(sprintf('%s: %s does not exist yet; the first tick creates it and %s', $id, $archive->database, self::primaryNote($archive->primary)));
                continue;
            }
            try {
                $db = ArchiveDb::open($archive->database, $config->settings->journalMode, $archive->policy(), $archive->timezone);
            } catch (Throwable $error) {
                $console->line(sprintf('%s: cannot open %s: %s', $id, $archive->database, $error->getMessage()));
                ++$problems;
                continue;
            }
            try {
                $schema = $db->schema();
                $missing = array_diff_key($archive->columns, array_flip($schema->columns));
                if ($missing !== []) {
                    $console->line(sprintf('%s: the first tick adds column(s) %s', $id, implode(', ', array_keys($missing))));
                }
                $foreign = self::foreign($app, $id);
                $mapping = new Mapping($archive, $archive->primary);
                try {
                    // The configured columns are there once a tick has run; the
                    // check should not fail on what that tick will add.
                    $mapping->verify($schema->withColumns(array_keys($archive->columns)), $foreign);
                    $console->line(sprintf('%s: %s, %d record(s), mapping ok', $id, $foreign ? 'a database this application did not create' : 'our database', $db->count()));
                } catch (MappingError $error) {
                    $console->line(sprintf('%s: %s', $id, $error->getMessage()));
                    ++$problems;
                }
            } finally {
                $db->close();
            }
        }
        foreach ($config->uploads as $id => $upload) {
            try {
                $app->runtime()->uploads()->make($upload);
                $console->line(sprintf('upload %s: %s for %s, ok', $id, $upload->kind->label(), $upload->archive));
            } catch (Throwable $error) {
                $console->line(sprintf('upload %s: %s', $id, $error->getMessage()));
                ++$problems;
            }
        }
        return $problems === 0 ? 0 : 1;
    }

    /** Whether the state says the database is not ours; without a state, nothing is. */
    private static function foreign(Application $app, string $id): bool
    {
        $runtime = $app->runtime();
        if (!is_file($runtime->config->settings->stateDbPath())) {
            return true;
        }
        return !$runtime->state()->archive($id)->createdByApp;
    }

    private static function primaryNote(?string $primary): string
    {
        return $primary === null
            ? 'the sender heard first becomes the primary'
            : sprintf('%s is written by name', $primary);
    }
}
