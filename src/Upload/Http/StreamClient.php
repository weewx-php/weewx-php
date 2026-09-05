<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

use WeewxPhp\Upload\Rejected;
use WeewxPhp\Version;

/**
 * HTTP through PHP's stream wrapper, for a host without the curl
 * extension. Needs `allow_url_fopen`, which most hosts leave on.
 */
final class StreamClient implements HttpClient
{
    public function send(HttpRequest $request): HttpResponse
    {
        $headers = ['User-Agent: ' . Version::USER_AGENT];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $options = [
            'method' => $request->method,
            'header' => implode("\r\n", $headers),
            'timeout' => $request->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if ($request->body !== null) {
            $options['content'] = $request->body;
        }
        $context = stream_context_create(['http' => $options]);
        $body = @file_get_contents($request->url, false, $context);
        if ($body === false) {
            $error = error_get_last();
            $message = $error === null ? 'no answer' : $error['message'];
            throw new Rejected(
                sprintf('%s: %s', $request->masked(), $message),
                permanent: str_contains($message, 'getaddrinfo') || str_contains($message, 'Name or service not known'),
            );
        }
        $status = 0;
        // Set by the wrapper beside the body; the first line carries the status.
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $found) === 1) {
            $status = (int) $found[1];
        }
        return new HttpResponse($status, $body);
    }
}
