<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Service;

use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Upload\Http\Http;
use WeewxPhp\Upload\Http\HttpClient;
use WeewxPhp\Upload\Http\HttpRequest;
use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Posted;
use WeewxPhp\Upload\Readings;
use WeewxPhp\Upload\Rejected;
use WeewxPhp\Upload\Upload;
use WeewxPhp\Upload\UploadError;
use WeewxPhp\Weewx\UnitSystem;

/**
 * InfluxDB: the archive again, in something built to be asked questions.
 * Every other upload sends readings to somebody else's weather service;
 * this one writes them into a database the operator runs, because a page
 * belongs to one archive and the question that makes somebody install
 * Grafana is "all five places on one axis". The tag is the archive, one
 * `location` per upload.
 *
 * Four traps, each of which answers 400 and looks like a network problem:
 * a field is one type for ever, so every reading goes out as a float,
 * `interval` with them; NaN and infinity are not numbers InfluxDB takes,
 * and one in a batch rejects the batch; a place with spaces in its name
 * has to be escaped, because a space is what separates tags from fields;
 * and units are not in the database, so readings are converted into a
 * system that is set once rather than inherited.
 *
 * A second store is a second truth: a rebuilt span has to reach here too,
 * or Grafana shows one number and the station's own page another. That
 * is what `upload run --since` is for.
 */
final class Influx implements Upload
{
    /** Points per request. Fifteen years is a million and a half; five thousand is a few hundred kilobytes. */
    public const BATCH = 5000;

    /** Columns that describe the record rather than the weather. */
    private const NOT_A_READING = ['dateTime', 'usUnits'];

    private readonly string $host;

    private readonly bool $tls;

    private readonly ?int $port;

    private readonly string $prefix;

    private readonly string $api;

    private readonly string $bucket;

    private readonly string $org;

    private readonly string $token;

    private readonly string $username;

    private readonly string $password;

    private readonly string $measurement;

    private readonly string $location;

    private readonly UnitSystem $system;

    private readonly int $timeout;

