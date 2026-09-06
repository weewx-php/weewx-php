<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend\Api;

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Query;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\UnitPreferences;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Frontend\Weather;

/** A publisher-owned contract, never a query language supplied by HTTP clients. */
final class Feed
{
    /**
     * @param array<string, Query> $queries Prepared archive/astronomy recipes.
     * @param array<string, string> $live Public field name => LOOP observation.
     * @param array<string, string> $labels Plain text; never HTML.
     * @param list<string> $origins Exact browser origins; '*' explicitly publishes to any origin.
     */
    public function __construct(
        private readonly Weather $weather,
        public readonly array $queries = [],
        private readonly array $live = [],
        private readonly array $labels = [],
        public readonly array $origins = [],
        public readonly int $pollSeconds = 15,
        private readonly int $liveMaxAge = 120,
        private readonly string $title = '',
    ) {
        if (count($queries) > 24 || count($live) > 8 || $queries + $live === [] || array_intersect_key($queries, $live) !== []) {
            throw new QueryError('Feed supports 24 queries and 8 distinct live fields');
        }
        foreach (array_keys($queries + $live) as $name) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/D', $name) !== 1) {
                throw new QueryError('Invalid public field name');
            }
        }
        if ($pollSeconds < 15 || $pollSeconds > 86400 || $liveMaxAge < 0 || $liveMaxAge > 86400) {
            throw new QueryError('Invalid feed interval');
        }
        foreach ($origins as $origin) {
            if ($origin !== '*' && preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$~D', $origin) !== 1) {
                throw new QueryError('Use exact HTTP origins without path or trailing slash');
            }
        }
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->queries + $this->live);
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    public function snapshot(string $id, array $fields, ?UnitPreferences $units = null): array
    {
        $data = [];
        $points = 0;
        foreach ($fields as $name) {
            $query = $this->queries[$name] ?? null;
            $result = $query === null
                ? $this->weather->live($this->live[$name], $this->liveMaxAge)
                : $query->prepared()->get();
            if ($units !== null) {
                $format = $result->output ?? new Output();
                $result = $units->output(new Output(
                    $format->language,
                    decimals: $format->decimals,
                    missing: $format->missing,
                    dateFormat: $format->dateFormat,
                    labels: $format->labels,
                ))->apply($result, $result instanceof Report ? '' : $result->observation);
            }
            $row = $result->jsonSerialize();
            $row['type'] = $result instanceof Series ? 'series' : ($result instanceof Report ? 'report' : 'value');
            $row['source'] = $query === null ? 'live' : ($query->spec()->period === 'almanac' ? 'astronomy' : 'archive');
            $row['label'] = $this->labels[$name] ?? $name;
            if ($result instanceof Value) {
                $row['formatted'] = $result->format();
                // LOOP values are read, not materialized; do not change the ETag on each read.
                if ($query === null) {
                    $row['computedAt'] = null;
                }
            }
            if ($result instanceof Series) {
                $points += count($result->points) + count($result->fallback);
            } elseif ($result instanceof Report) {
                $points += count($result->periods->points);
            }
            if ($points > 4096) {
                throw new QueryError('Public feed exceeds 4096 points; split the feed or reduce resolution');
            }
            $data[$name] = $row;
        }
        return ['version' => 1, 'feed' => $id, 'title' => $this->title,
            'timezone' => $this->weather->configuration()->timezone->getName(),
            'pollSeconds' => $this->pollSeconds, 'data' => $data];
    }
}
