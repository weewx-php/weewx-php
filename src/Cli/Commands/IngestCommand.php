<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Cli\Console;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Ingest\Protocol;

/** Local administration; reusable Store methods are the boundary for a future UI. */
final class IngestCommand implements Command
{
    public function name(): string
    {
        return 'ingest';
    }

    public function usage(): string
    {
        return 'ingest endpoints | list | status [<sender>] | sample <sender> | adopt <sender> [<name>] | ignore|block|reset <sender> | rotate ecowitt|wunderground|<sender> | prune';
    }

    public function summary(): string
    {
        return 'show push connection details, discover and adopt senders';
    }

    public function run(Application $app, array $args): int
    {
        $action = array_shift($args);
        $valid = match ($action) {
            'endpoints', 'list', 'prune' => count($args) === 0,
            'status' => count($args) <= 1,
            'sample', 'ignore', 'block', 'reset', 'rotate' => count($args) === 1,
            'adopt' => count($args) >= 1 && count($args) <= 2,
            default => false,
        };
        if (!$valid) {
            $app->console()->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $runtime = $app->runtime();
        $console = $app->console();
        $store = $runtime->ingest();
        $native = $runtime->live()->collector();
        if ($action === 'rotate') {
            $protocol = Protocol::tryFrom($args[0]);
            $secret = $protocol === null ? $store->replacePassword($args[0]) : $store->rotateSetup($protocol);
            $runtime->log->info('ingest: rotate ' . $args[0] . ' (cli)');
            $basePath = parse_url($runtime->config->ingest->publicUrl, PHP_URL_PATH);
            $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
            $console->line($protocol === Protocol::Ecowitt ? 'Path: ' . $basePath . '/' . $secret . '/ecowitt/' : 'PASSWORD: ' . $secret);
            return 0;
        }
        if ($action === 'prune') {
            $store->prune($runtime->clock->now());
            $console->line('ingest: maintenance complete');
            return 0;
        }
        if (isset($args[0]) && ($row = $native->sender($args[0])) !== null && in_array($action, ['status', 'sample', 'adopt', 'ignore', 'block', 'reset'], true)) {
            if ($action === 'status') {
                $console->line($args[0] . '  ' . Sqlite::text($row['state']) . '  ' . Sqlite::text($row['name']));
            } elseif ($action === 'sample') {
                $console->line($row['sample'] === null ? 'no sample' : Sqlite::text($row['sample']));
            } else {
                $native->setState($args[0], match ($action) {
                    'adopt' => 'adopted', 'ignore' => 'ignored', 'block' => 'blocked', default => 'pending',
                }, $args[1] ?? null);
                $runtime->log->info('ingest: ' . $action . ' ' . $args[0] . ' (cli)');
                $console->line($args[0] . ': ' . $action);
            }
            return 0;
        }
        if ($action === 'status') {
            $selected = $args[0] ?? null;
            if ($selected !== null && $store->sender($selected) === null) {
                $console->error('unknown sender');
                return 1;
            }
            $zone = $runtime->config->settings->timezone;
            foreach ($store->senders() as $sender) {
                if ($selected !== null && $selected !== $sender->id) {
                    continue;
                }
                $console->line($sender->id . '  ' . $sender->state . '  ' . $sender->name);
                $diagnostics = $store->diagnostics($sender->id);
                if ($diagnostics === null) {
                    $console->line('  no diagnostics yet');
                    continue;
                }
                $console->line(sprintf(
                    '  since=%s received=%d stored=%d duplicates=%d discarded=%d pending=%d',
                    Console::when($diagnostics->since, $zone),
                    $diagnostics->received,
                    $diagnostics->stored,
                    $diagnostics->duplicates,
                    $diagnostics->discarded,
                    $diagnostics->pending,
                ));
                $console->line(sprintf(
                    '  last=%s valid=%s interval=%s clock_offset=%s time=%s reason=%s',
                    Console::when($diagnostics->lastReceived, $zone),
                    Console::when($diagnostics->lastValid, $zone),
                    $diagnostics->lastInterval === null ? '-' : $diagnostics->lastInterval . 's',
                    $diagnostics->clockOffset === null ? '-' : sprintf('%+ds', $diagnostics->clockOffset),
                    $diagnostics->timeSource ?? '-',
                    $diagnostics->timeReason ?? '-',
                ));
                if ($diagnostics->lastError !== null) {
                    $console->line('  error=' . $diagnostics->lastError . ' at=' . Console::when($diagnostics->lastErrorAt, $zone));
                }
            }
            if ($selected === null) {
                foreach ($store->rejections() as $rejection) {
                    $console->line(sprintf(
                        'rejected: %s count=%s last=%s',
                        Sqlite::text($rejection['reason']),
                        Sqlite::text($rejection['count']),
                        Console::when((int) Sqlite::text($rejection['last_seen']), $zone),
                    ));
                }
            }
            return 0;
        }
        if ($action === 'endpoints') {
            if (!$runtime->config->ingest->enabled) {
                $console->error('ingest is disabled; set [Ingest] enabled = true');
                return 1;
            }
            $keys = $store->credentials();
            $base = $runtime->config->ingest->publicUrl;
            $basePath = parse_url($base, PHP_URL_PATH);
            $basePath = is_string($basePath) ? $basePath : '';
            $console->line('Ecowitt');
            $console->line('  URL: ' . $base . '/' . $keys['ecowitt'] . '/ecowitt/');
            $console->line('  Path: ' . $basePath . '/' . $keys['ecowitt'] . '/ecowitt/');
            $console->line('  Without rewrite: ' . $base . '/receive.php/' . $keys['ecowitt'] . '/ecowitt/');
            $console->line('  HTTP: ' . ($runtime->config->ingest->httpEcowitt ? 'allowed' : 'disabled'));
            $console->line('Wunderground');
            $console->line('  URL: ' . $base . '/weatherstation/updateweatherstation.php');
            $console->line('  ID: a name of your choice');
            $console->line('  PASSWORD: ' . $keys['wunderground']);
            $console->line('  HTTP: ' . ($runtime->config->ingest->httpWunderground ? 'allowed' : 'disabled'));
            $console->line('WeeWX JSON');
            $console->line('  URL: ' . $base . '/ingest/weewx.php');
            $console->line('  HTTPS required; configure the collector, then adopt the discovered station');
            return 0;
        }
        if ($action === 'list') {
            foreach ($native->senders() as $row) {
                $console->line(Sqlite::text($row['sender']) . '  ' . Sqlite::text($row['state']) . '  weewx  ' . Sqlite::text($row['name']));
            }
            foreach ($store->senders() as $sender) {
                $console->line(sprintf(
                    '%s  %s  %s  %s  received=%d stored=%d last=%s',
                    $sender->id,
                    $sender->state,
                    $sender->protocol->value,
                    $sender->name,
                    $sender->received,
                    $sender->stored,
                    Console::when($sender->lastSeen, $runtime->config->settings->timezone),
                ));
                $console->line(sprintf('  identity=%s model=%s peer=%s transport=%s', $sender->identity, $sender->model, $sender->peer, $sender->transport));
            }
            return 0;
        }
        $id = $args[0];
        $sender = $store->sender($id);
        if ($sender === null) {
            $console->error('unknown sender');
            return 1;
        }
        if ($action === 'sample') {
            $console->line($sender->sample ?? 'no sample');
            return 0;
        }
        if ($action === 'adopt') {
            $store->adopt($id, $args[1] ?? null, $runtime->clock->now());
        } else {
            $store->setState($id, match ($action) {
                'ignore' => 'ignored',
                'block' => 'blocked',
                default => 'pending',
            });
        }
        $runtime->log->info(sprintf('ingest: %s %s (cli)', $action ?? '', $id));
        $console->line($id . ': ' . ($action ?? ''));
        return 0;
    }
}
