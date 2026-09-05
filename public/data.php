<?php

declare(strict_types=1);

use WeewxPhp\DemoTheme\View;
use WeewxPhp\Frontend\Weather;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo '{"error":"method"}';
    return;
}
$wx = null;
try {
    require_once dirname(__DIR__) . '/themes/demo/View.php';
    /** @var Weather $wx */
    $wx = require dirname(__DIR__) . '/frontend.php';
    $wx = $wx->cacheOnly();
    $view = new View($wx, ($_GET['range'] ?? null) === '7d' ? '7d' : '24h');
    echo json_encode($view->snapshot(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $error) {
    error_log('Demo data: ' . $error->getMessage());
    http_response_code(503);
    echo '{"error":"unavailable"}';
} finally {
    $wx?->close();
}
