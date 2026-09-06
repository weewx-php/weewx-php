<?php

declare(strict_types=1);

use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Visit;

require dirname(__DIR__) . '/src/autoload.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
// The custom header blocks cross-origin form submissions. No CORS or configurable work parameters.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_WEATHER_VISIT'] ?? '') !== '1'
    || !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin', 'none'], true)) {
    http_response_code(403);
    exit;
}
$runtime = null;
try {
    $config = getenv('WEEWX_PHP_CONF');
    $runtime = Runtime::boot($config === false || $config === '' ? dirname(__DIR__) . '/weewx-php.conf' : $config);
    (new Visit($runtime))->run();
    http_response_code(204);
} catch (Throwable $error) {
    error_log('weewx-php visitor tick: ' . $error::class);
    http_response_code(503);
} finally {
    $runtime?->close();
}
