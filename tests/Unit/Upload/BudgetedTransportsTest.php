<?php

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
        self::assertSame([4, 2], array_column($inner->opened, 'timeout'));

        $clock->advance(4.0);
        $this->expectException(Rejected::class);
        $sockets->open('h', 1, false, true, 20);
    }
}
