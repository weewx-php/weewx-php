<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

/** WeeWX 5.5 core aggregates plus weewx-xaggs 1.0. Names are not SQL expressions. */
final class Catalog
{
    public const CORE = ['avg', 'avg_ge', 'avg_le', 'count', 'diff', 'exists', 'first', 'firsttime',
        'gustdir', 'has_data', 'last', 'lasttime', 'max', 'max_ge', 'max_le', 'maxmin', 'maxmintime',
        'maxsum', 'maxsumtime', 'maxtime', 'meanmax', 'meanmin', 'min', 'min_ge', 'min_le', 'minmax',
        'minmaxtime', 'minsum', 'minsumtime', 'mintime', 'not_null', 'rms', 'sum', 'sum_ge', 'sum_le',
        'tderiv', 'vecavg', 'vecdir'];
    public const XAGGS = ['historical_min', 'historical_min_avg', 'historical_max', 'historical_max_avg',
        'historical_mintime', 'historical_maxtime', 'historical_avg', 'avg_ge', 'avg_gt', 'avg_le', 'avg_lt'];
    public const DAILY = ['avg_ge', 'avg_gt', 'avg_le', 'avg_lt', 'max_ge', 'max_le', 'min_ge', 'min_le',
        'sum_ge', 'sum_le', 'maxmin', 'maxmintime', 'minmax', 'minmaxtime', 'meanmax', 'meanmin',
        'maxsum', 'maxsumtime', 'minsum', 'minsumtime'];

    public static function check(string $aggregate): void
    {
        if (!in_array($aggregate, self::CORE, true) && !in_array($aggregate, self::XAGGS, true) && $aggregate !== 'cumulative' && $aggregate !== 'value' && $aggregate !== 'trend' && $aggregate !== 'weighted_avg') {
            throw new QueryError('Unknown aggregate: ' . $aggregate);
        }
    }

    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $name) !== 1) {
            throw new QueryError('Invalid observation name');
        }
        return '"' . $name . '"';
    }

    public static function group(string $aggregate, ?string $default): ?string
    {
        if (str_ends_with($aggregate, 'time')) {
            return 'group_time';
        }
        if (preg_match('/_(?:ge|gt|le|lt)$/D', $aggregate) === 1 || $aggregate === 'count') {
            return 'group_count';
        }
        return match ($aggregate) {
            'exists', 'has_data', 'not_null' => 'group_boolean',
            'vecdir', 'gustdir' => 'group_direction',
            default => $default,
        };
    }
}
