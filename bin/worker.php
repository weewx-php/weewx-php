<?php

declare(strict_types=1);

use WeewxPhp\Tick\Background;
use WeewxPhp\Tick\Dispatcher;
use WeewxPhp\Tick\Runtime;

require dirname(__DIR__) . '/src/autoload.php';

if (PHP_SAPI !== 'cli' || !isset($argv) || count($argv) !== 3 || !in_array($argv[2], Dispatcher::LANES, true)) {
    exit(64);
}
$runtime = null;
try {
    $runtime = Runtime::boot($argv[1]);
    (new Background($runtime))->run($argv[2]);
} catch (Throwable $error) {
    error_log('weewx-php worker failed: ' . $error::class);
    $runtime?->log->error('worker failed: ' . $error->getMessage());
    exit(1);
} finally {
    $runtime?->close();
}
