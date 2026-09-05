<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use Throwable;
use WeewxPhp\Tick\Runtime;

/** Protocol authentication and discovery, independent of PHP globals or an admin UI. */
final class Receiver
{
    private bool $wrote = false;

    public function __construct(private readonly Runtime $runtime, private readonly string $basePath = '') {}

    public function wrote(): bool
    {
        return $this->wrote;
    }

    /** @param callable(): string $readBody Deferred until routing, rate, transport and Ecowitt key checks have passed. */
    public function handle(
        string $method,
        string $path,
        string $query,
        callable $readBody,
        string $peer,
        bool $https,
        string $contentType = '',
    ): Response {
        $this->wrote = false;
        $config = $this->runtime->config->ingest;
        if (!$config->enabled) {
            return new Response(404, 'not found');
        }
        $store = $this->runtime->ingest();
        $now = $this->runtime->clock->now();
        $sender = null;
        try {
            if (!$store->permit($peer, $now, $config)) {
                throw new Rejected('request rate limited', 429);
            }
            [$protocol, $key] = $this->route($path);
            if (!in_array($method, $protocol === Protocol::Ecowitt ? ['POST'] : ['GET', 'POST'], true)) {
                throw new Rejected('method not allowed', 405);
            }
            if ($protocol === Protocol::Ecowitt && !$store->ecowittKeyMatches($key)) {
                throw new Rejected('unauthorised', 403);
            }
            if (strlen($query) > Parser::MAX_BYTES) {
                throw new Rejected('query too large', 413);
            }
            $body = '';
            if ($method === 'POST') {
                $type = strtolower(trim(explode(';', $contentType, 2)[0]));
                if (!in_array($type, ['', 'application/x-www-form-urlencoded', 'text/plain'], true)) {
                    throw new Rejected('unsupported content type', 415);
                }
                $body = $readBody();
            }
            if ($protocol === Protocol::Ecowitt && $query !== '') {
                throw new Rejected('unexpected query');
            }
            // Parse together so duplicate credentials across query and body are rejected.
            $fields = Parser::form($query . ($query !== '' && $body !== '' ? '&' : '') . $body);
            $sender = $store->authenticate($protocol, $fields['PASSKEY'] ?? '', $fields['PASSWORD'] ?? '');
            if (!$store->permitSender($sender, $peer, $now, $config)) {
                throw new Rejected($sender === null ? 'discovery blocked' : 'sender rate limited', 429);
            }
            if (!$https && !($protocol === Protocol::Ecowitt ? $config->httpEcowitt : $config->httpWunderground)) {
                throw new Rejected('HTTPS required', 403);
            }
            $observation = Parser::observation($protocol, $fields, $now, $config->metricWind);
            $store->receive(
                $observation,
                $fields['PASSWORD'] ?? '',
                $peer,
                $https,
                $now,
                $config->maxPending,
                function (Sender $sender) use ($observation, $now): bool {
                    $this->wrote = $this->runtime->live()->add(
                        $observation->packet($sender, $now, $this->runtime->config->sources[$sender->id] ?? []),
                        array_keys($this->runtime->config->archives),
                        $this->runtime->config->settings->archiveInterval,
                        $this->runtime->config->settings->rawRetention > 0,
                        $now,
                        $this->runtime->config->intervals(),
                    );
                    return $this->wrote;
                },
            );
            return $protocol->reply();
        } catch (Rejected $error) {
            if ($error->status < 500 && $error->status !== 429) {
                if ($store->blocked($sender === null ? $peer : 'sender:' . $sender->id, $now)) {
                    $error = new Rejected($sender === null ? 'discovery blocked' : 'sender rate limited', 429);
                } else {
                    $store->failed($peer, $now, $sender?->id);
                }
            }
            $store->rejected($sender?->id, $error->getMessage(), $now);
            if ($error->status !== 429) {
                $this->runtime->log->warning(sprintf('ingest: %s from %s', $error->getMessage(), $peer));
            }
            return new Response($error->status, $error->status === 403 ? 'forbidden' : $error->getMessage());
        } catch (Throwable $error) {
            // No request target or payload in errors: both can hold credentials.
            $this->runtime->log->error('ingest: storage or processing failed (' . $error::class . ')');
            try {
                $store->rejected($sender?->id, 'storage or processing failed', $now);
            } catch (Throwable) {
                // A full/unavailable database cannot persist its own diagnosis.
            }
            return new Response(503, 'unavailable');
        }
    }

    /** @return array{Protocol, string} */
    private function route(string $path): array
    {
        $base = rtrim($this->basePath, '/');
        if (!str_starts_with($path, $base . '/')) {
            throw new Rejected('not found', 404);
        }
        $relative = substr($path, strlen($base));
        if (preg_match('#^/(?:receive\.php/)?([A-Za-z0-9]{12})/ecowitt/?$#D', $relative, $match) === 1) {
            return [Protocol::Ecowitt, $match[1]];
        }
        if (in_array(rtrim($relative, '/'), ['/weatherstation/updateweatherstation.php',
            '/weatherstation/updateweatherstation.asp', '/weatherstation/updateweatherstation'], true)) {
            return [Protocol::Wunderground, ''];
        }
        throw new Rejected('not found', 404);
    }
}
