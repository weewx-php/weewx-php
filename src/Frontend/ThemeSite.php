<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use WeewxPhp\Admin\ThemeRegistry;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Frontend\Api\Response;

/** Public entry points dispatch only to the locally configured theme. */
final class ThemeSite
{
    public const CSP = "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    public static function configPath(): string
    {
        $path = getenv('WEEWX_PHP_CONF');
        return $path === false || $path === '' ? dirname(__DIR__, 2) . '/weewx-php.conf' : $path;
    }

    /** @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $cookies
     */
    public static function respond(string $path, string $method, array $query, bool $snapshot = false, array $cookies = [], bool $secure = false): Response
    {
        $headers = ['Content-Type' => $snapshot ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::CSP, 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'];
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD']);
        }
        $wx = null;
        $theme = null;
        try {
            $file = ConfFile::read($path);
            $registry = ThemeRegistry::configured($path, file: $file);
            $id = $registry->active($file);
            $entry = $registry->file($id, $snapshot ? 'snapshot.php' : 'theme.php');
            if ($entry === null) {
                return new Response(404, $headers, $method === 'HEAD' ? '' : '{"error":"not_found"}');
            }
            $units = UnitPreferences::resolve($query, $cookies, $file->root()->optionalSection('Themes')?->optional('units')?->string() ?? 'metric');
            $theme = Theme::configured($path, $id, units: $units);
            $wx = Weather::open($path)->cacheOnly()->output($theme->output());
            $render = require $entry;
            if (!$render instanceof \Closure) {
                throw new \RuntimeException('Invalid theme entry');
            }
            $result = $render($wx, $theme, $query);
            if (($snapshot && !is_array($result)) || (!$snapshot && !is_string($result))) {
                throw new \RuntimeException('Invalid theme response');
            }
            $body = $snapshot ? json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : $result;
            $selection = UnitPreferences::choice($query['units'] ?? null);
            if (!$snapshot && $method === 'GET' && $selection !== null) {
                $headers['Set-Cookie'] = UnitPreferences::cookie($selection, $secure);
            }
            return new Response(200, $headers, $method === 'HEAD' ? '' : $body);
        } catch (\Throwable $error) {
            error_log('Theme: ' . $error->getMessage());
            $theme ??= new Theme();
            $body = $snapshot ? '{"error":"unavailable"}' : '<!doctype html><html lang="' . htmlspecialchars($theme->language, ENT_QUOTES, 'UTF-8') . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $theme->html('Weather station') . '</title><h1>' . $theme->html('No weather data') . '</h1></html>';
            return new Response(503, $headers, $method === 'HEAD' ? '' : $body);
        } finally {
            $wx?->close();
        }
    }

    /** Static files only; neither package PHP nor metadata can be downloaded. */
    public static function asset(string $path, string $method, string $info): Response
    {
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => self::CSP, 'Cache-Control' => 'no-cache'];
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD']);
        }
        try {
            $parts = explode('/', ltrim($info, '/'), 2);
            if (count($parts) !== 2) {
                return new Response(404, $headers);
            }
            [$id, $relative] = $parts;
            $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
                'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
            $type = $types[pathinfo($relative, PATHINFO_EXTENSION)] ?? null;
            if ($type === null) {
                return new Response(404, $headers);
            }
            $registry = ThemeRegistry::configured($path);
            $registry->definition($id);
            $asset = $registry->file($id, 'assets/' . $relative);
            if ($asset === null) {
                return new Response(404, $headers);
            }
            $body = file_get_contents($asset);
            if ($body === false) {
                return new Response(404, $headers);
            }
            return new Response(200, $headers + ['Content-Type' => $type], $method === 'HEAD' ? '' : $body);
        } catch (\Throwable) {
            return new Response(404, $headers);
        }
    }
}
