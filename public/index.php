<?php

declare(strict_types=1);

use WeewxPhp\DemoTheme\View;
use WeewxPhp\Frontend\Theme;
use WeewxPhp\Frontend\Weather;

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$wx = null;
$view = null;
try {
    require_once dirname(__DIR__) . '/themes/demo/View.php';
    /** @var Weather $wx */
    $wx = require dirname(__DIR__) . '/frontend.php';
    $configPath = getenv('WEEWX_PHP_CONF');
    $theme = Theme::configured($configPath === false || $configPath === '' ? dirname(__DIR__) . '/weewx-php.conf' : $configPath, 'demo');
    $range = $_GET['range'] ?? $theme->extras['default_range'] ?? '24h';
    $range = $range === '7d' ? '7d' : '24h';
    $view = new View($wx, $range);
} catch (Throwable $error) {
    error_log('Demo theme: ' . $error->getMessage());
    http_response_code(503);
} finally {
    $wx?->close();
}

require dirname(__DIR__) . '/themes/demo/template.php';