    public function __construct(
        UploadConfig $config,
        private readonly HttpClient $http,
        private readonly int $batch = self::BATCH,
    ) {
        $url = rtrim(trim($config->text('url')), '/');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UploadError(sprintf('%s is not an http:// or https:// address', $url));
        }
        $this->host = $host;
        $this->tls = $scheme === 'https';
        $this->port = is_array($parts) && isset($parts['port']) ? $parts['port'] : null;
        $this->prefix = rtrim(is_array($parts) ? ($parts['path'] ?? '') : '', '/');
        $this->api = $config->text('api') === 'v1' ? 'v1' : 'v2';
        $this->bucket = trim($config->text('bucket'));
        $this->org = trim($config->text('org'));
        $this->token = trim($config->text('token'));
        $this->username = trim($config->text('username'));
        $this->password = $config->text('password');
        $measurement = trim($config->text('measurement'));
        $this->measurement = $measurement === '' ? 'weather' : $measurement;
        $this->location = trim($config->text('location'));
        $this->system = UnitSystem::fromName($config->text('unit_system'));
        $this->timeout = $config->timeout;
        if ($this->bucket === '') {
            throw new UploadError('InfluxDB needs a bucket (InfluxDB 2) or database (InfluxDB 1)');
        }
        if ($this->api === 'v2' && $this->token === '') {
            throw new UploadError('the InfluxDB 2 API needs a token');
        }
    }

    public function kind(): Kind
    {
        return Kind::Influx;
    }

    /**
     * One record as one line of line protocol, or null if it holds no
     * reading: an interval in which every reading failed quality control
     * leaves a record with a timestamp and nothing else, and a measurement
     * with no fields is a syntax error at the far end.
     *
     * @param array<string, mixed> $record
     */
    public function line(array $record): ?string
    {
        $readings = new Readings($record);
        if ($readings->timestamp() === 0) {
            return null;
        }
        ksort($record, SORT_STRING);
        $fields = [];
        foreach ($record as $name => $raw) {
            if (in_array($name, self::NOT_A_READING, true) || !$readings->has($name)) {
                continue;
            }
            $value = $readings->in($name, $this->system);
            if ($value === null || !is_finite($value)) {
                continue;
            }
            $fields[] = self::tag($name) . '=' . json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($fields === []) {
            return null;
        }
        $tags = $this->location === '' ? '' : ',location=' . self::tag($this->location);
        return sprintf('%s%s %s %d', self::measurement($this->measurement), $tags, implode(',', $fields), $readings->timestamp());
    }

    /**
     * A batch as text, and how many records went into it.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return array{0: string, 1: int}
     */
    public function body(array $records): array
    {
        $lines = [];
        foreach ($records as $record) {
            $line = $this->line($record);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        return [implode("\n", $lines), count($lines)];
    }

    public function post(array $records): Posted
    {
        $posted = new Posted();
        foreach (array_chunk($records, max(1, $this->batch)) as $batch) {
            [$body, $count] = $this->body($batch);
            if ($count === 0) {
                $posted->skipped += count($batch);
                continue;
            }
            try {
                $this->write($body);
            } catch (Rejected $error) {
                if ($error->permanent) {
                    throw $error;
                }
                $posted->failures[] = [(new Readings($batch[0]))->timestamp(), $error->getMessage()];
                break;
            }
            $posted->sent += $count;
            $posted->skipped += count($batch) - $count;
            $posted->through = (new Readings($batch[count($batch) - 1]))->timestamp();
        }
        return $posted;
    }

    /**
     * A write with an empty body: it goes through authentication and the
     * bucket lookup and then has no points to store. That tests the three
     * things that are wrong when this does not work, the address, the
     * credentials and the bucket, without a made-up reading in somebody's
     * series.
     */
    public function check(): string
    {
        try {
            $this->write('');
        } catch (Rejected $error) {
            return 'refused: ' . $error->getMessage();
        }
        return sprintf('InfluxDB accepted the credentials and the %s %s.', $this->api === 'v1' ? 'database' : 'bucket', $this->bucket);
    }

    public function describe(): array
    {
        return [
            'host' => $this->host,
            'api' => $this->api,
            'bucket' => $this->bucket,
            'measurement' => $this->measurement,
            'location' => $this->location,
            'units' => $this->system->name,
        ];
    }

    /** Where a write goes: the one thing the two APIs differ in. */
    public function writeUrl(): string
    {
        $base = sprintf('%s://%s%s%s', $this->tls ? 'https' : 'http', $this->host, $this->port === null ? '' : ':' . $this->port, $this->prefix);
        if ($this->api === 'v1') {
            $fields = ['db' => $this->bucket, 'precision' => 's'];
            if ($this->username !== '') {
                $fields['u'] = $this->username;
                $fields['p'] = $this->password;
            }
            return $base . '/write?' . Http::query($fields);
        }
        $fields = ['bucket' => $this->bucket, 'precision' => 's'];
        if ($this->org !== '') {
            $fields['org'] = $this->org;
        }
        return $base . '/api/v2/write?' . Http::query($fields);
    }

    /** @throws Rejected */
    private function write(string $body): void
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
        if ($this->token !== '') {
            $headers['Authorization'] = 'Token ' . $this->token;
        }
        $response = $this->http->send(new HttpRequest('POST', $this->writeUrl(), $body, $headers, $this->timeout));
        $status = $response->status;
        if ($status === 200 || $status === 204) {
            return;
        }
        if ($status === 401 || $status === 403) {
            throw new Rejected('InfluxDB refused the credentials: ' . $response->excerpt(160), permanent: true);
        }
        if ($status === 404) {
            throw new Rejected(
                sprintf('InfluxDB has no %s %s: %s', $this->api === 'v1' ? 'database' : 'bucket', $this->bucket, $response->excerpt(160)),
                permanent: true,
            );
        }
        if ($status === 400) {
            // Our line protocol, not their trouble. InfluxDB names the
            // offending line, which is the only way to find a type conflict.
            throw new Rejected('InfluxDB rejected the batch: ' . $response->excerpt(400));
        }
        if ($status === 413) {
            throw new Rejected(sprintf('the batch was too large for InfluxDB (%d bytes); lower catch_up', strlen($body)));
        }
        throw new Rejected(sprintf('InfluxDB answered %d: %s', $status, $response->excerpt(160)));
    }

    /**
     * Line protocol escaping. Commas, equals signs and spaces separate the
     * parts of a line, so each is escaped where it appears inside one. A
     * backslash is left alone, except a trailing one, which would escape
     * the separator after it.
     */
    private static function escape(string $text, string $also): string
    {
        $text = rtrim($text, '\\');
        foreach (str_split(',' . $also) as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }

    /** A tag key or value, or a field key: commas, equals signs and spaces all separate. */
    public static function tag(string $text): string
    {
        return self::escape($text, '= ');
    }

    /** A measurement name: a comma ends it, a space starts the fields. */
    public static function measurement(string $text): string
    {
        return self::escape($text, ' ');
    }
}
