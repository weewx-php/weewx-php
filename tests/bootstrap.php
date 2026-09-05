<?php

declare(strict_types=1);

/*
 * In the test image the dev tools are installed beside the mounted
 * repository (/opt/build), not inside it; a checkout with a local `composer
 * install` has them in the usual place. Either way the application's own
 * autoloader is registered as well, so a class Composer does not know about
 * is still found.
 */
foreach (['/opt/build/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        break;
    }
}

require __DIR__ . '/../src/autoload.php';
