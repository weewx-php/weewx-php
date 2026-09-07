<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Upload\Http\CurlClient;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Http\StreamClient;
use WeewxPhp\Upload\Rejected;

final class HttpResponseLimitTest extends TestCase
{
    public function testStreamResponsesRespectTheLimitAndUnboundedRequestsStillWork(): void
    {
        $this->checkClient(new StreamClient());
    }

    public function testCurlResponsesRespectTheLimitAndUnboundedRequestsStillWork(): void
    {
        if (!CurlClient::available()) {
            self::markTestSkipped('ext-curl is optional');
        }
        $this->checkClient(new CurlClient());
    }

    public function testClientsExposeRedirectHeadersWithoutFollowingThem(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $clients = [new StreamClient()];
        if (CurlClient::available()) {
            $clients[] = new CurlClient();
        }
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            foreach ($clients as $_) {
                $connection = stream_socket_accept($server, 3);
                if ($connection === false) {
                    exit(1);
                }
                stream_set_timeout($connection, 3);
                while (($line = fgets($connection)) !== false && trim($line) !== '') {
                }
                fwrite($connection, "HTTP/1.1 302 Found\r\nLoCaTiOn: https://example.invalid/package\r\nContent-Length: 8\r\nConnection: close\r\n\r\nredirect");
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }
        fclose($server);
        try {
            foreach ($clients as $client) {
                $response = $client->send(new HttpRequest('GET', 'http://' . $address . '/', timeout: 2, maxResponseBytes: 32));
                self::assertSame(302, $response->status);
                self::assertSame('https://example.invalid/package', $response->headers['location'] ?? null);
                self::assertSame('redirect', $response->body);
            }
        } finally {
            pcntl_waitpid($pid, $status);
        }
        self::assertIsInt($status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    private function checkClient(HttpClient $client): void
    {
        $dir = TempDir::create('http-limit');
        try {
            file_put_contents($dir . '/body', str_repeat('x', 32));
            $path = str_replace('\\', '/', $dir . '/body');
            $url = 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path;
            self::assertSame(str_repeat('x', 32), $client->send(new HttpRequest('GET', $url))->body);
            self::assertSame(str_repeat('x', 32), $client->send(new HttpRequest('GET', $url, maxResponseBytes: 32))->body);
            $this->expectException(Rejected::class);
            $client->send(new HttpRequest('GET', $url, maxResponseBytes: 31));
        } finally {
            TempDir::remove($dir);
        }
    }
}
