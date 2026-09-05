<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

printf("PHP %s\n", PHP_VERSION);
printf("sqlite3 %s\n", extension_loaded('sqlite3') ? 'yes' : 'no');
if (extension_loaded('sqlite3')) {
    printf("sqlite %s\n", SQLite3::version()['versionString']);
}
