<?php

declare(strict_types=1);

// Development only: php -S 0.0.0.0:8080 -t public bin/ingest-router.php
// Explicit routes keep the built-in server from serving anything outside public/.
$path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
if ($path === '/tick.php') {
    require dirname(__DIR__) . '/public/tick.php';
} elseif ($path === '/ingest/weewx.php') {
    $_SERVER['SCRIPT_NAME'] = '/ingest/weewx.php';
    require dirname(__DIR__) . '/public/ingest/weewx.php';
} else {
    $_SERVER['SCRIPT_NAME'] = '/receive.php';
    require dirname(__DIR__) . '/public/receive.php';
}
