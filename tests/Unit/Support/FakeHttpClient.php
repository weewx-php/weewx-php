<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Support;

use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Http\HttpResponse;
use WeewxPhp\Upload\Rejected;

/** Answers with what a test queued, and keeps every request it was asked to make. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|Rejected> */
    private array $answers = [];

    public function answer(int $status, string $body = ''): self
    {
        $this->answers[] = new HttpResponse($status, $body);
        return $this;
    }

    public function fail(string $message, bool $permanent = false): self
    {
        $this->answers[] = new Rejected($message, $permanent);
        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $answer = array_shift($this->answers) ?? new HttpResponse(200, 'success');
        if ($answer instanceof Rejected) {
            throw $answer;
        }
        return $answer;
    }

    public function last(): HttpRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('nothing was requested');
        }
        return $last;
    }
}
