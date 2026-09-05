<?php

declare(strict_types=1);

/*
 * The one URL of the application: a call makes the tick turn. Cron can
 * call it, an ingest can call it, a browser can call it; what it answers
 * is a small JSON and none of the configuration.
 *
 *   GET /tick.php?token=...      or the header  X-Tick-Token: ...
 *
 * The token is `tick_token` in weewx-php.conf, compared in constant time.
 * Without one configured, every call is refused. The configuration file
 * is looked for beside the application, or where WEEWX_PHP_CONF points.
 */

use WeewxPhp\Tick\Outcome;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;

require dirname(__DIR__) . '/src/autoload.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$configPath = getenv('WEEWX_PHP_CONF');
if ($configPath === false || $configPath === '') {
    $configPath = dirname(__DIR__) . '/weewx-php.conf';
}

try {
    $runtime = Runtime::boot($configPath);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['status' => Outcome::ERROR, 'error' => 'the configuration could not be loaded; see the server log'], JSON_THROW_ON_ERROR);
    error_log('weewx-php: ' . $error->getMessage());
    exit;
}

$offered = $_GET['token'] ?? ($_SERVER['HTTP_X_TICK_TOKEN'] ?? null);
$expected = $runtime->config->settings->tickToken;
if ($expected === null || !is_string($offered) || !hash_equals($expected, $offered)) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $outcome = (new Tick($runtime))->run('http');
} catch (Throwable $error) {
    $runtime->log->error('tick (http): ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => Outcome::ERROR, 'error' => 'the tick failed; see the log'], JSON_THROW_ON_ERROR);
    exit;
} finally {
    $runtime->close();
}

http_response_code(match ($outcome->status) {
    Outcome::OK => 200,
    Outcome::BUSY => 503,
    default => 500,
});
echo json_encode($outcome->toArray(), JSON_THROW_ON_ERROR);
