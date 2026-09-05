<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Upload\Rejected;

/**
 * An HTTP client that keeps to a tick's budget: a request may wait no
 * longer than the tick has left, whatever timeout it asked for. So an
 * upload with a generous timeout still runs late in a tick, on a short
 * leash, rather than never; and once nothing is left, nothing starts.
 */
final class BudgetedHttpClient implements HttpClient
{
    public function __construct(
        private readonly HttpClient $inner,
        private readonly Budget $budget,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $left = $this->budget->timeLeft();
        if (!is_finite($left)) {
            return $this->inner->send($request);
        }
        if ($left < 1.0) {
            throw new Rejected('no time left in this tick');
        }
        $seconds = (int) floor($left);
        return $this->inner->send($request->timeout <= $seconds ? $request : $request->withTimeout($seconds));
    }
}
