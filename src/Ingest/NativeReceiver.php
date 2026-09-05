<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use Throwable;
use WeewxPhp\Tick\Runtime;

/** Bounded discovery and token-bound JSON batches; adoption gates journal writes. */
final class NativeReceiver
{
    private bool $wrote = false;

    public function __construct(private readonly Runtime $runtime) {}

    public function wrote(): bool
    {
        return $this->wrote;
    }

    /** @param callable(): string $readBody Called only after transport, method and token-format checks. */
    public function handle(string $method, string $query, callable $readBody, string $peer, bool $https, string $contentType, string $authorization): Response
    {
        $this->wrote = false;
        $config = $this->runtime->config;
        if (!$config->ingest->enabled) {
            return self::error('disabled', 404);
        }
        try {
            if (!$https) {
                throw new Rejected('https_required', 403);
            }
            if ($method !== 'POST') {
                throw new Rejected('method_not_allowed', 405);
            }
            if ($query !== '') {
                throw new Rejected('query_not_allowed');
            }
            $now = $this->runtime->clock->now();
            if (!$this->runtime->ingest()->permit($peer, $now, $config->ingest)) {
                throw new Rejected('rate_limited', 429);
            }
            if (preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $match) !== 1) {
                throw new Rejected('unauthorized', 401);
            }
            $token = $match[1];
            $store = $this->runtime->live()->collector();
            $known = $store->knownCollector($token);
            if ($known !== null && !$store->permit($known, $now, $config->ingest->senderRequestsPerMinute)) {
                throw new Rejected('rate_limited', 429);
            }
            if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
                throw new Rejected('unsupported_content_type', 415);
            }
            if (NativeParser::maxAge($config->settings) < 1) {
                throw new Rejected('retention_too_short', 503);
            }
            $batch = NativeParser::parse($readBody());
            if ($known !== null && $known !== $batch['collector']) {
                throw new Rejected('collector_mismatch', 403);
            }
            $results = $store->receive($batch['collector'], $token, $batch['events'], $config, $now);
            foreach ($results as $result) {
                $this->wrote = $this->wrote || $result['status'] === 'stored';
            }
            return self::json([
                'version' => $batch['version'], 'status' => 'ok', 'results' => $results,
                'limits' => [
                    'max_bytes' => NativeParser::MAX_BYTES, 'max_packets' => NativeParser::MAX_PACKETS,
                    'max_fields' => NativeParser::MAX_FIELDS, 'max_age_seconds' => NativeParser::maxAge($config->settings),
                    'future_skew_seconds' => NativeParser::FUTURE_SKEW,
                    'receipt_retention_seconds' => NativeParser::RECEIPT_RETENTION,
                    'max_receipts' => $config->ingest->maxNativeReceipts,
                    'versions' => [1, 2, 3],
                    'kinds' => ['loop', 'archive'],
                ],
            ]);
        } catch (Rejected $error) {
            if ($error->status !== 429) {
                $this->runtime->log->warning('native ingest: ' . $error->getMessage());
            }
            return self::error($error->getMessage(), $error->status);
        } catch (Throwable $error) {
            $this->runtime->log->error('native ingest: storage or processing failed (' . $error::class . ')');
            return self::error('unavailable', 503);
        }
    }

    public static function error(string $reason, int $status): Response
    {
        return self::json(['version' => 1, 'status' => 'error', 'error' => $reason], $status);
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value, int $status = 200): Response
    {
        return new Response($status, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'application/json');
    }
}
