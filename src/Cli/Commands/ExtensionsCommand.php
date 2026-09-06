<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Extension\Registry;
use WeewxPhp\Tick\ExecutionProfile;
use WeewxPhp\Tick\Lock;

final class ExtensionsCommand implements Command
{
    public function name(): string
    {
        return 'extensions';
    }
    public function usage(): string
    {
        return 'extensions <tags|run>';
    }
    public function summary(): string
    {
        return 'list extension tags or advance background work';
    }

    public function run(Application $app, array $args): int
    {
        if (count($args) !== 1 || !in_array($args[0], ['tags', 'run'], true)) {
            $app->console()->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $runtime = $app->runtime();
        if ($args[0] === 'tags') {
            $app->console()->line(json_encode((new Registry($runtime->config))->tags(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return 0;
        }
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($runtime->config->settings->lockPath());
        if ($lock === null) {
            throw new \RuntimeException('busy');
        }
        try {
            $runtime->refresh();
        } finally {
            $lock->release();
        }
        $results = $runtime->extensions(Budget::of($runtime->clock, ExecutionProfile::limit($runtime->config->settings->timeBudget, (int) ini_get('max_execution_time')), PHP_INT_MAX));
        $app->console()->line(json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return array_filter($results, static fn(array $one): bool => ($one['status'] ?? '') === 'error') === [] ? 0 : 1;
    }
}
