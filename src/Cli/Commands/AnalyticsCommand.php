<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Astronomy\Sky;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Frontend\Astronomy;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Frontend\Catalog;
use WeewxPhp\Frontend\Query;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Tick\Lock;

final class AnalyticsCommand implements Command
{
    public function name(): string
    {
        return 'analytics';
    }
    public function usage(): string
    {
        return 'analytics <run|status|catalog|register file|sync theme file|deactivate theme|preflight file|forget id|invalidate archive [start end]>';
    }
    public function summary(): string
    {
        return 'prepare theme data and manage analytics';
    }

    public function run(Application $app, array $args): int
    {
        $action = array_shift($args);
        $valid = match ($action) {
            'run', 'status', 'catalog' => $args === [],
            'register', 'forget', 'deactivate', 'preflight' => count($args) === 1,
            'sync' => count($args) === 2,
            'invalidate' => count($args) === 1 || count($args) === 3,
            default => false,
        };
        if (!$valid) {
            $app->console()->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        if ($action === 'catalog') {
            $app->console()->line(json_encode(['weewx' => Catalog::CORE, 'xaggs' => Catalog::XAGGS, 'series' => ['archive', 'cumulative'], 'astronomy' => Astronomy::fields(), 'bodies' => Sky::bodies()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return 0;
        }
        $runtime = $app->runtime();
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($runtime->config->settings->lockPath());
        if ($lock === null) {
            $app->console()->error('busy');
            return 1;
        }
        $cache = new Cache($runtime->config->settings);
        try {
            if (in_array($action, ['register', 'sync', 'preflight'], true)) {
                $file = realpath($action === 'sync' ? $args[1] : $args[0]);
                if ($file === false || !is_file($file)) {
                    throw new QueryError('Data definition file not found');
                }
                $wx = new Weather($runtime->config, clock: $runtime->clock);
                try {
                    $queries = (static function (string $path, Weather $wx): mixed {
                        return require $path;
                    })($file, $wx);
                    if (!is_array($queries)) {
                        throw new QueryError('Data definition must return an array of queries');
                    }
                    foreach ($queries as $name => $query) {
                        if (!is_string($name) || !$query instanceof Query) {
                            throw new QueryError('Data definition needs named Query objects');
                        }
                        if ($action === 'register') {
                            $app->console()->line($name . ' ' . $query->register());
                        }
                        if ($action === 'preflight') {
                            $spec = $query->spec();
                            $spec->validate();
                            $item = $spec->period === 'almanac' ? ['astronomy' => $spec->observation] : $wx->archive($query->definition()['archive'])->measurement($spec->observation);
                            $app->console()->line(json_encode(['name' => $name, 'recipe' => $spec, 'measurement' => $item], JSON_THROW_ON_ERROR));
                        }
                    }
                    if ($action === 'sync') {
                        $app->console()->line(json_encode($wx->syncTheme($args[0], $queries), JSON_THROW_ON_ERROR));
                    }
                } finally {
                    $wx->close();
                }
            } elseif ($action === 'deactivate') {
                $cache->sync($args[0], [], $runtime->clock->now());
            } elseif ($action === 'invalidate') {
                $archive = $runtime->config->archive($args[0]) ?? throw new QueryError('Unknown archive');
                $span = count($args) === 3 ? new Span(Span::timestamp($args[1], $archive->timezone), Span::timestamp($args[2], $archive->timezone)) : null;
                $cache->invalidate($archive->id, $span);
            } elseif ($action === 'forget') {
                $cache->forget($args[0]);
            } elseif ($action === 'status') {
                $app->console()->line(json_encode($cache->status(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $answer = (new Worker($runtime))->run(Budget::of($runtime->clock, $runtime->config->settings->timeBudget, PHP_INT_MAX));
                $app->console()->line(json_encode($answer, JSON_THROW_ON_ERROR));
                return $answer['failed'] > 0 ? 1 : 0;
            }
            return 0;
        } finally {
            $cache->close();
            $lock->release();
        }
    }
}
