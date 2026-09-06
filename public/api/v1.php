<?php

declare(strict_types=1);

use WeewxPhp\Frontend\Api\Endpoint;
use WeewxPhp\Frontend\Api\Feed;
use WeewxPhp\Frontend\Api\Response;
use WeewxPhp\Frontend\Weather;

require_once dirname(__DIR__, 2) . '/src/autoload.php';
$wx = null;
try {
    $configEnv = getenv('WEEWX_PHP_CONF');
    $configPath = $configEnv === false || $configEnv === '' ? dirname(__DIR__, 2) . '/weewx-php.conf' : $configEnv;
    $feedEnv = getenv('WEEWX_PHP_FEEDS');
    $feedPath = $feedEnv === false || $feedEnv === '' ? dirname($configPath) . '/public-feeds.php' : $feedEnv;
    if (($feedEnv === false || $feedEnv === '') && !is_file($feedPath) && is_file($configPath)) {
        $themeFile = \WeewxPhp\Config\ConfFile::read($configPath);
        $themes = \WeewxPhp\Admin\ThemeRegistry::configured($configPath, file: $themeFile);
        $feedPath = $themes->file($themes->active($themeFile), 'feeds.php') ?? $feedPath;
    }
    $feeds = [];
    if (is_file($feedPath)) {
        $wx = Weather::open($configPath)->cacheOnly();
        // Only a local administrator-configured file is executable. No HTTP path is accepted.
        $loaded = require $feedPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('Feed file must return an array');
        }
        foreach ($loaded as $name => $feed) {
            if (!is_string($name) || !$feed instanceof Feed) {
                throw new RuntimeException('Invalid feed definition');
            }
            $feeds[$name] = $feed;
        }
    }
    $headers = [];
    foreach (['origin', 'if-none-match', 'access-control-request-method', 'access-control-request-headers'] as $name) {
        $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        if (is_string($value)) {
            $headers[$name] = $value;
        }
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $query = [];
    foreach ($_GET as $key => $value) {
        $query[(string) $key] = $value;
    }
    (new Endpoint($feeds))->handle(is_string($method) ? $method : '', $query, $headers)->send();
} catch (Throwable $e) {
    error_log('Public API: ' . $e->getMessage());
    (new Response(
        503,
        ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Vary' => 'Origin', 'X-Content-Type-Options' => 'nosniff'],
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' ? '' : '{"version":1,"error":"unavailable"}',
    ))->send();
} finally {
    $wx?->close();
}
