<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Frontend;

use JsonException;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Frontend\CacheJson;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Spec;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tests\Support\TempDir;

final class CacheTest extends TestCase
{
    public function testPhpPrecisionDoesNotInvalidateConfigurationOrChangeQueryIdentity(): void
    {
        $dir = TempDir::create('cache-precision');
        $archive = Archives::config(database: $dir . '/weather.sdb');
        $cache = new Cache(Archives::settings($dir));
        $previous = ini_get('serialize_precision');
        $spec = new Spec(coverage: 0.7, threshold: 12.3);
        try {
            ini_set('serialize_precision', '-1');
            $revision = $cache->observe($archive);
            $key = $cache->register($archive->id, $spec, 100);
            $cache->storeChunk('completed-hour', $archive->id, new Span(0, 3600), new Value(12.3));
            foreach (['100', '14', '17', '-1'] as $precision) {
                ini_set('serialize_precision', $precision);
                self::assertSame($revision, $cache->observe($archive));
                self::assertSame($key, $cache->register($archive->id, $spec, 100));
                self::assertSame(12.3, $cache->chunk('completed-hour')?->raw);
                self::assertSame($precision, ini_get('serialize_precision'));
            }
            // A real configuration change must still invalidate historical results.
            $changed = Archives::config(database: $archive->database, latitude: 48.4597);
            self::assertSame($revision + 1, $cache->observe($changed));
            self::assertNull($cache->chunk('completed-hour'));
        } finally {
            ini_set('serialize_precision', $previous);
            $cache->close();
            TempDir::remove($dir);
        }
    }

    public function testEncodingFailureRestoresHostPrecision(): void
    {
        $previous = ini_set('serialize_precision', '100');
        try {
            try {
                CacheJson::encode(INF);
                self::fail('Non-finite values must be rejected');
            } catch (JsonException) {
                self::assertSame('100', ini_get('serialize_precision'));
            }
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function testConcurrentArchiveMutationRejectsStaleChunksWithoutErasingUnaffectedHours(): void
    {
        $dir = TempDir::create('cache-generation');
        $archive = Archives::config(database: $dir . '/weather.sdb');
        $reader = new Cache(Archives::settings($dir));
        $writer = new Cache(Archives::settings($dir));
        try {
            $reader->guardWrites($archive->id, $reader->observe($archive));
            $reader->storeChunk('old-hour', $archive->id, new Span(0, 3600), new Value(12.3));
            $writer->beginMutation($archive, new Span(3600, 7200));
            $reader->storeChunk('during-write', $archive->id, new Span(3600, 7200), new Value(99.0));
            self::assertNull($reader->chunk('during-write'));
            $writer->endMutation($archive, new Span(3600, 7200));
            $reader->storeChunk('stale-after-commit', $archive->id, new Span(3600, 7200), new Value(99.0));
            self::assertNull($reader->chunk('stale-after-commit'));
            self::assertSame(12.3, $reader->chunk('old-hour')?->raw);
            $reader->guardWrites($archive->id, $reader->revision($archive->id));
            $reader->storeChunk('fresh', $archive->id, new Span(3600, 7200), new Value(13.0));
            self::assertSame(13.0, $reader->chunk('fresh')?->raw);
        } finally {
            $reader->close();
            $writer->close();
            TempDir::remove($dir);
        }
    }
}
