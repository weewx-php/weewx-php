<?php

// Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

declare(strict_types=1);

namespace WeewxPhp\Tests\Upload;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Tests\Support\FakeConnection;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\FakeSocketFactory;
use WeewxPhp\Time\FixedClock;
use WeewxPhp\Upload\Http\BudgetedHttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Net\BudgetedSocketFactory;
use WeewxPhp\Upload\Rejected;

final class BudgetedTransportsTest extends TestCase
{
    public function testSocketAndMqttKeepTheFractionalBudget(): void
    {
        $clock = new FixedClock(1_000);
        $budget = Budget::of($clock, 1.0, 100);
        $inner = (new FakeSocketFactory())->queue(new FakeConnection("\x20\x02\x00\x00"));
        $sockets = new BudgetedSocketFactory($inner, $budget);
        $clock->advance(0.75);
        $client = new \WeewxPhp\Upload\Mqtt\Client($sockets, 'broker', 1883, 'test', '', '', false, true, 60, 1);
        try {
            $client->connect();
            self::assertTrue($client->connected());
            self::assertSame(0.25, $inner->opened[0]['timeout']);
        } finally {
            $client->close();
        }
    }

    public function testSocketReadsShareOneDeadline(): void
    {
        $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($streams);
        stream_set_timeout($streams[0], 0, 120000);
        $connection = new \WeewxPhp\Upload\Net\StreamConnection($streams[0], 'test', (hrtime(true) / 1_000_000_000) + 0.12);
        $start = (hrtime(true) / 1_000_000_000);
        try {
            self::assertSame('', $connection->readSome(1));
            try {
                $connection->read(1);
                self::fail('The second read must not get a new timeout');
            } catch (Rejected $error) {
                self::assertStringContainsString('connection', $error->getMessage());
            }
            self::assertLessThan(0.2, (hrtime(true) / 1_000_000_000) - $start);
        } finally {
            $connection->close();
            fclose($streams[1]);
        }
    }

    public function testARequestWaitsNoLongerThanTheTickHasLeft(): void
    {
        $clock = new FixedClock(1_000);
        $budget = Budget::of($clock, 12.0, 100);
        $inner = new FakeHttpClient();
        $client = new BudgetedHttpClient($inner, $budget);

        $client->send(new HttpRequest('GET', 'https://a', timeout: 30));
        self::assertSame(12, $inner->last()->timeout);
        $client->send(new HttpRequest('GET', 'https://a', timeout: 5));
        self::assertSame(5, $inner->last()->timeout);

        $clock->advance(10.5);
        $client->send(new HttpRequest('GET', 'https://a', timeout: 30));
        self::assertSame(1, $inner->last()->timeout);

        $clock->advance(1.0);
        $this->expectException(Rejected::class);
        $this->expectExceptionMessage('no time left');
        $client->send(new HttpRequest('GET', 'https://a', timeout: 30));
    }

    public function testAnUnlimitedBudgetLeavesRequestsAlone(): void
    {
        $inner = new FakeHttpClient();
        $client = new BudgetedHttpClient($inner, Budget::unlimited(new FixedClock(1_000)));
        $client->send(new HttpRequest('GET', 'https://a', timeout: 300));
        self::assertSame(300, $inner->last()->timeout);
    }

    public function testASocketOpensWithWhatIsLeft(): void
    {
        $clock = new FixedClock(1_000);
        $budget = Budget::of($clock, 4.0, 100);
        $inner = (new FakeSocketFactory())->queue(new FakeConnection())->queue(new FakeConnection());
        $sockets = new BudgetedSocketFactory($inner, $budget);

        $sockets->open('h', 1, false, true, 20);
        $sockets->open('h', 1, false, true, 2);
        self::assertSame([4.0, 2], array_column($inner->opened, 'timeout'));

        $clock->advance(4.0);
        $this->expectException(Rejected::class);
        $sockets->open('h', 1, false, true, 20);
    }
}
