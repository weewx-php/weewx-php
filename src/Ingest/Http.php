<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use Throwable;
use WeewxPhp\Config\IngestConfig;
use WeewxPhp\Tick\Dispatcher;
use WeewxPhp\Tick\Runtime;

/** Thin PHP/Apache adapter. The web server owns TLS and request read timeouts. */
final class Http
{
    public static function serve(string $projectDir, string $scriptSuffix, bool $native = false): void
    {
        $runtime = null;
        try {
            $path = getenv('WEEWX_PHP_CONF');
            $runtime = Runtime::boot($path === false || $path === '' ? $projectDir . '/weewx-php.conf' : $path);
            $runtime->nonBlockingIngest();
            $server = self::server();
            [$peer, $https] = self::connection($server, $runtime->config->ingest);
            $script = $server['SCRIPT_NAME'] ?? '';
            $base = str_ends_with($script, $scriptSuffix) ? substr($script, 0, -strlen($scriptSuffix)) : '';
            $receiver = $native ? new NativeReceiver($runtime) : new Receiver($runtime, $base);
            $target = explode('?', $server['REQUEST_URI'] ?? '/', 2)[0];
            $readBody = static function () use ($server, $native): string {
                $max = $native ? NativeParser::MAX_BYTES : Parser::MAX_BYTES;
                $length = $server['CONTENT_LENGTH'] ?? '';
                if ($length !== '' && (!ctype_digit($length) || (float) $length > $max)) {
                    throw new Rejected($native ? 'payload_too_large' : 'payload too large', 413);
                }
                if ($native && !in_array(strtolower($server['HTTP_CONTENT_ENCODING'] ?? ''), ['', 'identity'], true)) {
                    throw new Rejected('unsupported_content_encoding', 415);
                }
                $body = file_get_contents('php://input', false, null, 0, $max + 1);
                if ($body === false) {
                    throw new Rejected($native ? 'unreadable_body' : 'cannot read body');
                }
                return $body;
            };
            $response = $receiver instanceof NativeReceiver ? $receiver->handle(
                $server['REQUEST_METHOD'] ?? '',
                $server['QUERY_STRING'] ?? '',
                $readBody,
                $peer,
                $https,
                $server['CONTENT_TYPE'] ?? '',
                self::nativeAuthorization($server),
            ) : $receiver->handle(
                $server['REQUEST_METHOD'] ?? '',
                $target,
                $server['QUERY_STRING'] ?? '',
                $readBody,
                $peer,
                $https,
                $server['CONTENT_TYPE'] ?? '',
            );
            header('Content-Length: ' . strlen($response->body));
            $response->send();
            if ($receiver->wrote() && $runtime->config->ingest->tickMode === 'auto') {
                // Queue only. No calculations, archive writes or network uploads in this request.
                (new Dispatcher($runtime))->request();
            }
        } catch (Throwable $error) {
            error_log('weewx-php ingest failed (' . $error::class . ')');
            if (!headers_sent()) {
                ($native ? NativeReceiver::error('unavailable', 503) : new Response(503, 'unavailable'))->send();
            }
        } finally {
            $runtime?->close();
        }
    }

    /** @param array<string, string> $server
     * @return array{string, bool}
     */
    public static function connection(array $server, IngestConfig $config): array
    {
        $peer = $server['REMOTE_ADDR'] ?? '';
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
            $peer = 'unknown';
        }
        $https = in_array(strtolower($server['HTTPS'] ?? ''), ['on', '1'], true);
        if (in_array($peer, $config->trustedProxies, true)) {
            // The configured proxy must replace (not append) these two headers.
            // TLS on the proxy-to-PHP hop says nothing about the console's hop.
            $https = false;
            $forwarded = trim($server['HTTP_X_FORWARDED_FOR'] ?? '');
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                $peer = $forwarded;
                $https = strtolower($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            }
        }
        return [$peer, $https];
    }

    /** Some shared hosts strip Authorization before invoking PHP.
     * @param array<string, string> $server
     */
    public static function nativeAuthorization(array $server): string
    {
        $bearer = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = $server['HTTP_X_WEEWX_TOKEN'] ?? '';
        if ($bearer !== '' && $token !== '') {
            return ''; // Ambiguous credentials are never selected by precedence.
        }
        return $token === '' ? $bearer : 'Bearer ' . $token;
    }

    /** @return array<string, string> */
    private static function server(): array
    {
        $server = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $server[$name] = $value;
            }
        }
        return $server;
    }
}
