<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Http;

use WeewxPhp\Upload\Rejected;

/**
 * Where an HTTP request goes. An interface so that a test can hand a
 * service canned answers and read what it asked.
 */
interface HttpClient
{
    /**
     * @throws Rejected When the request could not be made at all: permanent for a name that
     *     does not resolve, which is almost always a typo, and transient for everything else.
     */
    public function send(HttpRequest $request): HttpResponse;
}
