<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Db\Sqlite;

/** Provision native senders locally. Secrets are displayed only on creation/rotation. */
final class CollectorCommand implements Command
{
    public function name(): string
    {
        return 'collector';
    }

    public function usage(): string
    {
        return 'collector add <name> | list | stations <collector> | adopt <collector> <station> [<name>] | block <collector> <station> | rotate|disable|enable <collector>';
    }

    public function summary(): string
    {
        return 'manage native WeeWX collectors and station admission';
    }

    public function run(Application $app, array $args): int
    {
        $action = array_shift($args);
        $valid = match ($action) {
            'list' => $args === [],
            'add', 'stations', 'rotate', 'disable', 'enable' => count($args) === 1,
            'block' => count($args) === 2,
            'adopt' => count($args) === 2 || count($args) === 3,
            default => false,
        };
        if (!$valid) {
            $app->console()->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $runtime = $app->runtime();
        $store = $runtime->live()->collector();
        $console = $app->console();
        if ($action === 'add') {
            $created = $store->create($args[0], $runtime->clock->now());
            $console->line('collector_id: ' . $created['id']);
            $console->line('token: ' . $created['token']);
            $console->line('URL: ' . $runtime->config->ingest->publicUrl . '/ingest/weewx.php');
            $runtime->log->info('collector: created ' . $created['id'] . ' (cli)');
            return 0;
        }
        if ($action === 'list') {
            foreach ($store->collectors() as $row) {
                $console->line(sprintf('%s  %s  %s', Sqlite::text($row['id']), $row['enabled'] === 1 ? 'enabled' : 'disabled', Sqlite::text($row['name'])));
            }
            return 0;
        }
        if ($action === 'stations') {
            foreach ($store->stations($args[0]) as $row) {
                $console->line(sprintf(
                    '%s  %s  %s  %s  stored=%s duplicates=%s',
                    Sqlite::text($row['station']),
                    Sqlite::text($row['sender']),
                    Sqlite::text($row['state']),
                    Sqlite::text($row['driver_module']),
                    Sqlite::text($row['stored']),
                    Sqlite::text($row['duplicates']),
                ));
            }
            return 0;
        }
        if ($action === 'adopt') {
            $store->adopt($args[0], $args[1], $args[2] ?? null);
        } elseif ($action === 'block') {
            $store->block($args[0], $args[1]);
        } elseif ($action === 'rotate') {
            $console->line('token: ' . $store->rotate($args[0]));
        } else {
            $store->enable($args[0], $action === 'enable');
        }
        $runtime->log->info('collector: ' . $action . ' ' . $args[0] . ' (cli)');
        $console->line($args[0] . ': ' . $action);
        return 0;
    }
}
