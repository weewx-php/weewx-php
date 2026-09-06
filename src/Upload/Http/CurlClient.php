<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

use WeewxPhp\Upload\Rejected;
use WeewxPhp\Version;

/**
 * HTTP through the curl extension, which nearly every host has and which
 * handles TLS, redirects and timeouts the way a browser would.
 */
final class CurlClient implements HttpClient
{
    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new Rejected('curl could not be initialised');
        }
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $request->method === '' ? 'GET' : $request->method,
            CURLOPT_TIMEOUT => $request->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $request->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => Version::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options);
        $boundedBody = '';
        if ($request->maxResponseBytes !== null) {
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (\CurlHandle $handle, string $chunk) use (&$boundedBody, $request): int {
                if (strlen($boundedBody) + strlen($chunk) > $request->maxResponseBytes) {
                    return 0;
                }
                $boundedBody .= $chunk;
                return strlen($chunk);
            });
        }
        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            $code = curl_errno($handle);
            throw new Rejected(
                sprintf('%s: %s', $request->masked(), $error === '' ? 'no answer' : $error),
                permanent: $code === CURLE_COULDNT_RESOLVE_HOST,
            );
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        return new HttpResponse(is_int($status) ? $status : 0, $request->maxResponseBytes === null && is_string($body) ? $body : $boundedBody);
    }
}
