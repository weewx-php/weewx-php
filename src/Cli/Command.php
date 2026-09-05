<?php

declare(strict_types=1);

namespace WeewxPhp\Cli;

interface Command
{
    /** The word on the command line. */
    public function name(): string;

    /** The arguments after it, as `help` shows them. */
    public function usage(): string;

    /** One line saying what it does. */
    public function summary(): string;

    /**
     * @param list<string> $args Everything after the command's name.
     *
     * @return int The exit status.
     */
    public function run(Application $app, array $args): int;
}
