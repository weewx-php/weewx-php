<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreUpdate/Guard.php';
try {
    \WeewxPhp\CoreUpdate\Guard::enter(dirname(__DIR__));
} catch (\Throwable) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Core update in progress. Please retry shortly.\n");
        exit(75);
    }
    http_response_code(503);
    header('Retry-After: 10');

    exit('Core update in progress. Please retry shortly.');
}

/*
 * The autoloader for an installation without Composer. On a web host the
 * application arrives as copied files, and this is the only autoloader that
 * is certain to be there. In development Composer's own autoloader is
 * registered first by the test bootstrap; this one then never sees a class
 * that Composer already found.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WeewxPhp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
