<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

if (PHP_SAPI !== 'cli' || !in_array(count($argv), [3, 4], true)) {
    fwrite(STDERR, "Usage: php scripts/build_core_release.php OUTPUT TAG [INSTALL_ZIP]\n");
    exit(64);
}
$package = \WeewxPhp\CoreUpdate\Builder::build(dirname(__DIR__), $argv[2]);
if (file_put_contents($argv[1], $package) !== strlen($package)) {
    throw new RuntimeException('Cannot write core release package');
}
echo hash('sha256', $package) . '  ' . basename($argv[1]) . "\n";
if (isset($argv[3])) {
    \WeewxPhp\CoreUpdate\Builder::installation(dirname(__DIR__), $package, $argv[3]);
    echo hash_file('sha256', $argv[3]) . '  ' . basename($argv[3]) . "\n";
}
