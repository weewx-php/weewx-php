<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Backup\Restore;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;

final class RestoreCommand implements Command
{
    public function name(): string
    {
        return 'restore';
    }

    public function usage(): string
    {
        return 'restore <backup.tar> <new directory>';
    }

    public function summary(): string
    {
        return 'verify and restore an installation into a new directory';
    }

    public function run(Application $app, array $args): int
    {
        if (count($args) !== 2) {
            $app->console()->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $app->console()->line(Restore::run($args[0], $args[1]));
        return 0;
    }
}
