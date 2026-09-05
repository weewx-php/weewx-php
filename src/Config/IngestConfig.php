<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

/** HTTP push reception. Native credentials and admission live with their journal. */
final class IngestConfig
{
    /** @param list<string> $trustedProxies Exact proxy IPs; their forwarding headers must be overwritten by the proxy. */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $publicUrl = '',
        public readonly bool $httpEcowitt = true,
        public readonly bool $httpWunderground = true,
        public readonly array $trustedProxies = [],
        public readonly int $requestsPerMinute = 300,
        public readonly int $maxPending = 100,
        public readonly string $metricWind = 'kph',
        public readonly int $senderRequestsPerMinute = 120,
        public readonly string $tickMode = 'auto',
        public readonly int $maxNativeReceipts = 2000000,
    ) {}
}
