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
