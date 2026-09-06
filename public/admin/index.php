<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/autoload.php';

use WeewxPhp\Admin\Controller;

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '0';
if (is_string($contentLength) && (int) $contentLength > 262144) {
    http_response_code(413);
    exit('Request too large');
}
$config = getenv('WEEWX_PHP_CONF');
$config = $config === false || $config === '' ? dirname(__DIR__, 2) . '/weewx-php.conf' : $config;
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
$response = (new Controller($config, time(), getenv('WEEWX_PHP_ADMIN_HTTP') === '1'))->handle(
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
    \WeewxPhp\Db\Json::object(json_encode($_GET, JSON_THROW_ON_ERROR)),
    \WeewxPhp\Db\Json::object(json_encode($_POST, JSON_THROW_ON_ERROR)),
    is_string($_COOKIE['weewx_admin'] ?? null) ? $_COOKIE['weewx_admin'] : null,
    is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
    $https,
);
if ($response->token !== null) {
    $script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/admin/index.php';
    setcookie('weewx_admin', $response->token, ['expires' => $response->token === '' ? 1 : time() + 7200,
        'path' => rtrim(str_replace('\\', '/', dirname($script)), '/') . '/',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
}
http_response_code($response->status);
header('Content-Type: ' . $response->contentType);
if ($response->location !== null) {
    header('Location: ' . $response->location);
}
if (is_resource($response->download) && $response->filename !== null) {
    header('Content-Disposition: attachment; filename="' . $response->filename . '"');
    $info = fstat($response->download);
    if ($info !== false) {
        header('Content-Length: ' . $info['size']);
    }
    fpassthru($response->download);
    fclose($response->download);
} else {
    echo $response->body;
}
