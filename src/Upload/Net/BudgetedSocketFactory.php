<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Net;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Upload\Rejected;

/** Sockets that keep to a tick's budget, the way {@see \WeewxPhp\Upload\Http\BudgetedHttpClient} keeps requests to it. */
final class BudgetedSocketFactory implements SocketFactory
{
    public function __construct(
        private readonly SocketFactory $inner,
        private readonly Budget $budget,
    ) {}

    public function open(string $host, int $port, bool $tls, bool $verify, int $timeout): Connection
    {
        $left = $this->budget->timeLeft();
        if (is_finite($left)) {
            if ($left < 1.0) {
                throw new Rejected('no time left in this tick');
            }
            $timeout = min($timeout, (int) floor($left));
        }
        return $this->inner->open($host, $port, $tls, $verify, $timeout);
    }
}
