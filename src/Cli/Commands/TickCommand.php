<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Tick\Outcome;
use WeewxPhp\Tick\Tick;

/** What cron calls. */
final class TickCommand implements Command
{
    public function name(): string
    {
        return 'tick';
    }

    public function usage(): string
    {
        return 'tick';
    }

    public function summary(): string
    {
        return 'build what is due, judge the stations, prune the journal';
    }

    public function run(Application $app, array $args): int
    {
        $outcome = (new Tick($app->runtime()))->run('cli');
        $app->console()->line(json_encode($outcome->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $outcome->status === Outcome::ERROR ? 1 : 0;
    }
}
