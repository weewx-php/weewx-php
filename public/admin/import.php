<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/autoload.php';

use WeewxPhp\Admin\ImportController;
use WeewxPhp\Db\Json;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
$config = getenv('WEEWX_PHP_CONF');
$config = $config === false || $config === '' ? dirname(__DIR__, 2) . '/weewx-php.conf' : $config;
$webspace = getenv('WEEWX_PHP_WEBSPACE');
if ($webspace === false || $webspace === '') {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $parent = is_string($documentRoot) && $documentRoot !== '' ? realpath(dirname($documentRoot)) : false;
    $project = realpath(dirname($config));
    // A host account's document-root parent is the search scope, never the server filesystem root.
    $webspace = $parent !== false && dirname($parent) !== $parent && $project !== false
        && str_starts_with(str_replace('\\', '/', $project) . '/', rtrim(str_replace('\\', '/', $parent), '/') . '/') ? $parent : dirname($config);
}
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '0';
$contentLength = is_string($contentLength) && ctype_digit($contentLength) ? (int) $contentLength : -1;
$response = (new ImportController($config, time(), $webspace, getenv('WEEWX_PHP_ADMIN_HTTP') === '1'))->handle(
    is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
    Json::object(json_encode($_GET, JSON_THROW_ON_ERROR)),
    is_string($_COOKIE['weewx_admin'] ?? null) ? $_COOKIE['weewx_admin'] : null,
    is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '',
    is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
    $https,
    $contentLength,
    static function (int $limit): string {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return '';
        }
        try {
            $text = stream_get_contents($stream, $limit);
            return $text === false ? '' : $text;
        } finally {
            fclose($stream);
        }
    },
);
if ($response->token !== null) {
    $script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/admin/import.php';
    setcookie('weewx_admin', $response->token, ['expires' => time() + 7200, 'path' => rtrim(str_replace('\\', '/', dirname($script)), '/') . '/',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
}
http_response_code($response->status);
header('Content-Type: ' . $response->contentType);
echo $response->body;
