<?php

declare(strict_types=1);

/*
 * The helpers in this directory are run by tests/conformance/run.py through
 * the PHP CLI and print what the Python side compares. They load the
 * application the way a web host does: through its own autoloader, without
 * Composer.
 */
require __DIR__ . '/../../../src/autoload.php';
